<?php
/**
 * Portal publico del cliente: https://dominio/?c=CODIGO
 *  - Ver y descargar la cotizacion.
 *  - Aceptarla (eligiendo fecha y hora del evento), solicitar cambios (conversacion) o rechazarla.
 *  - Aceptada: solo ver/descargar. Rechazada: "cotizacion no valida".
 */
if (!defined('SIN_SESION')) {
    define('SIN_SESION', true);
}
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/portal.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$codigo = (string)($_GET['c'] ?? '');
$cot = preg_match('/^[A-Za-z0-9]{6,12}$/', $codigo) ? db_one('SELECT * FROM cotizaciones WHERE codigo_corto = ?', [$codigo]) : null;
$emp = $cot ? db_one('SELECT * FROM empresas WHERE id = ? AND activo = 1', [$cot['empresa_id']]) : null;
if (!$cot || !$emp) {
    portal_pagina_simple(null, 'Enlace no valido', 'El enlace no es valido o la cotizacion ya no esta disponible. Comunicate con quien te la envio.', 'link-2-off');
}

$id = (int)$cot['id'];
$eid = (int)$cot['empresa_id'];
$base = '?c=' . rawurlencode($codigo);
$cliente = db_one('SELECT * FROM clientes WHERE id = ?', [$cot['cliente_id']]);
$numero = numero_cotizacion($cot, $emp);

/* ---------- PDF ---------- */
if (isset($_GET['pdf']) && $cot['estado'] !== 'rechazada') {
    require_once __DIR__ . '/includes/pdf_cotizacion.php';
    $lineas = db_all('SELECT * FROM cotizacion_detalle WHERE cotizacion_id = ? ORDER BY orden, id', [$id]);
    $pdf = construir_pdf_cotizacion($cot, $emp, $cliente, $lineas);
    enviar_pdf_sin_cache($pdf, $numero . '.pdf', empty($_GET['descargar']));
    exit;
}

/* ---------- Imagen de la cotizacion ---------- */
if (isset($_GET['img']) && $cot['estado'] !== 'rechazada') {
    require_once __DIR__ . '/includes/imagen_cotizacion.php';
    $ruta = imagen_cot_path($eid, $cot['imagen']);
    if (!$ruta) {
        http_response_code(404);
        exit;
    }
    if (ob_get_length()) {
        ob_end_clean();
    }
    header('Content-Type: ' . (substr($ruta, -4) === '.png' ? 'image/png' : 'image/jpeg'));
    header('Content-Length: ' . filesize($ruta));
    header('X-Content-Type-Options: nosniff');
    readfile($ruta);
    exit;
}

/* ---------- Mensajes nuevos (JSON, para refrescar el chat) ---------- */
if (isset($_GET['mensajes'])) {
    header('Content-Type: application/json; charset=utf-8');
    $despues = (int)($_GET['despues'] ?? 0);
    $rows = db_all('SELECT id, autor, mensaje, created_at FROM cotizacion_mensajes WHERE cotizacion_id = ? AND id > ? ORDER BY id', [$id, $despues]);
    echo json_encode(['estado' => $cot['estado'], 'huella' => substr(huella_cotizacion($id), 0, 8), 'mensajes' => array_map('portal_msg_json', $rows)], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- Acciones del cliente ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string)($_POST['accion'] ?? '');
    $ajax = !empty($_POST['ajax']);
    $nombreCli = $cliente['nombre'] ?? 'El cliente';

    if ($cot['estado'] !== 'pendiente') {
        if ($ajax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Esta cotizacion ya no admite cambios.', 'recargar' => true]);
            exit;
        }
        header('Location: ' . $base);
        exit;
    }

    if ($accion === 'mensaje') {
        $texto = trim((string)($_POST['mensaje'] ?? ''));
        if ($texto === '') {
            if ($ajax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'Escribe tu mensaje.']);
                exit;
            }
            header('Location: ' . $base . '#conversacion');
            exit;
        }
        $mid = agregar_mensaje($id, $eid, 'cliente', $texto);
        db_exec("UPDATE cotizaciones SET respuesta_cliente = 'cambios', respuesta_cliente_at = NOW() WHERE id = ?", [$id]);
        avisar_negocio($cot, $emp, 'Mensaje de ' . $nombreCli . ' - ' . $numero, $nombreCli . " escribio sobre la cotizacion " . $numero . ":\n\n" . $texto);
        if ($ajax) {
            header('Content-Type: application/json; charset=utf-8');
            $m = db_one('SELECT id, autor, mensaje, created_at FROM cotizacion_mensajes WHERE id = ?', [$mid]);
            echo json_encode(['ok' => true, 'mensaje' => portal_msg_json($m)], JSON_UNESCAPED_UNICODE);
            exit;
        }
        header('Location: ' . $base . '&ok=mensaje#conversacion');
        exit;
    }

    if ($accion === 'aceptar') {
        $fe = (string)($_POST['fecha_evento'] ?? '');
        $he = (string)($_POST['hora_evento'] ?? '');
        $nota = trim((string)($_POST['comentario'] ?? ''));
        if (!valid_date($fe) || $fe < date('Y-m-d') || !preg_match('/^\d{2}:\d{2}$/', $he)) {
            header('Location: ' . $base . '&error=fecha#responder');
            exit;
        }
        $n = db_exec("UPDATE cotizaciones SET estado = 'aceptada', fecha_evento = ?, hora_evento = ?, fecha_aceptacion = NOW(), recordatorio_enviado = NULL,
                respuesta_cliente = 'aceptada', respuesta_cliente_at = NOW(), enviada_at = COALESCE(enviada_at, NOW())
            WHERE id = ? AND estado = 'pendiente'", [$fe, $he . ':00', $id]);
        $txt = 'El cliente acepto la cotizacion para el ' . fecha_larga($fe) . ' a las ' . hora($he) . '.';
        agregar_mensaje($id, $eid, 'sistema', $txt);
        if ($nota !== '') {
            agregar_mensaje($id, $eid, 'cliente', $nota);
        }
        avisar_negocio($cot, $emp, $nombreCli . ' acepto la cotizacion ' . $numero, $txt . ($nota !== '' ? "\n\nComentario: " . $nota : '') . "\n\nEl evento ya aparece en tu calendario.");
        header('Location: ' . $base . '&ok=aceptada');
        exit;
    }

    if ($accion === 'rechazar') {
        $motivo = trim((string)($_POST['motivo'] ?? ''));
        db_exec("UPDATE cotizaciones SET estado = 'rechazada', fecha_aceptacion = NULL, recordatorio_enviado = NULL,
                respuesta_cliente = 'rechazada', respuesta_cliente_at = NOW() WHERE id = ? AND estado = 'pendiente'", [$id]);
        agregar_mensaje($id, $eid, 'sistema', 'El cliente rechazo la cotizacion.');
        if ($motivo !== '') {
            agregar_mensaje($id, $eid, 'cliente', 'Motivo: ' . $motivo);
        }
        avisar_negocio($cot, $emp, $nombreCli . ' rechazo la cotizacion ' . $numero, 'La cotizacion ' . $numero . ' fue rechazada por el cliente.' . ($motivo !== '' ? "\n\nMotivo: " . $motivo : ''));
        header('Location: ' . $base . '&ok=rechazada');
        exit;
    }
    header('Location: ' . $base);
    exit;
}

/* ---------- Rechazada: no valida ---------- */
if ($cot['estado'] === 'rechazada') {
    if (($_GET['ok'] ?? '') === 'rechazada') {
        portal_pagina_simple($emp, 'Gracias por avisarnos', 'Registramos que rechazaste la cotizacion ' . $numero . '. Si cambias de opinion o necesitas otra propuesta, escribenos.', 'circle-x', true);
    }
    portal_pagina_simple($emp, 'Cotizacion no valida', 'La cotizacion ' . $numero . ' ya no esta vigente. Si necesitas una nueva propuesta, comunicate con ' . $emp['nombre'] . '.', 'file-x-2', true);
}

// Marca que el cliente ya la abrio
if ($cot['estado'] === 'pendiente' && empty($cot['respuesta_cliente'])) {
    db_exec("UPDATE cotizaciones SET respuesta_cliente = 'vista', respuesta_cliente_at = NOW() WHERE id = ? AND respuesta_cliente IS NULL", [$id]);
}

$lineas = db_all('SELECT descripcion, unidad, cantidad, precio_unitario, subtotal FROM cotizacion_detalle WHERE cotizacion_id = ? ORDER BY orden, id', [$id]);
$mensajes = mensajes_cotizacion($id);
$pendiente = $cot['estado'] === 'pendiente';
$aceptada = $cot['estado'] === 'aceptada';
$ok = (string)($_GET['ok'] ?? '');
$c1 = $emp['color_primario'] ?: '#1E40AF';
$c2 = $emp['color_secundario'] ?: '#F59E0B';
$logo = logo_url($emp);
$telEmp = preg_replace('/\D/', '', (string)$emp['telefono']);
if (strlen($telEmp) === 8) {
    $telEmp = '502' . $telEmp;
}
$moneda = $emp['moneda'];
$cant = function ($n) { return rtrim(rtrim(number_format((float)$n, 2, '.', ','), '0'), '.'); };
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="<?= e($c1) ?>">
<meta name="robots" content="noindex, nofollow">
<title>Cotizacion <?= e($numero) ?> · <?= e($emp['nombre']) ?></title>
<meta property="og:title" content="Cotizacion <?= e($numero) ?> · <?= e($emp['nombre']) ?>">
<meta property="og:description" content="Revisa tu cotizacion: puedes verla, descargarla, aceptarla o solicitar cambios.">
<?php if ($logo): ?><meta property="og:image" content="<?= e(url_base() . '/' . ltrim($logo, '/')) ?>"><?php endif; ?>
<link rel="icon" type="image/png" href="assets/icons/favicon-32.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css?v=<?= ASSET_V ?>">
<link rel="stylesheet" href="assets/css/tailwind.css?v=<?= ASSET_V ?>">
<script src="assets/vendor/lucide.min.js"></script>
<script src="assets/js/chat.js?v=<?= ASSET_V ?>"></script>
<style>
  :root { --c1: <?= e($c1) ?>; --c2: <?= e($c2) ?>; --c1-txt: <?= e(color_texto($c1)) ?>; --c2-txt: <?= e(color_texto($c2)) ?>; }
  .portal-modal { display: none; }
  .portal-modal.abierto { display: flex; }
</style>
</head>
<body class="min-h-screen bg-slate-100 font-sans text-slate-800 antialiased <?= $pendiente ? 'pb-24 lg:pb-0' : '' ?>">

<!-- Encabezado de la empresa -->
<header class="relative overflow-hidden" style="background: var(--c1); color: var(--c1-txt)">
  <div class="pointer-events-none absolute -right-16 -top-20 h-56 w-56 rounded-full bg-white/10"></div>
  <div class="pointer-events-none absolute -bottom-24 left-10 h-40 w-40 rounded-full" style="background: color-mix(in srgb, var(--c2) 35%, transparent)"></div>
  <div class="relative mx-auto flex max-w-5xl items-center gap-3 px-4 pb-16 pt-5 sm:px-6">
    <?php if ($logo): ?>
      <img src="<?= e($logo) ?>" alt="" class="h-12 w-12 shrink-0 rounded-2xl bg-white object-contain p-1 shadow-sm">
    <?php else: ?>
      <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-white/20 text-xl font-extrabold"><?= e(mb_strtoupper(mb_substr($emp['nombre'], 0, 1))) ?></div>
    <?php endif; ?>
    <div class="min-w-0 flex-1">
      <p class="truncate text-lg font-extrabold leading-tight"><?= e($emp['nombre']) ?></p>
      <p class="truncate text-xs opacity-80"><?= e(implode(' · ', array_filter([$emp['telefono'], $emp['correo']]))) ?></p>
    </div>
    <?php if ($telEmp): ?>
      <a href="https://wa.me/<?= e($telEmp) ?>?text=<?= rawurlencode('Hola, te escribo por la cotizacion ' . $numero . '.') ?>" target="_blank" rel="noopener" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white/15 hover:bg-white/25" title="Escribir por WhatsApp" aria-label="Escribir por WhatsApp"><i data-lucide="phone" class="h-5 w-5"></i></a>
    <?php endif; ?>
  </div>
</header>

<main class="relative mx-auto -mt-12 max-w-5xl px-4 pb-10 sm:px-6">

  <?php if ($ok === 'aceptada' || ($aceptada && $ok === '')): ?>
    <div class="mb-4 flex items-start gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-900 shadow-sm">
      <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-emerald-500 text-white"><i data-lucide="calendar-check-2" class="h-5 w-5"></i></div>
      <div>
        <p class="font-bold"><?= $ok === 'aceptada' ? '¡Listo! Tu evento quedo confirmado' : 'Cotizacion aceptada' ?></p>
        <p class="text-sm">Fecha: <strong><?= e(fecha_larga($cot['fecha_evento'])) ?><?= $cot['hora_evento'] ? ' a las ' . e(hora($cot['hora_evento'])) : '' ?></strong>. <?= $ok === 'aceptada' ? e($emp['nombre']) . ' ya fue notificado.' : '' ?></p>
      </div>
    </div>
  <?php elseif ($ok === 'mensaje'): ?>
    <div class="mb-4 flex items-center gap-3 rounded-2xl border border-sky-200 bg-sky-50 p-4 text-sky-900 shadow-sm">
      <i data-lucide="send" class="h-5 w-5 shrink-0"></i><p class="text-sm font-semibold">Mensaje enviado. Te responderemos pronto en esta misma pagina.</p>
    </div>
  <?php elseif (($_GET['error'] ?? '') === 'fecha'): ?>
    <div class="mb-4 flex items-center gap-3 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-rose-900 shadow-sm">
      <i data-lucide="alert-circle" class="h-5 w-5 shrink-0"></i><p class="text-sm font-semibold">Elige una fecha (de hoy en adelante) y un horario para tu evento.</p>
    </div>
  <?php endif; ?>

  <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start">

    <!-- Cotizacion -->
    <article class="overflow-hidden rounded-3xl bg-white shadow-xl shadow-slate-900/5">
      <div class="h-1.5" style="background: linear-gradient(90deg, var(--c1), var(--c2))"></div>
      <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 p-5 sm:p-6">
        <div>
          <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Cotizacion</p>
          <h1 class="text-2xl font-extrabold texto-marca"><?= e($numero) ?></h1>
          <p class="text-xs text-slate-500">Emitida el <?= e(fecha_larga($cot['fecha'])) ?></p>
        </div>
        <?php if ($aceptada): ?>
          <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-3 py-1 text-xs font-bold text-emerald-800"><i data-lucide="check-circle-2" class="h-3.5 w-3.5"></i>Aceptada</span>
        <?php else: ?>
          <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-3 py-1 text-xs font-bold text-amber-800"><i data-lucide="clock" class="h-3.5 w-3.5"></i>Esperando tu respuesta</span>
        <?php endif; ?>
      </div>

      <div class="grid gap-4 border-b border-slate-100 p-5 sm:grid-cols-2 sm:p-6">
        <div>
          <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Cliente</p>
          <p class="font-semibold text-slate-900"><?= e($cliente['nombre'] ?? '') ?></p>
          <?php if (!empty($cliente['nit'])): ?><p class="text-xs text-slate-500">NIT: <?= e($cliente['nit']) ?></p><?php endif; ?>
        </div>
        <div>
          <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Evento</p>
          <?php if (!empty($cot['nombre_evento'])): ?><p class="font-extrabold texto-marca"><?= e($cot['nombre_evento']) ?></p><?php endif; ?>
          <p class="flex items-center gap-1.5 text-sm font-semibold text-slate-900"><i data-lucide="calendar-days" class="h-4 w-4 text-slate-400"></i><?= e(fecha_larga($cot['fecha_evento'])) ?><?= $cot['hora_evento'] ? ' · ' . e(hora($cot['hora_evento'])) : '' ?></p>
          <?php if (!empty($cot['lugar_evento'])): ?><p class="flex items-center gap-1.5 text-sm text-slate-600"><i data-lucide="map-pin" class="h-4 w-4 text-slate-400"></i><?= e($cot['lugar_evento']) ?></p><?php endif; ?>
        </div>
      </div>

      <div class="p-5 sm:p-6">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-slate-200 text-left text-[10px] font-bold uppercase tracking-wider text-slate-400 sm:text-[11px]">
              <th class="pb-2 pr-2 font-bold">Producto</th>
              <th class="pb-2 pr-2 text-right font-bold">Cant.</th>
              <th class="hidden pb-2 pr-2 text-right font-bold sm:table-cell">Precio</th>
              <th class="pb-2 text-right font-bold">Subtotal</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <?php foreach ($lineas as $l): ?>
              <tr>
                <td class="py-2.5 pr-2 align-top">
                  <p class="font-semibold text-slate-800"><?= e($l['descripcion']) ?></p>
                  <p class="text-xs text-slate-400 sm:hidden"><?= e(money($l['precio_unitario'], $moneda)) ?> c/u</p>
                </td>
                <td class="py-2.5 pr-2 text-right align-top text-slate-600"><?= e($cant($l['cantidad'])) ?></td>
                <td class="hidden py-2.5 pr-2 text-right align-top text-slate-600 sm:table-cell"><?= e(money($l['precio_unitario'], $moneda)) ?></td>
                <td class="whitespace-nowrap py-2.5 text-right align-top font-bold text-slate-900"><?= e(money($l['subtotal'], $moneda)) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <div class="mt-4 flex flex-col items-end gap-1.5">
          <?php if (cot_tiene_descuento($cot)): ?>
            <div class="flex w-full max-w-xs justify-between gap-4 text-sm text-slate-600"><span>Sub total</span><span class="font-bold text-slate-900"><?= e(money(cot_subtotal($cot), $moneda)) ?></span></div>
            <div class="flex w-full max-w-xs justify-between gap-4 text-sm text-rose-600"><span><?= e(cot_texto_descuento($cot)) ?></span><span class="shrink-0 font-bold">- <?= e(money($cot['descuento_monto'], $moneda)) ?></span></div>
          <?php endif; ?>
          <div class="mt-1 flex w-full max-w-xs items-center justify-between gap-6 rounded-2xl px-5 py-3" style="background: var(--c2); color: var(--c2-txt)">
            <span class="text-sm font-bold">TOTAL</span>
            <span class="text-xl font-extrabold"><?= e(money($cot['total'], $moneda)) ?></span>
          </div>
        </div>

        <?php if (!empty($cot['notas'])): ?>
          <div class="mt-5 rounded-2xl bg-slate-50 p-4">
            <p class="mb-1 text-[11px] font-bold uppercase tracking-wider text-slate-400">Notas</p>
            <p class="whitespace-pre-line text-sm text-slate-700"><?= e($cot['notas']) ?></p>
          </div>
        <?php endif; ?>

        <?php if (!empty($cot['imagen'])): ?>
          <a href="<?= e($base) ?>&amp;img=1" target="_blank" class="mt-5 flex h-56 items-center justify-center overflow-hidden rounded-2xl border border-slate-200 bg-slate-50 p-2 sm:h-72">
            <img src="<?= e($base) ?>&amp;img=1&amp;v=<?= e(substr(md5($cot['imagen']), 0, 6)) ?>" alt="Imagen de referencia" class="block h-full w-full object-contain">
          </a>
        <?php endif; ?>

        <?php if (!empty($emp['pie_cotizacion'])): ?>
          <p class="mt-5 whitespace-pre-line border-t border-slate-100 pt-4 text-xs text-slate-500"><?= e($emp['pie_cotizacion']) ?></p>
        <?php endif; ?>
      </div>

      <div class="grid grid-cols-2 gap-2 border-t border-slate-100 bg-slate-50/70 p-4 sm:p-5">
        <a href="<?= e($base) ?>&amp;pdf=1&amp;v=<?= e(substr(huella_cotizacion($id), 0, 6)) ?>" target="_blank" rel="noopener" class="btn btn-claro"><i data-lucide="file-text"></i>Ver PDF</a>
        <a href="<?= e($base) ?>&amp;pdf=1&amp;descargar=1&amp;v=<?= e(substr(huella_cotizacion($id), 0, 6)) ?>" class="btn btn-primario"><i data-lucide="download"></i>Descargar</a>
      </div>
    </article>

    <!-- Columna de respuesta -->
    <aside class="space-y-5 lg:sticky lg:top-5">
      <?php if ($pendiente): ?>
        <section class="rounded-3xl bg-white p-5 shadow-xl shadow-slate-900/5" id="responder">
          <h2 class="text-base font-extrabold text-slate-900">¿Que te parece?</h2>
          <p class="mt-0.5 text-sm text-slate-500">Responde aqui mismo, sin llamadas ni mensajes de ida y vuelta.</p>
          <div class="mt-4 space-y-2">
            <button type="button" class="btn w-full bg-emerald-600 py-3 text-base text-white hover:bg-emerald-700" data-abrir="modal-aceptar"><i data-lucide="check-circle-2"></i>Aceptar cotizacion</button>
            <button type="button" class="btn btn-claro w-full py-3" data-ir-chat><i data-lucide="message-square-diff"></i>Solicitar cambios</button>
            <button type="button" class="w-full rounded-xl py-2 text-sm font-semibold text-rose-600 hover:bg-rose-50" data-abrir="modal-rechazar">Rechazar cotizacion</button>
          </div>
        </section>

        <!-- Conversacion -->
        <section class="overflow-hidden rounded-3xl bg-white shadow-xl shadow-slate-900/5" id="conversacion">
          <div class="flex items-center gap-2 border-b border-slate-100 px-5 py-4">
            <i data-lucide="messages-square" class="h-5 w-5 texto-marca"></i>
            <h2 class="text-base font-extrabold text-slate-900">Conversacion</h2>
          </div>
          <div class="max-h-[26rem] space-y-3 overflow-y-auto bg-slate-50/60 px-4 py-4" id="chat-lista">
            <p class="py-6 text-center text-sm text-slate-400 <?= $mensajes ? 'hidden' : '' ?>" id="chat-vacio">¿Quieres cambiar algo? Escribe aqui lo que necesitas y <?= e($emp['nombre']) ?> te enviara la cotizacion actualizada en este mismo enlace.</p>
            <?php foreach ($mensajes as $m): ?><?= burbuja_chat($m, 'cliente', $cliente['nombre'] ?? 'Cliente', $emp['nombre'], portal_cuando($m['created_at'])) ?><?php endforeach; ?>
          </div>
          <form method="post" action="<?= e($base) ?>" class="flex items-end gap-2 border-t border-slate-100 p-3" id="chat-form">
            <input type="hidden" name="accion" value="mensaje">
            <textarea name="mensaje" rows="1" maxlength="2000" required class="textarea max-h-32 min-h-[2.75rem] flex-1 resize-none py-2.5" placeholder="Escribe tu mensaje..." id="chat-texto"></textarea>
            <button class="btn btn-primario h-11 w-11 shrink-0 p-0" aria-label="Enviar"><i data-lucide="send-horizontal"></i></button>
          </form>
        </section>
      <?php else: ?>
        <section class="rounded-3xl bg-white p-5 shadow-xl shadow-slate-900/5">
          <h2 class="text-base font-extrabold text-slate-900">Tu evento esta confirmado</h2>
          <p class="mt-1 text-sm text-slate-600"><?= e(fecha_larga($cot['fecha_evento'])) ?><?= $cot['hora_evento'] ? ' a las ' . e(hora($cot['hora_evento'])) : '' ?>.</p>
          <p class="mt-3 text-xs text-slate-500">Puedes ver o descargar tu cotizacion cuando quieras desde este enlace. Para cualquier cambio comunicate con <?= e($emp['nombre']) ?><?= $emp['telefono'] ? ' al ' . e($emp['telefono']) : '' ?>.</p>
        </section>
      <?php endif; ?>
    </aside>
  </div>

  <p class="mt-8 text-center text-[11px] text-slate-400">Cotizacion generada con el sistema de <?= e($emp['nombre']) ?> · Diseñado por Edwin Mazariegos - 31586340</p>
</main>

<?php if ($pendiente): ?>
<!-- Barra fija en movil -->
<div class="fixed inset-x-0 bottom-0 z-20 border-t border-slate-200 bg-white/95 px-4 pt-3 backdrop-blur lg:hidden" style="padding-bottom: max(.75rem, env(safe-area-inset-bottom))">
  <div class="mx-auto flex max-w-md gap-2">
    <button type="button" class="btn btn-claro flex-1" data-ir-chat><i data-lucide="message-square-diff"></i>Cambios</button>
    <button type="button" class="btn flex-[1.4] bg-emerald-600 text-white hover:bg-emerald-700" data-abrir="modal-aceptar"><i data-lucide="check-circle-2"></i>Aceptar</button>
  </div>
</div>

<!-- Modal aceptar -->
<div class="portal-modal fixed inset-0 z-50 items-center justify-center overflow-y-auto bg-slate-900/50 p-4" id="modal-aceptar">
  <form method="post" action="<?= e($base) ?>" class="my-auto w-full max-w-md rounded-3xl bg-white p-5 shadow-2xl sm:p-6">
    <input type="hidden" name="accion" value="aceptar">
    <div class="mb-1 flex items-center justify-between">
      <h3 class="text-lg font-extrabold text-slate-900">Confirmar tu evento</h3>
      <button type="button" class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100" data-cerrar aria-label="Cerrar"><i data-lucide="x" class="h-5 w-5"></i></button>
    </div>
    <p class="mb-4 text-sm text-slate-500">Elige el dia y el horario de tu evento. Al confirmar, la cotizacion queda aceptada y se agenda tu fecha.</p>
    <div class="grid grid-cols-2 gap-3">
      <label class="campo min-w-0"><span>Fecha del evento</span>
        <input class="input min-w-0" type="date" name="fecha_evento" value="<?= e($cot['fecha_evento'] >= date('Y-m-d') ? $cot['fecha_evento'] : '') ?>" min="<?= date('Y-m-d') ?>" required>
      </label>
      <label class="campo min-w-0"><span>Horario</span>
        <input class="input min-w-0" type="time" name="hora_evento" value="<?= e($cot['hora_evento'] ? substr($cot['hora_evento'], 0, 5) : '') ?>" required>
      </label>
    </div>
    <label class="campo mt-3"><span>Comentario <span class="font-normal text-slate-400">(opcional)</span></span>
      <textarea class="textarea" name="comentario" rows="2" maxlength="1000" placeholder="Algo que debamos saber"></textarea>
    </label>
    <div class="mt-4 rounded-2xl bg-slate-50 p-3 text-sm">
      <div class="flex justify-between"><span class="text-slate-500">Total</span><strong class="text-slate-900"><?= e(money($cot['total'], $moneda)) ?></strong></div>
    </div>
    <button class="btn mt-4 w-full bg-emerald-600 py-3 text-base text-white hover:bg-emerald-700"><i data-lucide="check-circle-2"></i>Aceptar y confirmar fecha</button>
  </form>
</div>

<!-- Modal rechazar -->
<div class="portal-modal fixed inset-0 z-50 items-center justify-center overflow-y-auto bg-slate-900/50 p-4" id="modal-rechazar">
  <form method="post" action="<?= e($base) ?>" class="my-auto w-full max-w-md rounded-3xl bg-white p-5 shadow-2xl sm:p-6">
    <input type="hidden" name="accion" value="rechazar">
    <div class="mb-1 flex items-center justify-between">
      <h3 class="text-lg font-extrabold text-slate-900">Rechazar cotizacion</h3>
      <button type="button" class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100" data-cerrar aria-label="Cerrar"><i data-lucide="x" class="h-5 w-5"></i></button>
    </div>
    <p class="mb-4 text-sm text-slate-500">¿Seguro? Si solo quieres ajustar algo, mejor <button type="button" class="font-semibold texto-marca underline" data-ir-chat>solicita cambios</button>.</p>
    <label class="campo"><span>Motivo <span class="font-normal text-slate-400">(opcional)</span></span>
      <textarea class="textarea" name="motivo" rows="3" maxlength="1000" placeholder="Nos ayuda a mejorar"></textarea>
    </label>
    <div class="mt-5 flex gap-3">
      <button type="button" class="btn btn-claro flex-1" data-cerrar>Volver</button>
      <button class="btn flex-1 bg-rose-600 text-white hover:bg-rose-700">Si, rechazar</button>
    </div>
  </form>
</div>
<?php endif; ?>

<script>
lucide.createIcons();
(function () {
  function $(s, c) { return (c || document).querySelector(s); }
  function abrir(id) { var m = document.getElementById(id); if (!m) return; m.classList.add('abierto'); var f = m.querySelector('input:not([type=hidden]),textarea'); if (f && window.innerWidth > 640) setTimeout(function () { f.focus(); }, 50); }
  function cerrarTodo() { Array.prototype.forEach.call(document.querySelectorAll('.portal-modal.abierto'), function (m) { m.classList.remove('abierto'); }); }
  document.addEventListener('click', function (ev) {
    var a = ev.target.closest('[data-abrir]'); if (a) { abrir(a.getAttribute('data-abrir')); return; }
    if (ev.target.closest('[data-cerrar]') || ev.target.classList.contains('portal-modal')) { cerrarTodo(); return; }
    if (ev.target.closest('[data-ir-chat]')) {
      cerrarTodo();
      var c = document.getElementById('conversacion'); if (!c) return;
      c.scrollIntoView({ behavior: 'smooth', block: 'start' });
      setTimeout(function () { var t = document.getElementById('chat-texto'); if (t) t.focus(); }, 450);
    }
  });
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') cerrarTodo(); });
  Array.prototype.forEach.call(document.querySelectorAll('.portal-modal form'), function (f) {
    f.addEventListener('submit', function () { var b = f.querySelector('button:not([type=button])'); if (b) setTimeout(function () { b.disabled = true; }, 0); });
  });

  /* Chat */
  var lista = $('#chat-lista'), form = $('#chat-form'), texto = $('#chat-texto');
  if (!lista) return;
  lista.scrollTop = lista.scrollHeight;
  var ultimo = <?= $mensajes ? (int)end($mensajes)['id'] : 0 ?>, huella = '<?= e(substr(huella_cotizacion($id), 0, 8)) ?>';
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function burbuja(m) { return window.burbujaChat(m, 'cliente', <?= json_encode($cliente['nombre'] ?? 'Cliente', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>, <?= json_encode($emp['nombre'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>); }
  function agregar(m) { if (m.id <= ultimo) return; ultimo = m.id; $('#chat-vacio') && $('#chat-vacio').classList.add('hidden'); lista.insertAdjacentHTML('beforeend', burbuja(m)); lista.scrollTop = lista.scrollHeight; }
  texto.addEventListener('input', function () { texto.style.height = 'auto'; texto.style.height = Math.min(texto.scrollHeight, 128) + 'px'; });
  form.addEventListener('submit', function (ev) {
    if (!window.fetch) return;
    ev.preventDefault();
    if (!texto.value.trim()) return;
    var fd = new FormData(form); fd.append('ajax', '1');
    var btn = form.querySelector('button'); btn.disabled = true;
    fetch(form.action, { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (r) {
      btn.disabled = false;
      if (r.recargar) { location.reload(); return; }
      if (!r.ok) { alert(r.error || 'No se pudo enviar.'); return; }
      texto.value = ''; texto.style.height = ''; agregar(r.mensaje);
    }).catch(function () { btn.disabled = false; form.submit(); });
  });
  // Revisa respuestas del negocio cada 20 s
  setInterval(function () {
    if (document.hidden) return;
    fetch('<?= e($base) ?>&mensajes=1&despues=' + ultimo).then(function (r) { return r.json(); }).then(function (r) {
      (r.mensajes || []).forEach(agregar);
      if (r.estado !== 'pendiente' || r.huella !== huella) {
        if (!texto.value.trim() && !document.querySelector('.portal-modal.abierto')) location.reload();
      }
    }).catch(function () {});
  }, 20000);
})();
</script>
</body>
</html>
<?php
/* ===================== Funciones de la vista ===================== */

function portal_msg_json($m)
{
    return ['id' => (int)$m['id'], 'autor' => $m['autor'], 'mensaje' => $m['mensaje'], 'cuando' => portal_cuando($m['created_at'])];
}

function portal_cuando($dt)
{
    $t = strtotime($dt);
    $hoy = date('Y-m-d');
    $d = date('Y-m-d', $t);
    if ($d === $hoy) {
        return 'Hoy ' . date('g:i A', $t);
    }
    if ($d === date('Y-m-d', strtotime('-1 day'))) {
        return 'Ayer ' . date('g:i A', $t);
    }
    return date('d/m/Y g:i A', $t);
}

/** Pagina sencilla (enlace invalido, rechazada) y termina. */
function portal_pagina_simple($emp, $titulo, $texto, $icono, $conMarca = false)
{
    $c1 = $emp['color_primario'] ?? '#1E40AF';
    $logo = $emp ? logo_url($emp) : null;
    http_response_code($emp ? 200 : 404);
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($titulo) ?></title>
<link rel="icon" type="image/png" href="assets/icons/favicon-32.png">
<link rel="stylesheet" href="assets/css/app.css?v=<?= ASSET_V ?>">
<link rel="stylesheet" href="assets/css/tailwind.css?v=<?= ASSET_V ?>">
<script src="assets/vendor/lucide.min.js"></script>
<style>:root { --c1: <?= e($c1) ?>; --c1-txt: <?= e(color_texto($c1)) ?>; }</style>
</head>
<body class="flex min-h-screen items-center justify-center bg-slate-100 p-5 font-sans text-slate-800 antialiased">
  <div class="w-full max-w-sm overflow-hidden rounded-3xl bg-white text-center shadow-xl">
    <?php if ($emp && $conMarca): ?>
      <div class="flex items-center justify-center gap-2 px-5 py-4" style="background: var(--c1); color: var(--c1-txt)">
        <?php if ($logo): ?><img src="<?= e($logo) ?>" alt="" class="h-8 w-8 rounded-lg bg-white object-contain p-0.5"><?php endif; ?>
        <span class="font-bold"><?= e($emp['nombre']) ?></span>
      </div>
    <?php endif; ?>
    <div class="p-7">
      <div class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-500"><i data-lucide="<?= e($icono) ?>" class="h-7 w-7"></i></div>
      <h1 class="text-xl font-extrabold text-slate-900"><?= e($titulo) ?></h1>
      <p class="mt-2 text-sm text-slate-500"><?= e($texto) ?></p>
      <?php if ($emp && !empty($emp['telefono'])): ?><p class="mt-4 text-sm font-semibold text-slate-700"><?= e($emp['telefono']) ?></p><?php endif; ?>
    </div>
  </div>
  <script>lucide.createIcons();</script>
</body>
</html>
    <?php
    exit;
}
