<?php

class UploadsController {

    // La extensión sale SIEMPRE del MIME detectado por contenido, nunca del nombre
    // que mandó el cliente: un "foto.php" con bytes de JPEG terminaría ejecutable.
    private const TIPOS_OK  = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/gif'       => 'gif',
        'image/webp'      => 'webp',
        'application/pdf' => 'pdf',
    ];
    private const MAX_BYTES = 5 * 1024 * 1024; // 5 MB
    private const DIR_DISCO = __DIR__ . '/../../uploads/comprobantes/';
    private const DIR_WEB   = '/Logos/uploads/comprobantes/';

    public function subir(): void {
        if (empty($_FILES['archivo'])) {
            json(400, ['error' => 'No se recibió ningún archivo']);
        }

        $f = $_FILES['archivo'];

        if ($f['error'] !== UPLOAD_ERR_OK) {
            $msgs = [
                UPLOAD_ERR_INI_SIZE   => 'El archivo supera el límite del servidor',
                UPLOAD_ERR_FORM_SIZE  => 'El archivo supera el tamaño permitido',
                UPLOAD_ERR_PARTIAL    => 'La subida fue interrumpida',
                UPLOAD_ERR_NO_FILE    => 'No se seleccionó ningún archivo',
            ];
            json(400, ['error' => $msgs[$f['error']] ?? 'Error al subir el archivo']);
        }

        if ($f['size'] > self::MAX_BYTES) {
            json(400, ['error' => 'El archivo supera el límite de 5 MB']);
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        if (!isset(self::TIPOS_OK[$mime])) {
            json(400, ['error' => 'Tipo de archivo no permitido. Solo imágenes (JPG, PNG, WEBP) o PDF.']);
        }

        if (!is_dir(self::DIR_DISCO)) {
            mkdir(self::DIR_DISCO, 0755, true);
        }

        $ext  = self::TIPOS_OK[$mime];
        $name = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $dest = self::DIR_DISCO . $name;

        if (!move_uploaded_file($f['tmp_name'], $dest)) {
            json(500, ['error' => 'No se pudo guardar el archivo en el servidor']);
        }

        json(200, [
            'path'   => self::DIR_WEB . $name,
            'nombre' => $f['name'],
            'mime'   => $mime,
        ]);
    }
}
