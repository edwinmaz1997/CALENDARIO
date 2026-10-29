<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/empresa_form.php';
require_empresa();

$emp = empresa_actual();
$errores = [];
$secciones = mi_empresa_secciones();
$seccion = input('s', 'datos');
if (!isset($secciones[$seccion]) || $seccion === 'descuentos') {
    $seccion = 'datos';
}

if (is_post()) {
    csrf_check();
    list(, $errores) = guardar_empresa((int)$emp['id'], $emp);
    if (!$errores) {
        flash('ok', 'Datos de la empresa actualizados.');
        redirect('mi_empresa.php?s=' . $seccion);
    }
}

$titulo = 'Mi empresa';
$activo = 'mi_empresa_' . $seccion;
require __DIR__ . '/includes/header.php';
render_tabs_mi_empresa($seccion);
render_form_empresa($emp, 'mi_empresa.php?s=' . $seccion, 'index.php', $errores, $seccion);
require __DIR__ . '/includes/footer.php';
