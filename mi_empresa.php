<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/empresa_form.php';
require_empresa();

$emp = empresa_actual();
$errores = [];

if (is_post()) {
    csrf_check();
    list(, $errores) = guardar_empresa((int)$emp['id'], $emp);
    if (!$errores) {
        flash('ok', 'Datos de la empresa actualizados.');
        redirect('mi_empresa.php');
    }
}

$titulo = 'Mi empresa';
$activo = 'mi_empresa';
require __DIR__ . '/includes/header.php';
render_form_empresa($emp, 'mi_empresa.php', 'index.php', $errores);
require __DIR__ . '/includes/footer.php';
