<?php

class SystemPaths {
    
    /**
     * Encuentra el binario de MySQL en el sistema
     * Intenta: which/where → variaciones comunes → falla con error claro
     */
    public static function findMysqlBin(): string {
        // 1. Intentar con which (Linux/Mac) o where (Windows)
        $comandoFind = PHP_OS_FAMILY === 'Windows' ? 'where' : 'which';
        $descarte    = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $output = trim(shell_exec("$comandoFind mysql 2>$descarte") ?: '');
        if ($output && file_exists($output)) {
            return $output;
        }

        // 2. Rutas comunes en Windows
        if (PHP_OS_FAMILY === 'Windows') {
            $rutasComunes = [
                'C:\xampp\mysql\bin\mysql.exe',
                'C:\wamp\bin\mysql\mysql8.0.1\bin\mysql.exe',
                'C:\wamp64\bin\mysql\mysql8.0.1\bin\mysql.exe',
                'C:\laragon\bin\mysql\mysql-8.0.1\bin\mysql.exe',
                'C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe',
                'C:\Program Files (x86)\MySQL\MySQL Server 8.0\bin\mysql.exe',
            ];
            foreach ($rutasComunes as $ruta) {
                if (file_exists($ruta)) return $ruta;
            }
        }

        // 3. Rutas comunes en Linux/Mac
        if (PHP_OS_FAMILY !== 'Windows') {
            $rutasComunes = [
                '/usr/bin/mysql',
                '/usr/local/bin/mysql',
                '/opt/homebrew/bin/mysql',
                '/usr/local/mysql/bin/mysql',
            ];
            foreach ($rutasComunes as $ruta) {
                if (file_exists($ruta)) return $ruta;
            }
        }

        throw new Exception(
            'No se pudo encontrar el binario de MySQL. ' .
            'Verificá que MySQL esté instalado y agregado al PATH del sistema.'
        );
    }

    /**
     * Encuentra el binario de MySQLdump
     */
    public static function findMysqldumpBin(): string {
        $comandoFind = PHP_OS_FAMILY === 'Windows' ? 'where' : 'which';
        $descarte    = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $output = trim(shell_exec("$comandoFind mysqldump 2>$descarte") ?: '');
        if ($output && file_exists($output)) {
            return $output;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $rutasComunes = [
                'C:\xampp\mysql\bin\mysqldump.exe',
                'C:\wamp\bin\mysql\mysql8.0.1\bin\mysqldump.exe',
                'C:\wamp64\bin\mysql\mysql8.0.1\bin\mysqldump.exe',
                'C:\laragon\bin\mysql\mysql-8.0.1\bin\mysqldump.exe',
                'C:\Program Files\MySQL\MySQL Server 8.0\bin\mysqldump.exe',
            ];
            foreach ($rutasComunes as $ruta) {
                if (file_exists($ruta)) return $ruta;
            }
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            $rutasComunes = [
                '/usr/bin/mysqldump',
                '/usr/local/bin/mysqldump',
                '/opt/homebrew/bin/mysqldump',
                '/usr/local/mysql/bin/mysqldump',
            ];
            foreach ($rutasComunes as $ruta) {
                if (file_exists($ruta)) return $ruta;
            }
        }

        throw new Exception(
            'No se pudo encontrar mysqldump. Verificá que MySQL esté instalado y en el PATH'
        );
    }

    /**
     * Valida que una carpeta sea segura para operaciones
     */
    public static function validarCarpetaSegura(string $carpeta): string {
        $carpeta = trim($carpeta);
        
        if ($carpeta === '' || $carpeta === '/') {
            throw new Exception('Carpeta inválida: no se puede usar la raíz del sistema');
        }
        
        $carpeta_real = realpath($carpeta);
        if ($carpeta_real === false) {
            $carpeta_padre = dirname($carpeta);
            $carpeta_padre_real = realpath($carpeta_padre);
            
            if ($carpeta_padre_real === false) {
                throw new Exception("Carpeta o su padre no existen: $carpeta");
            }
            
            self::verificarCarpetaNoProhibida($carpeta_padre_real);
            $carpeta_real = $carpeta_padre_real . DIRECTORY_SEPARATOR . basename($carpeta);
        } else {
            self::verificarCarpetaNoProhibida($carpeta_real);
        }
        
        return $carpeta_real;
    }
    
    /**
     * Verifica que una carpeta no sea de directorios prohibidos
     */
    private static function verificarCarpetaNoProhibida(string $carpeta_real): void {
        $prohibidas = [
            '/',
            '/etc',
            '/sys',
            '/proc',
            '/boot',
            '/dev',
            '/root',
            '/bin',
            '/sbin',
            '/usr/bin',
            '/usr/sbin',
            '/usr/local/bin',
            '/var/www',
            '/var/log',
            '/var/backups',
            'C:\\',
            'C:\\Windows',
            'C:\\Program Files',
            'C:\\Program Files (x86)',
            'C:\\ProgramData',
            'C:\\Users',
        ];

        $carpeta_real = str_replace('\\', '/', $carpeta_real);

        foreach ($prohibidas as $prohibida) {
            $prohibida = str_replace('\\', '/', $prohibida);
            $prohibida = rtrim($prohibida, '/');
            
            if ($carpeta_real === $prohibida) {
                throw new Exception("No se puede usar la carpeta del sistema: $carpeta_real");
            }
            
            if (strpos($carpeta_real, $prohibida . '/') === 0) {
                throw new Exception("La carpeta no puede estar dentro de: $prohibida");
            }
        }
        
        if (!is_writable($carpeta_real)) {
            throw new Exception("No hay permisos de escritura en: $carpeta_real");
        }
    }
    
    /**
     * Valida que un archivo esté dentro de una carpeta segura
     */
    public static function validarArchivoEnCarpeta(string $archivo, string $carpeta_base): void {
        $archivo_real = realpath($archivo);
        $carpeta_real = realpath($carpeta_base);
        
        if ($archivo_real === false || $carpeta_real === false) {
            throw new Exception('Archivo o carpeta no existen');
        }
        
        if (strpos($archivo_real, $carpeta_real . DIRECTORY_SEPARATOR) !== 0 &&
            $archivo_real !== $carpeta_real) {
            throw new Exception("El archivo está fuera de la carpeta permitida: $archivo");
        }
    }
}
