<?php

// Consulta de condición de IVA al Padrón de ARCA (WS_SR_PADRON_A5).
//
// Este helper es un soft-check: si el servicio no está disponible (timeout, CUIT
// no habilitado en el panel de ARCA, entorno de homologación sin datos reales),
// devuelve null y no bloquea la emisión. Solo alerta cuando hay una discrepancia
// comprobada, para que el operador pueda corregir la ficha del cliente antes de
// emitir el comprobante.
//
// Prerequisito: el contribuyente debe haber habilitado el servicio "ws_sr_padron_a5"
// en el Administrador de Relaciones de Clave Fiscal de ARCA para su CUIT.

class AfipPadron {

    // El manual oficial (v1.0) muestra el path como "srpadron" sin guión.
    private const URLS = [
        'homologacion' => 'https://awshomo.afip.gov.ar/sr-padron/webservices/personaServiceA5',
        'produccion'   => 'https://aws.afip.gov.ar/sr-padron/webservices/personaServiceA5',
    ];

    // Devuelve la condición de IVA tal como figura en el padrón de ARCA, o null
    // si no se puede obtener (servicio caído, CUIT inválido, entorno sin datos, etc.).
    //
    // Posibles valores devueltos:
    //   'Responsable Inscripto', 'Monotributo', 'Exento', 'Consumidor Final'
    public static function condicionIva(int $cuit, array $config): ?string {
        if ($cuit <= 0) return null;
        if (empty($config['afip_cert']) || empty($config['afip_key'])) return null;

        $cuitEmisor = (int)preg_replace('/\D/', '', (string)($config['cuit'] ?? ''));
        if (!$cuitEmisor) return null;

        $entorno = ($config['afip_entorno'] ?? 'homologacion') === 'produccion' ? 'produccion' : 'homologacion';

        try {
            $ta = AfipWs::obtenerTA($config, $entorno, 'ws_sr_padron_a5');

            $xml = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:per="http://a5.soap.ws.server.puc.sr/">' .
                '<soapenv:Header/>' .
                '<soapenv:Body>' .
                '<per:getPersona>' .
                '<token>' . htmlspecialchars($ta['token'], ENT_XML1) . '</token>' .
                '<sign>'  . htmlspecialchars($ta['sign'],  ENT_XML1) . '</sign>' .
                "<cuitRepresentada>$cuitEmisor</cuitRepresentada>" .
                "<idPersona>$cuit</idPersona>" .
                '</per:getPersona>' .
                '</soapenv:Body>' .
                '</soapenv:Envelope>';

            $respuesta = AfipWs::post(self::URLS[$entorno], $xml, 'http://a5.soap.ws.server.puc.sr/getPersona');

            $limpio = preg_replace('/xmlns(:\w+)?="[^"]*"/', '', $respuesta);
            $limpio = preg_replace('/<(\/?)(\w+):/', '<$1', $limpio);
            $doc    = simplexml_load_string($limpio);
            if (!$doc) return null;

            $persona = $doc->Body->getPersonaResponse->personaReturn ?? null;
            if ($persona === null) return null;

            return self::parsearCondicion($persona);

        } catch (Throwable $e) {
            error_log(sprintf(
                "[%s] AfipPadron: no se pudo consultar CUIT %d — %s\n",
                date('Y-m-d H:i:s'), $cuit, $e->getMessage()
            ), 3, __DIR__ . '/../logs/error.log');
            return null;
        }
    }

    /**
     * Compara la condición devuelta por el padrón con la guardada en la ficha.
     * Solo verifica la distinción principal que importa para elegir la letra del
     * comprobante: RI (Responsable Inscripto) vs. todo lo demás.
     */
    public static function condicionCoincide(string $padron, string $guardada): bool {
        $esRI = fn(string $c): bool => mb_stripos($c, 'inscripto') !== false;
        return $esRI($padron) === $esRI($guardada);
    }

    // ── Privado ───────────────────────────────────────────────────────

    // Estructura real del XML según manual v1.0:
    //   <datosMonotributo>        → presente si es Monotributista
    //     <categoriaMonotributo>  → categoría activa
    //   <datosRegimenGeneral>     → presente si es Régimen General (RI, Exento, etc.)
    //     <impuesto>              → uno por tributo; idImpuesto=30 → IVA → Responsable Inscripto
    // El nodo "datosImpositivos" NO existe en la respuesta real.
    private static function parsearCondicion(SimpleXMLElement $persona): string {
        // Monotributo: tiene la sección datosMonotributo con categoría activa
        $dm = $persona->datosMonotributo ?? null;
        if ($dm !== null && isset($dm->categoriaMonotributo)) {
            return 'Monotributo';
        }

        // Responsable Inscripto: régimen general con impuesto IVA (idImpuesto=30)
        $drg = $persona->datosRegimenGeneral ?? null;
        if ($drg !== null && isset($drg->impuesto)) {
            foreach ($drg->impuesto as $imp) {
                if ((int)($imp->idImpuesto ?? 0) === 30) {
                    return 'Responsable Inscripto';
                }
            }
            // Tiene régimen general pero sin IVA → Exento de IVA
            return 'Exento';
        }

        return 'Consumidor Final';
    }
}
