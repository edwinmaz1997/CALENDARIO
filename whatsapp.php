<?php
/**
 * Estado y prueba de la integracion con WhatsApp (solo administrador).
 */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/whatsapp.php';
require_admin();

$c = wa_config();

if (is_post()) {
    csrf_check();
    if (input('accion') === 'prueba') {
        $tel = wa_telefono(input('telefono'));
        $plantilla = input('plantilla', 'hello_world');
        if (!$tel) {
            flash('error', 'Escribe un numero valido (8 digitos o con codigo de pais).');
        } else {
            if ($plantilla === 'hello_world') {
                list($ok, $mid, $err) = wa_enviar_plantilla($tel, 'hello_world', [], 'en_US');
            } elseif ($plantilla === 'cliente') {
                list($ok, $mid, $err) = wa_enviar_plantilla($tel, $c['plantilla_cliente'], ['Cliente de prueba', 'Mi empresa', fecha_larga(date('Y-m-d', strtotime('+5 days'))) . ' a las 4:00 PM', 'Salon de prueba', 'Q 1,500.00']);
            } else {
                list($ok, $mid, $err) = wa_enviar_plantilla($tel, $c['plantilla_proveedor'], ['Proveedor de prueba', 'Mi empresa', fecha_larga(date('Y-m-d', strtotime('+5 days'))) . ' a las 4:00 PM', 'Salon de prueba', 'Pastel 3 pisos x1, Mesa de dulces x1']);
            }
            flash($ok ? 'ok' : 'error', $ok ? 'Mensaje de prueba enviado a +' . $tel . '.' : 'No se pudo enviar: ' . $err);
        }
    }
    redirect('whatsapp.php');
}

$envios = db_all('SELECT w.*, e.nombre AS empresa, c.correlativo, e.prefijo FROM whatsapp_envios w
    JOIN empresas e ON e.id = w.empresa_id JOIN cotizaciones c ON c.id = w.cotizacion_id
    ORDER BY COALESCE(w.updated_at, w.created_at) DESC LIMIT 50');
$totales = db_one("SELECT COALESCE(SUM(estado = 'enviado'), 0) AS enviados, COALESCE(SUM(estado = 'error'), 0) AS errores,
    COALESCE(SUM(estado = 'enviado' AND created_at >= ?), 0) AS mes FROM whatsapp_envios", [date('Y-m-01')]);

$checks = [
    ['ID del numero (phone_number_id)', $c['phone_number_id'] !== ''],
    ['Token de acceso', $c['token'] !== ''],
    ['Envio automatico activo (whatsapp.activo)', $c['activo']],
];

$titulo = 'WhatsApp';
$activo = 'whatsapp';
require __DIR__ . '/includes/header.php';
?>
<div class="mx-auto grid max-w-5xl grid-cols-1 gap-5 lg:grid-cols-3">
  <div class="min-w-0 space-y-5 lg:col-span-2">
    <section class="card p-5">
      <h2 class="mb-3 text-base font-bold text-slate-900">Estado de la configuracion</h2>
      <ul class="space-y-2 text-sm">
        <?php foreach ($checks as $ch): ?>
          <li class="flex items-center gap-2">
            <i data-lucide="<?= $ch[1] ? 'check-circle-2' : 'circle-dashed' ?>" class="h-5 w-5 <?= $ch[1] ? 'text-emerald-600' : 'text-slate-400' ?>"></i>
            <span class="<?= $ch[1] ? 'text-slate-800' : 'text-slate-500' ?>"><?= e($ch[0]) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
      <p class="mt-3 text-xs text-slate-500">Estos datos se colocan en <code>config/config.php</code>, seccion <code>whatsapp</code>. Recordatorios: <?= e(implode(' y ', array_map(function ($d) { return wa_etiqueta_tipo($d . 'dias'); }, $c['dias']))) ?> del evento, al cliente y a cada proveedor. Idioma de plantillas: <code><?= e($c['idioma']) ?></code>.</p>
    </section>

    <section class="card p-5">
      <h2 class="mb-1 text-base font-bold text-slate-900">Plantillas a crear en Meta</h2>
      <p class="mb-4 text-xs text-slate-500">WhatsApp Manager &gt; Plantillas de mensajes &gt; Crear. Categoria <strong>Utilidad</strong>, idioma <strong>Espanol</strong>. Copia el nombre y el texto exactamente.</p>
      <?php
      $plantillas = [
          [$c['plantilla_cliente'], 'Hola {{1}}, te saluda {{2}}. Te recordamos que tu evento es el {{3}} en {{4}}. Saldo pendiente: {{5}}. Cualquier consulta, responde a este mensaje.', ['Maria Perez', 'Eventos Dona Neca', '20 de noviembre de 2026 a las 4:00 PM', 'Salon Las Palmas', 'Q 3,000.00']],
          [$c['plantilla_proveedor'], 'Hola {{1}}, te saluda {{2}}. Te recordamos el evento del {{3}} en {{4}}. Productos a entregar: {{5}}. Por favor confirma tu disponibilidad respondiendo este mensaje.', ['Ana', 'Eventos Dona Neca', '20 de noviembre de 2026 a las 4:00 PM', 'Salon Las Palmas', 'Pastel 3 pisos x1']],
      ];
      foreach ($plantillas as $pl): ?>
        <div class="mb-4 rounded-xl border border-slate-200 p-4 last:mb-0">
          <div class="flex items-center justify-between gap-2">
            <code class="text-sm font-bold text-slate-900"><?= e($pl[0]) ?></code>
            <button type="button" class="text-xs font-semibold texto-marca hover:underline" onclick="navigator.clipboard && navigator.clipboard.writeText(this.closest('div').nextElementSibling.textContent.trim()); this.textContent='Copiado'">Copiar texto</button>
          </div>
          <p class="mt-2 rounded-lg bg-slate-50 p-3 text-sm text-slate-700"><?= e($pl[1]) ?></p>
          <p class="mt-2 text-xs text-slate-500">Ejemplos de variables (Meta los pide al crear): <?= e(implode(' | ', array_map(function ($v, $i) { return '{{' . ($i + 1) . '}} ' . $v; }, $pl[2], array_keys($pl[2])))) ?></p>
        </div>
      <?php endforeach; ?>
    </section>

    <section class="card overflow-hidden">
      <h2 class="border-b border-slate-100 px-5 py-4 text-base font-bold text-slate-900">Ultimos envios</h2>
      <?php if (!$envios): ?>
        <p class="px-5 py-8 text-center text-sm text-slate-400">Aun no se han enviado recordatorios.</p>
      <?php else: ?>
        <table class="tabla tabla-movil">
          <thead><tr><th>Evento</th><th>Destinatario</th><th>Recordatorio</th><th>Estado</th></tr></thead>
          <tbody>
            <?php foreach ($envios as $w): ?>
              <tr>
                <td class="td-principal"><span class="font-semibold"><?= e($w['prefijo'] . '-' . str_pad($w['correlativo'], 5, '0', STR_PAD_LEFT)) ?></span> <span class="text-xs text-slate-500"><?= e($w['empresa']) ?></span></td>
                <td data-label="Destinatario"><?= $w['destinatario_tipo'] === 'cliente' ? 'Cliente' : 'Proveedor' ?> · +<?= e($w['telefono']) ?></td>
                <td data-label="Recordatorio"><?= e(wa_etiqueta_tipo($w['tipo'])) ?> · <?= e(fecha($w['updated_at'] ?: $w['created_at'], true)) ?></td>
                <td data-label="Estado">
                  <?= $w['estado'] === 'enviado' ? '<span class="font-semibold text-emerald-600">Enviado</span>' : '<span class="font-semibold text-rose-600">Error</span><span class="block max-w-xs truncate text-xs text-slate-500" title="' . e($w['error']) . '">' . e($w['error']) . '</span>' ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>
  </div>

  <aside class="min-w-0 space-y-5">
    <section class="card p-5">
      <h2 class="mb-3 text-base font-bold text-slate-900">Enviar prueba</h2>
      <?php if (!wa_configurado()): ?>
        <p class="text-sm text-slate-500">Disponible cuando esten el ID del numero y el token.</p>
      <?php else: ?>
        <form method="post" class="space-y-3">
          <?= csrf_field() ?><input type="hidden" name="accion" value="prueba">
          <label class="campo"><span>Numero</span><input class="input" name="telefono" inputmode="tel" placeholder="5555 1234" required></label>
          <label class="campo"><span>Plantilla</span>
            <select class="select" name="plantilla">
              <option value="hello_world">hello_world (viene con la cuenta)</option>
              <option value="cliente"><?= e($c['plantilla_cliente']) ?></option>
              <option value="proveedor"><?= e($c['plantilla_proveedor']) ?></option>
            </select>
          </label>
          <button class="btn btn-exito w-full"><i data-lucide="send"></i>Enviar prueba</button>
        </form>
      <?php endif; ?>
    </section>
    <section class="card p-5 text-sm">
      <h2 class="mb-3 text-base font-bold text-slate-900">Resumen</h2>
      <div class="space-y-1.5">
        <div class="flex justify-between"><span class="text-slate-500">Enviados este mes</span><strong><?= (int)$totales['mes'] ?></strong></div>
        <div class="flex justify-between"><span class="text-slate-500">Enviados en total</span><strong><?= (int)$totales['enviados'] ?></strong></div>
        <div class="flex justify-between"><span class="text-slate-500">Con error</span><strong class="<?= $totales['errores'] ? 'text-rose-600' : '' ?>"><?= (int)$totales['errores'] ?></strong></div>
        <div class="flex justify-between border-t border-slate-100 pt-1.5"><span class="text-slate-500">Costo aprox. del mes</span><strong>US$ <?= number_format($totales['mes'] * 0.013, 2) ?></strong></div>
      </div>
      <p class="mt-2 text-[11px] text-slate-400">Calculado con US$0.013 por mensaje de utilidad (tarifa de Meta para Guatemala).</p>
    </section>
  </aside>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
