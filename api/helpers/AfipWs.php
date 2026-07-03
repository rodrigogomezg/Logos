<?php

// Facturación electrónica AFIP: WSAA (autenticación) + WSFEv1 (CAE).
//
// Implementado con cURL y XML armado a mano en lugar de ext-soap, para que
// la app instalable no dependa de habilitar extensiones en php.ini.
// El certificado y la clave (PEM) viven en la tabla configuracion, cargados
// desde Configuración → AFIP (.p12).

class AfipException extends Exception {}

class AfipWs {

    private const URLS = [
        'homologacion' => [
            'wsaa' => 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms',
            'wsfe' => 'https://wswhomo.afip.gov.ar/wsfev1/service.asmx',
        ],
        'produccion' => [
            'wsaa' => 'https://wsaa.afip.gov.ar/ws/services/LoginCms',
            'wsfe' => 'https://servicios1.afip.gov.ar/wsfev1/service.asmx',
        ],
    ];

    private const TIPO_CMP = [
        'FC A-ELECT' => 1,
        'FC B-ELECT' => 6,
    ];

    // RG 4/2017: alícuotas de IVA → id de AFIP
    private const ALIC_ID = [
        '0'    => 3,
        '2.5'  => 9,
        '5'    => 8,
        '10.5' => 4,
        '21'   => 5,
        '27'   => 6,
    ];

    // ── API pública ───────────────────────────────────────────────────

    /**
     * Solicita CAE para una venta. Devuelve:
     *   ['numero_afip' => int, 'cae' => string, 'cae_vencimiento' => 'Y-m-d']
     * Lanza AfipException con un mensaje apto para mostrar al usuario.
     */
    public static function facturar(array $venta, array $config): array {
        $tipoCmp = self::TIPO_CMP[$venta['tipo_comprobante']] ?? null;
        if ($tipoCmp === null) {
            throw new AfipException("El tipo de comprobante '{$venta['tipo_comprobante']}' no es electrónico.");
        }
        if (empty($config['afip_cert']) || empty($config['afip_key'])) {
            throw new AfipException('No hay certificado AFIP cargado. Subilo en Configuración → AFIP.');
        }
        $cuitEmisor = (int)preg_replace('/\D/', '', (string)$config['cuit']);
        if (!$cuitEmisor) throw new AfipException('El CUIT del negocio no está configurado.');
        $ptoVta = (int)($config['punto_venta'] ?? 0);
        if ($ptoVta < 1) throw new AfipException('El punto de venta no está configurado.');

        $cuitReceptor = (int)preg_replace('/\D/', '', (string)($venta['cliente_cuit'] ?? ''));
        if (!$cuitReceptor) {
            throw new AfipException('La factura electrónica requiere un cliente con CUIT.');
        }

        $entorno = ($config['afip_entorno'] ?? 'homologacion') === 'produccion' ? 'produccion' : 'homologacion';

        // Serializar la numeración: dos cajas facturando a la vez no pueden
        // pedir el mismo número de comprobante.
        $db = DB::get();
        $lock = $db->query("SELECT GET_LOCK('logos_afip_facturar', 20)")->fetchColumn();
        if (!$lock) throw new AfipException('Otra caja está facturando en este momento. Reintentá en unos segundos.');

        try {
            $ta = self::obtenerTA($config, $entorno);

            $ultimo = self::feCompUltimoAutorizado($ta, $cuitEmisor, $ptoVta, $tipoCmp, $entorno);
            $numero = $ultimo + 1;

            $resultado = self::feCaeSolicitar($ta, $cuitEmisor, $ptoVta, $tipoCmp, $numero, $venta, $config, $entorno);
            $resultado['numero_afip'] = $numero;
            return $resultado;
        } finally {
            $db->query("SELECT RELEASE_LOCK('logos_afip_facturar')");
        }
    }

    // ── WSAA: Ticket de Acceso ────────────────────────────────────────

    private static function obtenerTA(array $config, string $entorno): array {
        $db = DB::get();

        $stmt = $db->prepare("SELECT token, sign FROM afip_tokens WHERE servicio = 'wsfe' AND entorno = ? AND expira > DATE_ADD(NOW(), INTERVAL 2 MINUTE)");
        $stmt->execute([$entorno]);
        if ($ta = $stmt->fetch()) {
            return ['token' => $ta['token'], 'sign' => $ta['sign']];
        }

        // Generar TRA y firmarlo como CMS con el certificado del contribuyente
        $ahora = time();
        $tra = '<?xml version="1.0" encoding="UTF-8"?>' .
            '<loginTicketRequest version="1.0">' .
            '<header>' .
            '<uniqueId>' . $ahora . '</uniqueId>' .
            '<generationTime>' . date('c', $ahora - 600) . '</generationTime>' .
            '<expirationTime>' . date('c', $ahora + 600) . '</expirationTime>' .
            '</header>' .
            '<service>wsfe</service>' .
            '</loginTicketRequest>';

        $traFile = tempnam(sys_get_temp_dir(), 'tra');
        $cmsFile = tempnam(sys_get_temp_dir(), 'cms');
        file_put_contents($traFile, $tra);

        $ok = openssl_pkcs7_sign($traFile, $cmsFile, $config['afip_cert'], $config['afip_key'], [], 0);
        if (!$ok) {
            @unlink($traFile); @unlink($cmsFile);
            throw new AfipException('No se pudo firmar el pedido de acceso con el certificado cargado: ' . openssl_error_string());
        }

        // El output es S/MIME: la firma CMS en base64 viene después de la primera línea en blanco
        $smime = file_get_contents($cmsFile);
        @unlink($traFile); @unlink($cmsFile);
        $partes = preg_split("/\r?\n\r?\n/", $smime, 2);
        $cms = trim($partes[1] ?? '');
        if ($cms === '') throw new AfipException('La firma CMS quedó vacía.');

        $xml = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">' .
            '<soapenv:Body><loginCms xmlns="http://wsaa.view.sua.dvadac.desein.afip.gov">' .
            '<in0>' . htmlspecialchars($cms, ENT_XML1) . '</in0>' .
            '</loginCms></soapenv:Body></soapenv:Envelope>';

        $respuesta = self::post(self::URLS[$entorno]['wsaa'], $xml, '');

        if (preg_match('/<faultstring[^>]*>(.*?)<\/faultstring>/s', $respuesta, $m)) {
            $msg = html_entity_decode(trim($m[1]));
            if (stripos($msg, 'alreadyAuthenticated') !== false || stripos($msg, 'TA valido') !== false) {
                throw new AfipException('AFIP dice que ya hay un ticket de acceso vigente que este sistema no tiene guardado. Esperá unos 10 minutos y reintentá.');
            }
            throw new AfipException('AFIP (WSAA) rechazó la autenticación: ' . $msg);
        }

        if (!preg_match('/<loginCmsReturn[^>]*>(.*?)<\/loginCmsReturn>/s', $respuesta, $m)) {
            throw new AfipException('Respuesta inesperada de AFIP (WSAA).');
        }

        $taXml = simplexml_load_string(html_entity_decode($m[1], ENT_QUOTES | ENT_XML1));
        if (!$taXml || empty($taXml->credentials->token)) {
            throw new AfipException('No se pudo interpretar el ticket de acceso de AFIP.');
        }

        $token  = (string)$taXml->credentials->token;
        $sign   = (string)$taXml->credentials->sign;
        $expira = date('Y-m-d H:i:s', strtotime((string)$taXml->header->expirationTime));

        $db->prepare("
            INSERT INTO afip_tokens (servicio, entorno, token, sign, expira)
            VALUES ('wsfe', ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE token = VALUES(token), sign = VALUES(sign), expira = VALUES(expira)
        ")->execute([$entorno, $token, $sign, $expira]);

        return ['token' => $token, 'sign' => $sign];
    }

    // ── WSFE ──────────────────────────────────────────────────────────

    private static function feCompUltimoAutorizado(array $ta, int $cuit, int $ptoVta, int $tipoCmp, string $entorno): int {
        $body =
            '<ar:FECompUltimoAutorizado>' .
            self::authXml($ta, $cuit) .
            "<ar:PtoVta>$ptoVta</ar:PtoVta><ar:CbteTipo>$tipoCmp</ar:CbteTipo>" .
            '</ar:FECompUltimoAutorizado>';

        $resp = self::wsfeCall('FECompUltimoAutorizado', $body, $entorno);
        $r = $resp->FECompUltimoAutorizadoResult ?? null;
        if ($r === null) throw new AfipException('Respuesta inesperada de AFIP (último autorizado).');

        self::tirarSiHayErrores($r, 'consultar el último comprobante');
        return (int)$r->CbteNro;
    }

    private static function feCaeSolicitar(array $ta, int $cuit, int $ptoVta, int $tipoCmp, int $numero, array $venta, array $config, string $entorno): array {
        $total = round((float)$venta['total'], 2);

        // Los precios del sistema son siempre IVA incluido: se discrimina el
        // neto y el IVA del mismo total, igual que en el PDF de Factura A.
        $alicPct = (float)($config['iva_porcentaje'] ?? 21);
        $alicId  = self::ALIC_ID[rtrim(rtrim(number_format($alicPct, 1, '.', ''), '0'), '.')] ?? null;
        if ($alicId === null) {
            throw new AfipException("La alícuota de IVA configurada ($alicPct%) no es una alícuota válida de AFIP.");
        }
        $neto = round($total / (1 + $alicPct / 100), 2);
        $iva  = round($total - $neto, 2);

        $fechaCbte = date('Ymd', strtotime((string)$venta['fecha']));

        $condReceptor = self::condicionIvaReceptorId((string)($venta['cliente_condicion_iva'] ?? ''), $tipoCmp);
        $docNro = (int)preg_replace('/\D/', '', (string)$venta['cliente_cuit']);

        $body =
            '<ar:FECAESolicitar>' .
            self::authXml($ta, $cuit) .
            '<ar:FeCAEReq>' .
            "<ar:FeCabReq><ar:CantReg>1</ar:CantReg><ar:PtoVta>$ptoVta</ar:PtoVta><ar:CbteTipo>$tipoCmp</ar:CbteTipo></ar:FeCabReq>" .
            '<ar:FeDetReq><ar:FECAEDetRequest>' .
            '<ar:Concepto>1</ar:Concepto>' .
            '<ar:DocTipo>80</ar:DocTipo>' .
            "<ar:DocNro>$docNro</ar:DocNro>" .
            "<ar:CbteDesde>$numero</ar:CbteDesde><ar:CbteHasta>$numero</ar:CbteHasta>" .
            "<ar:CbteFch>$fechaCbte</ar:CbteFch>" .
            "<ar:ImpTotal>$total</ar:ImpTotal>" .
            '<ar:ImpTotConc>0</ar:ImpTotConc>' .
            "<ar:ImpNeto>$neto</ar:ImpNeto>" .
            '<ar:ImpOpEx>0</ar:ImpOpEx>' .
            '<ar:ImpTrib>0</ar:ImpTrib>' .
            "<ar:ImpIVA>$iva</ar:ImpIVA>" .
            '<ar:MonId>PES</ar:MonId><ar:MonCotiz>1</ar:MonCotiz>' .
            "<ar:CondicionIVAReceptorId>$condReceptor</ar:CondicionIVAReceptorId>" .
            '<ar:Iva><ar:AlicIva>' .
            "<ar:Id>$alicId</ar:Id><ar:BaseImp>$neto</ar:BaseImp><ar:Importe>$iva</ar:Importe>" .
            '</ar:AlicIva></ar:Iva>' .
            '</ar:FECAEDetRequest></ar:FeDetReq>' .
            '</ar:FeCAEReq>' .
            '</ar:FECAESolicitar>';

        $resp = self::wsfeCall('FECAESolicitar', $body, $entorno);
        $r = $resp->FECAESolicitarResult ?? null;
        if ($r === null) throw new AfipException('Respuesta inesperada de AFIP (solicitud de CAE).');

        self::tirarSiHayErrores($r, 'solicitar el CAE');

        $det = $r->FeDetResp->FECAEDetResponse ?? null;
        if ($det === null) throw new AfipException('AFIP no devolvió el detalle del comprobante.');

        if ((string)$det->Resultado !== 'A') {
            $motivos = [];
            foreach ($det->Observaciones->Obs ?? [] as $obs) {
                $motivos[] = '[' . $obs->Code . '] ' . trim((string)$obs->Msg);
            }
            throw new AfipException('AFIP rechazó la factura: ' . ($motivos ? implode(' · ', $motivos) : 'sin detalle'));
        }

        return [
            'cae'             => (string)$det->CAE,
            'cae_vencimiento' => date('Y-m-d', strtotime((string)$det->CAEFchVto)),
        ];
    }

    // ── Utilitarios ───────────────────────────────────────────────────

    private static function authXml(array $ta, int $cuit): string {
        return '<ar:Auth>' .
            '<ar:Token>' . htmlspecialchars($ta['token'], ENT_XML1) . '</ar:Token>' .
            '<ar:Sign>' . htmlspecialchars($ta['sign'], ENT_XML1) . '</ar:Sign>' .
            "<ar:Cuit>$cuit</ar:Cuit>" .
            '</ar:Auth>';
    }

    /** Mapea la condición de IVA del cliente al id de la RG 5616. */
    private static function condicionIvaReceptorId(string $condicion, int $tipoCmp): int {
        $c = mb_strtolower($condicion);
        if (str_contains($c, 'inscripto'))  return 1;
        if (str_contains($c, 'exento'))     return 4;
        if (str_contains($c, 'monotrib'))   return 6;
        if (str_contains($c, 'final'))      return 5;
        // Sin dato: FC A exige RI; FC B se asume consumidor final
        return $tipoCmp === 1 ? 1 : 5;
    }

    private static function wsfeCall(string $metodo, string $bodyXml, string $entorno): SimpleXMLElement {
        $xml = '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ar="http://ar.gov.afip.dif.FEV1/">' .
            '<soap:Body>' . $bodyXml . '</soap:Body></soap:Envelope>';

        $respuesta = self::post(self::URLS[$entorno]['wsfe'], $xml, 'http://ar.gov.afip.dif.FEV1/' . $metodo);

        if (preg_match('/<faultstring[^>]*>(.*?)<\/faultstring>/s', $respuesta, $m)) {
            throw new AfipException('AFIP (WSFE) devolvió un error: ' . html_entity_decode(trim($m[1])));
        }

        // Sacar los namespaces para navegar el XML sin fricción
        $limpio = preg_replace('/xmlns(:\w+)?="[^"]*"/', '', $respuesta);
        $limpio = preg_replace('/<(\/?)(\w+):/', '<$1', $limpio);
        $doc = simplexml_load_string($limpio);
        if (!$doc) throw new AfipException('No se pudo interpretar la respuesta de AFIP.');

        $body = $doc->Body ?? null;
        if (!$body) throw new AfipException('Respuesta SOAP sin cuerpo.');
        $hijos = $body->children();
        return $hijos[0];
    }

    private static function tirarSiHayErrores(SimpleXMLElement $resultado, string $contexto): void {
        if (isset($resultado->Errors->Err)) {
            $msgs = [];
            foreach ($resultado->Errors->Err as $err) {
                $msgs[] = '[' . $err->Code . '] ' . trim((string)$err->Msg);
            }
            throw new AfipException("AFIP devolvió errores al $contexto: " . implode(' · ', $msgs));
        }
    }

    private static function post(string $url, string $xml, string $soapAction): string {
        $intento = function (bool $verificarSsl) use ($url, $xml, $soapAction) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $xml,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: text/xml; charset=utf-8',
                    'SOAPAction: "' . $soapAction . '"',
                ],
                CURLOPT_SSL_VERIFYPEER => $verificarSsl,
                CURLOPT_SSL_VERIFYHOST => $verificarSsl ? 2 : 0,
            ]);
            $resp  = curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            curl_close($ch);
            return [$resp, $errno, $error];
        };

        [$resp, $errno, $error] = $intento(true);

        // XAMPP suele venir sin curl.cainfo configurado: si falla la verificación
        // del certificado raíz, se reintenta sin verificar y se deja constancia.
        if ($errno === CURLE_SSL_CACERT || $errno === 77 || $errno === 60) {
            error_log(sprintf("[%s] AFIP: verificación SSL no disponible (falta CA bundle en php.ini), reintentando sin verificar\n", date('Y-m-d H:i:s')), 3, __DIR__ . '/../logs/error.log');
            [$resp, $errno, $error] = $intento(false);
        }

        if ($errno !== 0 || $resp === false) {
            throw new AfipException("No se pudo conectar con AFIP: $error. Verificá la conexión a internet.");
        }
        return $resp;
    }
}
