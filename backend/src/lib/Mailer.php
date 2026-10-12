<?php
// Cliente SMTP mínimo para enviar notificaciones a Mailpit (servicio "mailpit", puerto 1025).
// Mailpit solo captura los correos para pruebas; no entrega correo real.

function smtp_esperar($socket, string $codigo): void
{
    $respuesta = '';
    while (($linea = fgets($socket, 512)) !== false) {
        $respuesta .= $linea;
        if (isset($linea[3]) && $linea[3] === ' ') {
            break;
        }
    }
    if (strncmp($respuesta, $codigo, 3) !== 0) {
        throw new RuntimeException("SMTP: se esperaba $codigo y se recibió: " . trim($respuesta));
    }
}

function smtp_comando($socket, string $comando, string $codigo): void
{
    fwrite($socket, $comando . "\r\n");
    smtp_esperar($socket, $codigo);
}

function enviar_correo(string $para, string $asunto, string $html): void
{
    $host = getenv('SMTP_HOST') ?: 'mailpit';
    $port = (int) (getenv('SMTP_PORT') ?: 1025);
    $de   = getenv('MAIL_FROM') ?: 'reservaciones@practica.local';

    $socket = @fsockopen($host, $port, $errno, $errstr, 5);
    if (!$socket) {
        throw new RuntimeException("No se pudo conectar a $host:$port ($errstr)");
    }
    stream_set_timeout($socket, 5);

    try {
        smtp_esperar($socket, '220');
        smtp_comando($socket, 'EHLO backend', '250');
        smtp_comando($socket, "MAIL FROM:<$de>", '250');
        smtp_comando($socket, "RCPT TO:<$para>", '250');
        smtp_comando($socket, 'DATA', '354');

        $cabeceras = [
            'From: Sistema de Reservaciones <' . $de . '>',
            'To: <' . $para . '>',
            'Subject: =?UTF-8?B?' . base64_encode($asunto) . '?=',
            'Date: ' . date(DATE_RFC2822),
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        $cuerpo = chunk_split(base64_encode($html));
        fwrite($socket, implode("\r\n", $cabeceras) . "\r\n\r\n" . $cuerpo . "\r\n.\r\n");
        smtp_esperar($socket, '250');
        smtp_comando($socket, 'QUIT', '221');
    } finally {
        fclose($socket);
    }
}

function notificar_reservacion(array $r): void
{
    $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $html = <<<HTML
    <h2>Nueva reservación registrada (#{$e($r['id'])})</h2>
    <p>Hola {$e($r['solicitante'])}, tu solicitud de espacio fue registrada.</p>
    <table border="1" cellpadding="6" cellspacing="0">
      <tr><th align="left">Espacio</th><td>{$e($r['espacio'])}</td></tr>
      <tr><th align="left">Fecha</th><td>{$e($r['fecha'])}</td></tr>
      <tr><th align="left">Horario</th><td>{$e(substr($r['hora_inicio'], 0, 5))} - {$e(substr($r['hora_fin'], 0, 5))}</td></tr>
      <tr><th align="left">Estado</th><td>{$e($r['estado'])}</td></tr>
    </table>
    <p><small>Correo de prueba capturado por Mailpit.</small></p>
    HTML;
    enviar_correo($r['correo'], "Reservación #{$r['id']}: {$r['espacio']}", $html);
    error_log("[mail] Notificacion enviada a {$r['correo']} (reservacion #{$r['id']})");
}
