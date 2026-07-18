<?php

// Facturación electrónica ARCA (ex AFIP): WSAA (autenticación) + WSFEv1 (comprobantes).
//
// Implementado con cURL y XML armado a mano (sin ext-soap) para no depender de
// extensiones habilitadas en php.ini. El certificado y la clave (PEM) viven en la
// tabla configuracion, subidos como .p12 desde Configuración → AFIP.

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

    // Códigos de tipo de comprobante WSFEv1 (RG 100/98 y modificaciones).
    // FC A=1, NC A=3, FC B=6, NC B=8, FC C=11, NC C=13.
    // FC/NC A: emisor RI → receptor RI.  FC/NC B: emisor RI → receptor CF/Mono/Exento.
    // FC/NC C: emisor Monotributista → cualquier receptor (sin IVA desglosado).
    public const TIPO_CMP = [
        'FC A-ELECT' => 1,
        'NC A-ELECT' => 3,
        'FC B-ELECT' => 6,
        'NC B-ELECT' => 8,
        'FC C-ELECT' => 11,
        'NC C-ELECT' => 13,
    ];

    // RG 4/2017: alícuotas de IVA → id de ARCA
    private const ALIC_ID = [
        '0'    => 3,
        '2.5'  => 9,
        '5'    => 8,
        '10.5' => 4,
        '21'   => 5,
        '27'   => 6,
    ];

    // ── API pública ───────────────────────────────────────────────────

    /** Devuelve el código AFIP de tipo de comprobante, o null si no es electrónico. */
    public static function tipoCmp(string $tipo): ?int {
        return self::TIPO_CMP[$tipo] ?? null;
    }

    /**
     * Solicita CAE para una venta (FC A, FC B, NC A, NC B).
     *
     * $venta debe incluir al menos:
     *   id, fecha, total, tipo_comprobante, cliente_cuit, cliente_condicion_iva,
     *   [numero_afip_pendiente] (para retry seguro),
     *   [cbte_asoc_tipo, cbte_asoc_pto_vta, cbte_asoc_nro] (requerido para NC).
     *
     * Devuelve:
     *   ['numero_afip' => int, 'cae' => string, 'cae_vencimiento' => 'Y-m-d',
     *    'punto_venta' => int, 'response' => array]
     *
     * Retry seguro: si $venta['numero_afip_pendiente'] está seteado (número en vuelo
     * de un intento previo que falló por red), consulta FECompConsultar antes de
     * pedir un número nuevo. Si ARCA ya lo autorizó, devuelve ese CAE. Si no,
     * reintenta con el mismo número para evitar huecos en la numeración.
     */
    public static function facturar(array $venta, array $config): array {
        $tipoCmp = self::TIPO_CMP[$venta['tipo_comprobante']] ?? null;
        if ($tipoCmp === null) {
            throw new AfipException("El tipo '{$venta['tipo_comprobante']}' no es un comprobante electrónico.");
        }
        if (empty($config['afip_cert']) || empty($config['afip_key'])) {
            throw new AfipException('No hay certificado ARCA cargado. Subilo en Configuración → AFIP.');
        }
        $cuitEmisor = (int)preg_replace('/\D/', '', (string)$config['cuit']);
        if (!$cuitEmisor) throw new AfipException('El CUIT del negocio no está configurado.');
        $ptoVta = (int)($config['punto_venta'] ?? 0);
        if ($ptoVta < 1) throw new AfipException('El punto de venta no está configurado.');

        $cuitReceptor = (int)preg_replace('/\D/', '', (string)($venta['cliente_cuit'] ?? ''));
        // FC/NC A exigen CUIT del receptor (siempre Responsable Inscripto).
        // FC/NC B y C pueden ir a consumidor final sin CUIT.
        if (!$cuitReceptor && in_array($tipoCmp, [1, 3], true)) {
            $label = $tipoCmp === 1 ? 'FC A-ELECT' : 'NC A-ELECT';
            throw new AfipException("$label requiere un cliente con CUIT registrado.");
        }

        $entorno = ($config['afip_entorno'] ?? 'homologacion') === 'produccion' ? 'produccion' : 'homologacion';

        // El lock serializa las llamadas: dos cajas no pueden pedir el mismo número.
        $db   = DB::get();
        $lock = $db->query("SELECT GET_LOCK('logos_afip_facturar', 20)")->fetchColumn();
        if (!$lock) throw new AfipException('Otra caja está facturando en este momento. Reintentá en unos segundos.');

        try {
            $ta = self::obtenerTA($config, $entorno);

            // ── Retry: verificar si el número pendiente ya fue autorizado ──
            $numero_forzado = null;
            $pendiente = !empty($venta['numero_afip_pendiente']) ? (int)$venta['numero_afip_pendiente'] : null;

            if ($pendiente !== null) {
                try {
                    $cons = self::feCompConsultar($ta, $cuitEmisor, $ptoVta, $tipoCmp, $pendiente, $entorno);
                    if ($cons !== null && (string)($cons->Resultado ?? '') === 'A') {
                        // ARCA ya lo procesó: tomar el CAE sin emitir de nuevo
                        if (!empty($venta['id'])) {
                            $db->prepare("UPDATE ventas SET numero_afip_pendiente = NULL WHERE id = ?")
                               ->execute([$venta['id']]);
                        }
                        $caeVto = (string)($cons->CAEFchVto ?? '');
                        return [
                            'numero_afip'     => $pendiente,
                            'cae'             => (string)($cons->CodAutorizacion ?? ''),
                            'cae_vencimiento' => $caeVto ? date('Y-m-d', strtotime($caeVto)) : '',
                            'punto_venta'     => $ptoVta,
                            'response'        => ['fuente' => 'FECompConsultar', 'numero' => $pendiente],
                        ];
                    }
                    // Rechazado o no encontrado: reintentar con el mismo número
                    $numero_forzado = $pendiente;
                } catch (Throwable) {
                    // FECompConsultar también falló (red): conservar el pendiente
                    $numero_forzado = $pendiente;
                }
            }

            if ($numero_forzado === null) {
                $ultimo = self::feCompUltimoAutorizado($ta, $cuitEmisor, $ptoVta, $tipoCmp, $entorno);
                $numero_forzado = $ultimo + 1;
            }

            // Persistir el número antes de la llamada a ARCA (si falla la red,
            // el próximo reintento sabe qué número verificar con FECompConsultar).
            if (!empty($venta['id'])) {
                $db->prepare("UPDATE ventas SET numero_afip_pendiente = ? WHERE id = ?")
                   ->execute([$numero_forzado, $venta['id']]);
            }

            $resultado = self::feCaeSolicitar($ta, $cuitEmisor, $ptoVta, $tipoCmp, $numero_forzado, $venta, $config, $entorno);
            $resultado['numero_afip'] = $numero_forzado;
            $resultado['punto_venta'] = $ptoVta;

            if (!empty($venta['id'])) {
                $db->prepare("UPDATE ventas SET numero_afip_pendiente = NULL WHERE id = ?")
                   ->execute([$venta['id']]);
            }

            return $resultado;

        } finally {
            $db->query("SELECT RELEASE_LOCK('logos_afip_facturar')");
        }
    }

    /**
     * Consulta el estado de un comprobante ya emitido (FECompConsultar).
     * Devuelve null si no se puede consultar (servicio caído, comprobante inexistente).
     */
    public static function consultarComprobante(int $tipoCmp, int $ptoVta, int $numero, array $config): ?array {
        if (empty($config['afip_cert']) || empty($config['afip_key'])) return null;
        $cuit = (int)preg_replace('/\D/', '', (string)($config['cuit'] ?? ''));
        if (!$cuit) return null;
        $entorno = ($config['afip_entorno'] ?? 'homologacion') === 'produccion' ? 'produccion' : 'homologacion';

        try {
            $ta  = self::obtenerTA($config, $entorno);
            $det = self::feCompConsultar($ta, $cuit, $ptoVta, $tipoCmp, $numero, $entorno);
            if ($det === null) return null;

            $caeVto = (string)($det->CAEFchVto ?? '');
            return [
                'resultado'       => (string)$det->Resultado,
                'cae'             => (string)($det->CodAutorizacion ?? '') ?: null,
                'cae_vencimiento' => $caeVto ? date('Y-m-d', strtotime($caeVto)) : null,
                'total'           => (float)($det->ImpTotal ?? 0),
                'fecha_cbte'      => (string)($det->CbteFch ?? ''),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    // ── WSAA: Ticket de Acceso ────────────────────────────────────────

    /**
     * Devuelve ['token' => '...', 'sign' => '...'] para el servicio indicado.
     * Cachea el ticket en afip_tokens (~12 h). Público para que AfipPadron
     * pueda obtener tickets del servicio ws_sr_padron_a5 con el mismo cert.
     */
    public static function obtenerTA(array $config, string $entorno, string $servicio = 'wsfe'): array {
        $db = DB::get();

        $stmt = $db->prepare("
            SELECT token, sign FROM afip_tokens
            WHERE servicio = ? AND entorno = ? AND expira > DATE_ADD(NOW(), INTERVAL 2 MINUTE)
        ");
        $stmt->execute([$servicio, $entorno]);
        if ($ta = $stmt->fetch()) {
            return ['token' => $ta['token'], 'sign' => $ta['sign']];
        }

        $ahora = time();
        $tra   = '<?xml version="1.0" encoding="UTF-8"?>' .
            '<loginTicketRequest version="1.0">' .
            '<header>' .
            '<uniqueId>' . $ahora . '</uniqueId>' .
            '<generationTime>' . date('c', $ahora - 600) . '</generationTime>' .
            '<expirationTime>' . date('c', $ahora + 600) . '</expirationTime>' .
            '</header>' .
            "<service>$servicio</service>" .
            '</loginTicketRequest>';

        $traFile = tempnam(sys_get_temp_dir(), 'tra');
        $cmsFile = tempnam(sys_get_temp_dir(), 'cms');
        file_put_contents($traFile, $tra);

        $ok = openssl_pkcs7_sign($traFile, $cmsFile, $config['afip_cert'], $config['afip_key'], [], 0);
        if (!$ok) {
            @unlink($traFile); @unlink($cmsFile);
            throw new AfipException('No se pudo firmar el pedido de acceso: ' . openssl_error_string());
        }

        $smime = file_get_contents($cmsFile);
        @unlink($traFile); @unlink($cmsFile);
        $partes = preg_split("/\r?\n\r?\n/", $smime, 2);
        $cms    = trim($partes[1] ?? '');
        if ($cms === '') throw new AfipException('La firma CMS quedó vacía.');

        $xml = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">' .
            '<soapenv:Body><loginCms xmlns="http://wsaa.view.sua.dvadac.desein.afip.gov">' .
            '<in0>' . htmlspecialchars($cms, ENT_XML1) . '</in0>' .
            '</loginCms></soapenv:Body></soapenv:Envelope>';

        $respuesta = self::post(self::URLS[$entorno]['wsaa'], $xml, '');

        if (preg_match('/<faultstring[^>]*>(.*?)<\/faultstring>/s', $respuesta, $m)) {
            $msg = html_entity_decode(trim($m[1]));
            if (stripos($msg, 'alreadyAuthenticated') !== false || stripos($msg, 'TA valido') !== false) {
                throw new AfipException('ARCA dice que ya hay un ticket de acceso vigente que este sistema no registra. Esperá unos 10 minutos y reintentá.');
            }
            throw new AfipException("ARCA (WSAA) rechazó la autenticación para '$servicio': $msg");
        }

        if (!preg_match('/<loginCmsReturn[^>]*>(.*?)<\/loginCmsReturn>/s', $respuesta, $m)) {
            throw new AfipException('Respuesta inesperada de ARCA (WSAA).');
        }

        $taXml = simplexml_load_string(html_entity_decode($m[1], ENT_QUOTES | ENT_XML1));
        if (!$taXml || empty($taXml->credentials->token)) {
            throw new AfipException('No se pudo interpretar el ticket de acceso de ARCA.');
        }

        $token  = (string)$taXml->credentials->token;
        $sign   = (string)$taXml->credentials->sign;
        $expira = date('Y-m-d H:i:s', strtotime((string)$taXml->header->expirationTime));

        $db->prepare("
            INSERT INTO afip_tokens (servicio, entorno, token, sign, expira)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE token = VALUES(token), sign = VALUES(sign), expira = VALUES(expira)
        ")->execute([$servicio, $entorno, $token, $sign, $expira]);

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
        $r    = $resp->FECompUltimoAutorizadoResult ?? null;
        if ($r === null) throw new AfipException('Respuesta inesperada de ARCA (último autorizado).');

        self::tirarSiHayErrores($r, 'consultar el último comprobante');
        return (int)$r->CbteNro;
    }

    private static function feCompConsultar(array $ta, int $cuit, int $ptoVta, int $tipoCmp, int $numero, string $entorno): ?SimpleXMLElement {
        $body = '<ar:FECompConsultar>' .
            self::authXml($ta, $cuit) .
            '<ar:FeCompConsReq>' .
            "<ar:PtoVta>$ptoVta</ar:PtoVta>" .
            "<ar:CbteTipo>$tipoCmp</ar:CbteTipo>" .
            "<ar:CbteNro>$numero</ar:CbteNro>" .
            '</ar:FeCompConsReq>' .
            '</ar:FECompConsultar>';

        $resp = self::wsfeCall('FECompConsultar', $body, $entorno);
        $r    = $resp->FECompConsultarResult ?? null;
        if ($r === null) return null;
        if (isset($r->Errors->Err)) return null; // no encontrado
        return $r->ResultGet ?? null;
    }

    private static function feCaeSolicitar(
        array $ta, int $cuit, int $ptoVta, int $tipoCmp, int $numero,
        array $venta, array $config, string $entorno
    ): array {
        $total      = round((float)$venta['total'], 2);
        $fechaCbte  = date('Ymd', strtotime((string)$venta['fecha']));

        // FC/NC C (Monotributista): sin IVA desglosado. El total es todo neto.
        $esMonotributo = in_array($tipoCmp, [11, 13], true);
        $alicuotas = [];
        if ($esMonotributo) {
            $neto = $total;
            $iva  = 0.0;
        } else {
            $alicuotas = self::desgloseIva($venta, $config, $total);
            $neto = round(array_sum(array_column($alicuotas, 'neto')), 2);
            $iva  = round(array_sum(array_column($alicuotas, 'iva')), 2);
        }

        $condReceptor = self::condicionIvaReceptorId((string)($venta['cliente_condicion_iva'] ?? ''), $tipoCmp);
        $docNro       = (int)preg_replace('/\D/', '', (string)($venta['cliente_cuit'] ?? ''));
        $docTipo      = $docNro ? 80 : 99;
        if (!$docNro) $docNro = 0;

        // CbtesAsoc: obligatorio para NC (tipos 3, 8, 13)
        $cbtesAsocXml = '';
        $esNc = in_array($tipoCmp, [3, 8, 13], true);
        if ($esNc) {
            if (empty($venta['cbte_asoc_nro'])) {
                throw new AfipException('Las NC requieren el comprobante asociado (cbte_asoc_nro).');
            }
            $cbtesAsocXml =
                '<ar:CbtesAsoc><ar:CbteAsoc>' .
                '<ar:Tipo>'   . (int)$venta['cbte_asoc_tipo']    . '</ar:Tipo>' .
                '<ar:PtoVta>' . (int)$venta['cbte_asoc_pto_vta'] . '</ar:PtoVta>' .
                '<ar:Nro>'    . (int)$venta['cbte_asoc_nro']      . '</ar:Nro>' .
                '<ar:Cuit>'   . $cuit                             . '</ar:Cuit>' .
                '</ar:CbteAsoc></ar:CbtesAsoc>';
        }

        // Bloque <Iva>: solo para FC/NC A y B. Monotributistas (FC/NC C) no informan IVA.
        // Una entrada AlicIva por cada alícuota presente en la venta.
        $ivaXml = '';
        if (!$esMonotributo) {
            $ivaXml = '<ar:Iva>';
            foreach ($alicuotas as $a) {
                $ivaXml .=
                    '<ar:AlicIva>' .
                    '<ar:Id>' . $a['id'] . '</ar:Id>' .
                    '<ar:BaseImp>' . number_format($a['neto'], 2, '.', '') . '</ar:BaseImp>' .
                    '<ar:Importe>' . number_format($a['iva'], 2, '.', '') . '</ar:Importe>' .
                    '</ar:AlicIva>';
            }
            $ivaXml .= '</ar:Iva>';
        }

        $body =
            '<ar:FECAESolicitar>' .
            self::authXml($ta, $cuit) .
            '<ar:FeCAEReq>' .
            "<ar:FeCabReq><ar:CantReg>1</ar:CantReg><ar:PtoVta>$ptoVta</ar:PtoVta><ar:CbteTipo>$tipoCmp</ar:CbteTipo></ar:FeCabReq>" .
            '<ar:FeDetReq><ar:FECAEDetRequest>' .
            '<ar:Concepto>1</ar:Concepto>' .
            "<ar:DocTipo>$docTipo</ar:DocTipo>" .
            "<ar:DocNro>$docNro</ar:DocNro>" .
            "<ar:CbteDesde>$numero</ar:CbteDesde><ar:CbteHasta>$numero</ar:CbteHasta>" .
            "<ar:CbteFch>$fechaCbte</ar:CbteFch>" .
            '<ar:ImpTotal>' . number_format($total, 2, '.', '') . '</ar:ImpTotal>' .
            '<ar:ImpTotConc>0</ar:ImpTotConc>' .
            '<ar:ImpNeto>' . number_format($neto, 2, '.', '') . '</ar:ImpNeto>' .
            '<ar:ImpOpEx>0</ar:ImpOpEx>' .
            '<ar:ImpTrib>0</ar:ImpTrib>' .
            '<ar:ImpIVA>' . number_format($iva, 2, '.', '') . '</ar:ImpIVA>' .
            '<ar:MonId>PES</ar:MonId><ar:MonCotiz>1</ar:MonCotiz>' .
            "<ar:CondicionIVAReceptorId>$condReceptor</ar:CondicionIVAReceptorId>" .
            $cbtesAsocXml .
            $ivaXml .
            '</ar:FECAEDetRequest></ar:FeDetReq>' .
            '</ar:FeCAEReq>' .
            '</ar:FECAESolicitar>';

        $resp = self::wsfeCall('FECAESolicitar', $body, $entorno);
        $r    = $resp->FECAESolicitarResult ?? null;
        if ($r === null) throw new AfipException('Respuesta inesperada de ARCA (solicitud de CAE).');

        self::tirarSiHayErrores($r, 'solicitar el CAE');

        $det = $r->FeDetResp->FECAEDetResponse ?? null;
        if ($det === null) throw new AfipException('ARCA no devolvió el detalle del comprobante.');

        if ((string)$det->Resultado !== 'A') {
            $motivos = [];
            foreach ($det->Observaciones->Obs ?? [] as $obs) {
                $motivos[] = '[' . $obs->Code . '] ' . trim((string)$obs->Msg);
            }
            throw new AfipException('ARCA rechazó el comprobante: ' . ($motivos ? implode(' · ', $motivos) : 'sin detalle'));
        }

        $caeVto = (string)$det->CAEFchVto;
        return [
            'cae'             => (string)$det->CAE,
            'cae_vencimiento' => date('Y-m-d', strtotime($caeVto)),
            'response'        => [
                'resultado'       => (string)$det->Resultado,
                'cae'             => (string)$det->CAE,
                'cae_vencimiento' => $caeVto,
                'tipo_cmp'        => $tipoCmp,
                'pto_vta'         => $ptoVta,
                'numero'          => $numero,
            ],
        ];
    }

    // ── Utilitarios ───────────────────────────────────────────────────

    /**
     * Arma el desglose de alícuotas para el bloque <Iva> a partir de los ítems
     * de la venta ($venta['items_iva']: cantidad, precio_unitario con IVA
     * incluido, iva_porcentaje del producto). Sin ítems (NC emitida por monto),
     * cae a la alícuota única configurada, como antes.
     *
     * Devuelve [['id' => id ARCA, 'neto' => float, 'iva' => float], ...] con la
     * garantía de que sum(neto) + sum(iva) == $total: la diferencia de redondeo
     * entre buckets se absorbe en el neto del bucket de mayor importe.
     */
    private static function desgloseIva(array $venta, array $config, float $total): array {
        $brutos = [];
        $items  = $venta['items_iva'] ?? [];

        if ($items) {
            foreach ($items as $it) {
                $key = self::alicKey((float)$it['iva_porcentaje']);
                if (!isset(self::ALIC_ID[$key])) {
                    throw new AfipException("La alícuota de IVA de un producto de la venta ({$it['iva_porcentaje']}%) no es válida en ARCA.");
                }
                $brutos[$key] = ($brutos[$key] ?? 0.0) + (float)$it['cantidad'] * (float)$it['precio_unitario'];
            }
            // El envío es un servicio gravado a la alícuota general.
            $envio = (float)($venta['envio_precio'] ?? 0);
            if ($envio > 0) {
                $key = self::alicKey(21.0);
                $brutos[$key] = ($brutos[$key] ?? 0.0) + $envio;
            }
        } else {
            $alicPct = (float)($config['iva_porcentaje'] ?? 21);
            $key     = self::alicKey($alicPct);
            if (!isset(self::ALIC_ID[$key])) {
                throw new AfipException("La alícuota de IVA configurada ($alicPct%) no es válida en ARCA.");
            }
            $brutos[$key] = $total;
        }

        $alicuotas = [];
        $suma      = 0.0;
        $keyMayor  = null;
        foreach ($brutos as $key => $bruto) {
            $pct  = (float)$key;
            $neto = round($bruto / (1 + $pct / 100), 2);
            $iva  = round(round($bruto, 2) - $neto, 2);
            $alicuotas[$key] = ['id' => self::ALIC_ID[$key], 'neto' => $neto, 'iva' => $iva];
            $suma += $neto + $iva;
            if ($keyMayor === null || $bruto > $brutos[$keyMayor]) $keyMayor = $key;
        }

        $diff = round($total - $suma, 2);
        if ($diff !== 0.0) {
            $alicuotas[$keyMayor]['neto'] = round($alicuotas[$keyMayor]['neto'] + $diff, 2);
        }

        return array_values($alicuotas);
    }

    /** Normaliza un porcentaje a la clave de ALIC_ID: 21.00 → '21', 10.50 → '10.5'. */
    private static function alicKey(float $pct): string {
        return rtrim(rtrim(number_format($pct, 1, '.', ''), '0'), '.');
    }

    private static function authXml(array $ta, int $cuit): string {
        return '<ar:Auth>' .
            '<ar:Token>' . htmlspecialchars($ta['token'], ENT_XML1) . '</ar:Token>' .
            '<ar:Sign>'  . htmlspecialchars($ta['sign'],  ENT_XML1) . '</ar:Sign>' .
            "<ar:Cuit>$cuit</ar:Cuit>" .
            '</ar:Auth>';
    }

    /** Mapea la condición de IVA del cliente al id de la RG 5616. */
    private static function condicionIvaReceptorId(string $condicion, int $tipoCmp): int {
        $c = mb_strtolower($condicion);
        if (str_contains($c, 'inscripto')) return 1;
        if (str_contains($c, 'exento'))    return 4;
        if (str_contains($c, 'monotrib'))  return 6;
        if (str_contains($c, 'final'))     return 5;
        // Sin dato: FC/NC A asume RI; FC/NC B asume consumidor final
        return in_array($tipoCmp, [1, 3], true) ? 1 : 5;
    }

    private static function wsfeCall(string $metodo, string $bodyXml, string $entorno): SimpleXMLElement {
        $xml = '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ar="http://ar.gov.afip.dif.FEV1/">' .
            '<soap:Body>' . $bodyXml . '</soap:Body></soap:Envelope>';

        $respuesta = self::post(self::URLS[$entorno]['wsfe'], $xml, 'http://ar.gov.afip.dif.FEV1/' . $metodo);

        if (preg_match('/<faultstring[^>]*>(.*?)<\/faultstring>/s', $respuesta, $m)) {
            throw new AfipException('ARCA (WSFE) devolvió un error: ' . html_entity_decode(trim($m[1])));
        }

        $limpio = preg_replace('/xmlns(:\w+)?="[^"]*"/', '', $respuesta);
        $limpio = preg_replace('/<(\/?)(\w+):/', '<$1', $limpio);
        $doc    = simplexml_load_string($limpio);
        if (!$doc) throw new AfipException('No se pudo interpretar la respuesta de ARCA.');

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
            throw new AfipException("ARCA devolvió errores al $contexto: " . implode(' · ', $msgs));
        }
    }

    /**
     * POST HTTP genérico. Público para ser reutilizado por AfipPadron.
     * Reintenta sin verificar SSL si XAMPP no tiene CA bundle configurado.
     */
    public static function post(string $url, string $xml, string $soapAction): string {
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

        if ($errno === CURLE_SSL_CACERT || $errno === 77 || $errno === 60) {
            error_log(sprintf(
                "[%s] ARCA: verificación SSL no disponible (falta CA bundle en php.ini), reintentando sin verificar\n",
                date('Y-m-d H:i:s')
            ), 3, __DIR__ . '/../logs/error.log');
            [$resp, $errno, $error] = $intento(false);
        }

        if ($errno !== 0 || $resp === false) {
            throw new AfipException("No se pudo conectar con ARCA: $error. Verificá la conexión a internet.");
        }
        return $resp;
    }
}
