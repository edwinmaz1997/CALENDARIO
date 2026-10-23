<?php
/**
 * Envio de correo con PHPMailer (SMTP si esta configurado; si no, mail() del servidor).
 */
require_once APP_ROOT . '/lib/phpmailer/Exception.php';
require_once APP_ROOT . '/lib/phpmailer/PHPMailer.php';
require_once APP_ROOT . '/lib/phpmailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

/**
 * @param string|array $para      correo o lista de correos
 * @param array        $adjuntos  [['contenido' => binario, 'nombre' => 'archivo.pdf', 'mime' => 'application/pdf'], ...]
 * @param string|null  $responderA correo para "Responder a"
 * @return array [bool ok, string|null error]
 */
function enviar_correo($para, $asunto, $html, $texto = '', array $adjuntos = [], $responderA = null, $nombreRemitente = null)
{
    $cfg = $GLOBALS['CONFIG']['mail'] ?? [];
    $desde = $cfg['from_email'] ?? '';
    if ($desde === '' || !filter_var($desde, FILTER_VALIDATE_EMAIL)) {
        return [false, 'Falta configurar mail.from_email en config/config.php.'];
    }

    $m = new PHPMailer(true);
    try {
        $m->CharSet = 'UTF-8';
        $m->Encoding = 'base64';
        $smtp = $cfg['smtp'] ?? [];
        if (!empty($smtp['host'])) {
            $m->isSMTP();
            $m->Host = $smtp['host'];
            $m->Port = (int)($smtp['port'] ?? 465);
            $m->SMTPAuth = !empty($smtp['user']);
            $m->Username = $smtp['user'] ?? '';
            $m->Password = $smtp['pass'] ?? '';
            $sec = strtolower($smtp['secure'] ?? 'ssl');
            $m->SMTPSecure = $sec === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : ($sec === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : '');
            $m->SMTPAutoTLS = $sec !== '';
            $m->Timeout = 20;
        } else {
            $m->isMail();
        }
        $m->setFrom($desde, $nombreRemitente ?: ($cfg['from_name'] ?? 'Cotizador'));
        foreach ((array)$para as $p) {
            $m->addAddress($p);
        }
        if ($responderA && filter_var($responderA, FILTER_VALIDATE_EMAIL)) {
            $m->addReplyTo($responderA);
        }
        foreach ($adjuntos as $a) {
            $m->addStringAttachment($a['contenido'], $a['nombre'], PHPMailer::ENCODING_BASE64, $a['mime'] ?? 'application/octet-stream');
        }
        $m->isHTML(true);
        $m->Subject = $asunto;
        $m->Body = $html;
        $m->AltBody = $texto !== '' ? $texto : trim(html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>|<\/p>|<\/tr>/i', "\n", $html)), ENT_QUOTES, 'UTF-8'));
        $m->send();
        return [true, null];
    } catch (Exception $ex) {
        return [false, $m->ErrorInfo ?: $ex->getMessage()];
    }
}
