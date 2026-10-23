<?php
/**
 * Webhook de despliegue automatico desde GitHub.
 *
 * GitHub (Settings > Webhooks) envia un POST a https://TU-DOMINIO/deploy.php en cada push.
 * Este script valida la firma con el secreto y actualiza el codigo del servidor a la rama configurada.
 *
 * Metodo 1: ejecuta git directamente (si el hosting permite shell_exec).
 * Metodo 2: usa la API de cPanel (VersionControl/update) con un token de API.
 *
 * Configuracion en config/config.php, seccion 'deploy'.
 */

$cfg = require __DIR__ . '/config/config.php';
$d = $cfg['deploy'] ?? [];
date_default_timezone_set($cfg['app']['timezone'] ?? 'America/Guatemala');
header('Content-Type: text/plain; charset=utf-8');

function deploy_log($msg)
{
    $f = __DIR__ . '/cron/deploy.log';
    if (is_file($f) && filesize($f) > 524288) {
        @rename($f, $f . '.1');
    }
    @file_put_contents($f, date('Y-m-d H:i:s') . ' ' . $msg . PHP_EOL, FILE_APPEND);
}

function responder($code, $msg)
{
    http_response_code($code);
    deploy_log('[' . $code . '] ' . $msg);
    exit($msg . PHP_EOL);
}

$secreto = (string)($d['secret'] ?? '');
if (strlen($secreto) < 20) {
    responder(500, 'Deploy no configurado (falta deploy.secret en config.php).');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    responder(405, 'Solo POST.');
}

$cuerpo = file_get_contents('php://input');
$firma = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$esperada = 'sha256=' . hash_hmac('sha256', $cuerpo, $secreto);
if (!$firma || !hash_equals($esperada, $firma)) {
    responder(403, 'Firma invalida.');
}

$evento = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
if ($evento === 'ping') {
    responder(200, 'pong: webhook configurado correctamente.');
}
if ($evento !== 'push') {
    responder(200, 'Evento ignorado: ' . $evento);
}

// El payload puede venir como JSON o como form (payload=...)
$datos = json_decode($cuerpo, true);
if (!$datos && isset($_POST['payload'])) {
    $datos = json_decode($_POST['payload'], true);
}
$rama = $d['branch'] ?? 'dev';
$ref = $datos['ref'] ?? '';
if ($ref !== 'refs/heads/' . $rama) {
    responder(200, 'Push a ' . $ref . ' ignorado (se despliega solo ' . $rama . ').');
}
$commit = substr($datos['after'] ?? '', 0, 7);
$ruta = rtrim($d['path'] ?? __DIR__, '/');

/* ---------- Metodo 1: git por shell ---------- */
$salida = null;
$puedeShell = function_exists('shell_exec') && !in_array('shell_exec', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true);
if ($puedeShell) {
    $git = null;
    foreach (['/usr/local/cpanel/3rdparty/bin/git', '/usr/bin/git', '/usr/local/bin/git'] as $g) {
        if (@is_executable($g)) {
            $git = $g;
            break;
        }
    }
    if ($git) {
        $r = escapeshellarg($ruta);
        $b = escapeshellarg($rama);
        $g = escapeshellarg($git);
        $cmd = "cd $r && $g fetch origin $b 2>&1 && $g reset --hard origin/$rama 2>&1";
        $salida = shell_exec($cmd);
        if ($salida !== null && strpos($salida, 'HEAD is now at') !== false) {
            responder(200, 'Actualizado por git a ' . $commit . ': ' . trim($salida));
        }
        deploy_log('git fallo: ' . trim((string)$salida));
    }
}

/* ---------- Metodo 2: API de cPanel ---------- */
$cp = $d['cpanel'] ?? [];
if (!empty($cp['user']) && !empty($cp['token'])) {
    $host = $cp['host'] ?? 'localhost';
    $url = 'https://' . $host . ':2083/execute/VersionControl/update?' . http_build_query([
        'repository_root' => $ruta,
        'branch'          => $rama,
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: cpanel ' . $cp['user'] . ':' . $cp['token']],
        CURLOPT_TIMEOUT        => 60,
        // localhost no tiene certificado valido para ese nombre
        CURLOPT_SSL_VERIFYPEER => $host !== 'localhost',
        CURLOPT_SSL_VERIFYHOST => $host !== 'localhost' ? 2 : 0,
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    $json = json_decode((string)$resp, true);
    if (!empty($json['status'])) {
        responder(200, 'Actualizado por API de cPanel a ' . $commit . '.');
    }
    responder(500, 'Fallo la API de cPanel: ' . ($err ?: (string)$resp));
}

responder(500, 'No se pudo actualizar: shell_exec no disponible o git fallo, y no hay token de cPanel configurado. Salida: ' . trim((string)$salida));
