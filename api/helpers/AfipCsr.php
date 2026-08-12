<?php

// Generación de clave privada + CSR para el flujo de autoservicio del
// certificado ARCA (ex AFIP): el cliente no sabe usar OpenSSL, así que Logos
// genera la clave y el CSR (PKCS#10) por él. El cliente solo sube el CSR al
// portal de ARCA y vuelve a subir el certificado firmado que ARCA le entrega.
//
// No hay ningún .p12 en este flujo: ARCA no entrega uno, y el sistema ya
// guardaba cert/key como PEM en la tabla `configuracion` (ver
// ConfiguracionController::subirCertAfip()) — este helper solo llena esas
// mismas dos columnas por otro camino.

class AfipCsrException extends Exception {}

class AfipCsr {

    /**
     * Genera un par clave privada RSA 2048 + CSR (PKCS#10) con el Subject que
     * ARCA espera para certificados de Web Services:
     *   C  = AR
     *   O  = razón social del negocio
     *   CN = alias del certificado (el mismo nombre que el cliente va a usar
     *        en ARCA al hacer "Agregar alias")
     *   serialNumber = "CUIT <11 dígitos>" — convención de ARCA para asociar
     *        el certificado al CUIT del contribuyente.
     *
     * Devuelve ['key' => PEM clave privada, 'csr' => PEM CSR]. No toca la
     * base de datos ni el filesystem persistente.
     */
    public static function generar(string $razonSocial, string $cuit, string $alias): array {
        $cuitLimpio = preg_replace('/\D/', '', $cuit);
        if (strlen($cuitLimpio) !== 11) {
            throw new AfipCsrException('El CUIT debe tener 11 dígitos.');
        }

        $aliasLimpio = self::aAscii($alias) ?: 'LogosPOS';
        $orgLimpia   = self::aAscii($razonSocial) ?: 'Sin razon social';

        // 'config' explícito a propósito: openssl_pkey_new()/openssl_csr_new()
        // dependen de encontrar un openssl.cnf del sistema (vía openssl.cafile
        // en php.ini o la variable de entorno OPENSSL_CONF) para inicializar,
        // y en Windows eso suele no estar configurado — falla con "No such
        // process" tanto en XAMPP de desarrollo como en el PHP portable
        // empaquetado (Electron/Tauri). Se bundlea un .cnf mínimo propio para
        // no depender de que el entorno lo tenga bien configurado.
        $cnf = ['config' => __DIR__ . '/openssl_min.cnf'];

        $pkey = openssl_pkey_new($cnf + [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if (!$pkey) {
            throw new AfipCsrException('No se pudo generar la clave privada: ' . openssl_error_string());
        }

        $csrRes = openssl_csr_new([
            'countryName'      => 'AR',
            'organizationName' => $orgLimpia,
            'commonName'       => $aliasLimpio,
            'serialNumber'     => 'CUIT ' . $cuitLimpio,
        ], $pkey, $cnf + ['digest_alg' => 'sha256']);
        if (!$csrRes) {
            throw new AfipCsrException('No se pudo generar el CSR: ' . openssl_error_string());
        }

        if (!openssl_csr_export($csrRes, $csrPem) || !openssl_pkey_export($pkey, $keyPem, null, $cnf)) {
            throw new AfipCsrException('No se pudo exportar la clave o el CSR generados.');
        }

        return ['key' => $keyPem, 'csr' => $csrPem];
    }

    /**
     * Valida que un certificado firmado (PEM) subido por el cliente matchee
     * con la clave privada pendiente y no esté vencido. Devuelve la fecha de
     * vencimiento (Y-m-d) si es válido, o lanza AfipCsrException con un
     * mensaje pensado para mostrarle directo al cliente.
     */
    public static function validarCertContraClave(string $certPem, string $keyPem): string {
        $certRes = @openssl_x509_read($certPem);
        if (!$certRes) {
            throw new AfipCsrException('El archivo subido no es un certificado válido.');
        }

        if (!openssl_x509_check_private_key($certRes, $keyPem)) {
            throw new AfipCsrException('El certificado no corresponde a la clave generada. ¿Subiste el archivo correcto de ARCA?');
        }

        $parsed = openssl_x509_parse($certRes);
        if (!$parsed) {
            throw new AfipCsrException('No se pudo leer la fecha de vencimiento del certificado.');
        }
        if ($parsed['validTo_time_t'] < time()) {
            throw new AfipCsrException('El certificado ya está vencido (venció el ' . date('d/m/Y', $parsed['validTo_time_t']) . ').');
        }

        return date('Y-m-d', $parsed['validTo_time_t']);
    }

    /** Quita tildes/ñ/caracteres no ASCII — ARCA rechaza CN/O con acentos. */
    private static function aAscii(string $s): string {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
        if ($t === false) $t = $s;
        return trim((string)preg_replace('/[^A-Za-z0-9 .\-]/', '', $t));
    }
}
