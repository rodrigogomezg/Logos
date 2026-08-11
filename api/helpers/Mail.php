<?php

use PHPMailer\PHPMailer\PHPMailer;

class Mail {

    /**
     * Envía el comprobante al cliente por email con el PDF adjunto y
     * la plantilla HTML estilizada.
     */
    public static function enviarComprobante(
        array  $config,
        array  $venta,
        string $pdfBin,
        string $para,
        string $asunto
    ): void {
        $mail = self::buildMailer($config);
        $mail->addAddress($para, $venta['cliente_nombre'] ?? '');
        $mail->Subject = $asunto;

        require_once __DIR__ . '/Configuracion.php';
        $logoPath = Configuracion::rutaLogo();
        $logoB64  = file_exists($logoPath) ? base64_encode(file_get_contents($logoPath)) : null;
        $logoMime = 'image/png';

        ob_start();
        require __DIR__ . '/../../pos/templates/mail_comprobante.php';
        $mail->Body    = ob_get_clean();
        $mail->AltBody = self::textoPlano($venta, $config);

        $filename = 'Comprobante_' . $venta['numero'] . '.pdf';
        $mail->addStringAttachment($pdfBin, $filename, PHPMailer::ENCODING_BASE64, 'application/pdf');

        $mail->send();
    }

    /** Envía un mail de prueba para verificar la configuración SMTP. */
    public static function probar(array $config, string $para): void {
        $mail = self::buildMailer($config);
        $mail->addAddress($para);
        $mail->Subject = 'Prueba de configuración SMTP — Logos';
        $negocio = htmlspecialchars($config['nombre_fantasia'] ?: $config['razon_social']);
        $mail->Body    = '<p style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#333">Si recibís este mail, la configuración SMTP de <strong>' . $negocio . '</strong> está funcionando correctamente.</p>';
        $mail->AltBody = 'Si recibís este mail, la configuración SMTP está funcionando correctamente.';
        $mail->send();
    }

    private static function buildMailer(array $config): PHPMailer {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host     = $config['smtp_host'];
        $mail->SMTPAuth = true;
        $mail->Username = $config['smtp_usuario'];
        $mail->Password = $config['smtp_clave'];
        $mail->Port     = (int)($config['smtp_puerto'] ?? 587);

        $seg = strtolower(trim($config['smtp_seguridad'] ?? 'tls'));
        if ($seg === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($seg === 'none') {
            $mail->SMTPSecure  = '';
            $mail->SMTPAutoTLS = false;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        $deNombre = $config['smtp_de_nombre'] ?: ($config['nombre_fantasia'] ?: $config['razon_social']);
        $mail->setFrom($config['smtp_de_email'], $deNombre);

        $replyTo = trim($config['smtp_reply_to'] ?? '');
        if ($replyTo) {
            $mail->addReplyTo($replyTo);
        }

        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->isHTML(true);
        $mail->Timeout = 30;

        return $mail;
    }

    private static function textoPlano(array $venta, array $config): string {
        $negocio = $config['nombre_fantasia'] ?: $config['razon_social'];
        $total   = '$' . number_format((float)$venta['total'], 2, ',', '.');
        $cliente = $venta['cliente_nombre'] ?? 'Cliente';
        $tipo    = $venta['tipo_comprobante'] ?? 'Comprobante';
        $numero  = $venta['numero'];
        $fecha   = date('d/m/Y', strtotime($venta['fecha']));
        $tel     = $config['telefono'] ? "\n" . $config['telefono'] : '';
        $dom     = $config['domicilio'] ? "\n" . $config['domicilio'] : '';

        return "Hola {$cliente},\n\nAdjuntamos tu {$tipo} N° {$numero} del {$fecha} por {$total}.\nEncontrás el comprobante completo adjunto en formato PDF.\n\n{$negocio}{$dom}{$tel}\n\n---\nSistema Logos desarrollado por BRON — Sistemas y Programación\nhola@bron.com.ar";
    }
}
