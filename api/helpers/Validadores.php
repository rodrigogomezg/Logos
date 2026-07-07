<?php

class Validadores {
    
    /**
     * Valida que una fecha esté en formato YYYY-MM-DD y sea válida
     */
    public static function validarFecha(string $fecha, string $nombre = 'fecha'): string {
        $fecha = trim($fecha);
        
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            throw new Exception("$nombre debe estar en formato YYYY-MM-DD (ej: 2024-01-15), recibido: $fecha");
        }
        
        $partes = explode('-', $fecha);
        $año = (int)$partes[0];
        $mes = (int)$partes[1];
        $día = (int)$partes[2];
        
        if (!checkdate($mes, $día, $año)) {
            throw new Exception("$nombre no es una fecha válida: $fecha");
        }
        
        if ($año > date('Y') + 1) {
            throw new Exception("$nombre no puede ser más de 1 año en el futuro: $fecha");
        }
        
        return $fecha;
    }
    
    /**
     * Valida rango de fechas (desde <= hasta)
     */
    public static function validarRangoFechas(string $desde, string $hasta): void {
        $desde_validado = self::validarFecha($desde, 'fecha_desde');
        $hasta_validado = self::validarFecha($hasta, 'fecha_hasta');
        
        if (strtotime($desde_validado) > strtotime($hasta_validado)) {
            throw new Exception("fecha_desde no puede ser posterior a fecha_hasta: $desde > $hasta");
        }
    }
    
    /**
     * Valida que un número esté en rango
     */
    public static function validarRango($valor, $min, $max, string $nombre = 'valor'): void {
        $valor = (float)$valor;
        if ($valor < $min || $valor > $max) {
            throw new Exception("$nombre debe estar entre $min y $max, recibido: $valor");
        }
    }
    
    /**
     * Valida que un valor esté en una lista de opciones permitidas
     */
    public static function validarEnum($valor, array $opciones, string $nombre = 'valor'): void {
        if (!in_array($valor, $opciones, true)) {
            throw new Exception("$nombre debe ser uno de: " . implode(', ', $opciones) . ". Recibido: $valor");
        }
    }
    
    /**
     * Valida que un porcentaje IVA sea válido (0-100)
     */
    public static function validarIVA(float $porcentaje): void {
        if ($porcentaje < 0 || $porcentaje > 100) {
            throw new Exception("Porcentaje IVA debe estar entre 0 y 100, recibido: $porcentaje");
        }
    }
}
