<?php

class Configuracion {

    private static ?array $cache = null;

    public static function get(): array {
        if (self::$cache === null) {
            $stmt = DB::get()->query("SELECT * FROM configuracion WHERE id = 1");
            self::$cache = $stmt->fetch() ?: self::defaults();
        }
        return self::$cache;
    }

    public static function invalidar(): void {
        self::$cache = null;
    }

    private static function defaults(): array {
        return [
            'id'                   => 1,
            'razon_social'         => '',
            'nombre_fantasia'      => null,
            'cuit'                 => '',
            'condicion_iva'        => 'Responsable Inscripto',
            'domicilio'            => null,
            'iibb'                 => null,
            'telefono'             => null,
            'website'              => null,
            'punto_venta'          => null,
            'iva_porcentaje'       => 21.00,
            'impresora_nombre'     => null,
            'carpeta_comprobantes' => null,
            'carpeta_backups'      => null,
            'afip_cert'            => null,
            'afip_key'             => null,
            'afip_entorno'         => 'homologacion',
            'clave_autorizacion_hash' => null,
            'color_tema'              => 'azul',
        ];
    }

    public static function estaConfigurado(): bool {
        $c = self::get();
        return trim((string)($c['razon_social'] ?? '')) !== '';
    }
}
