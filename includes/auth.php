<?php
/**
 * Autenticacion y empresa activa.
 *
 * - Usuario 'empresa': trabaja siempre con su propia empresa.
 * - Usuario 'admin'  : al ingresar elige con que empresa trabajar y administra empresas/usuarios.
 */

function usuario_actual()
{
    static $u = false;
    if ($u === false) {
        $u = null;
        if (!empty($_SESSION['usuario_id'])) {
            $u = db_one('SELECT id, empresa_id, nombre, usuario, rol, activo FROM usuarios WHERE id = ?', [$_SESSION['usuario_id']]);
            if (!$u || !$u['activo']) {
                $u = null;
                cerrar_sesion();
            }
        }
    }
    return $u;
}

function es_admin()
{
    $u = usuario_actual();
    return $u && $u['rol'] === 'admin';
}

function intentar_login($usuario, $password)
{
    $u = db_one('SELECT * FROM usuarios WHERE usuario = ? LIMIT 1', [$usuario]);
    if (!$u || !password_verify($password, $u['password_hash'])) {
        return 'Usuario o contrasena incorrectos.';
    }
    if (!$u['activo']) {
        return 'El usuario esta desactivado.';
    }
    if ($u['rol'] === 'empresa') {
        $activa = db_val('SELECT activo FROM empresas WHERE id = ?', [$u['empresa_id']]);
        if (!$activa) {
            return 'La empresa asociada a este usuario esta desactivada.';
        }
    }
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        db_exec('UPDATE usuarios SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
    }
    session_regenerate_id(true);
    $_SESSION['usuario_id'] = (int)$u['id'];
    $_SESSION['empresa_id'] = $u['rol'] === 'empresa' ? (int)$u['empresa_id'] : null;
    db_exec('UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = ?', [$u['id']]);
    return null;
}

function cerrar_sesion()
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function require_login()
{
    if (!usuario_actual()) {
        redirect('login.php');
    }
}

function require_admin()
{
    require_login();
    if (!es_admin()) {
        flash('error', 'No tienes permiso para acceder a esa seccion.');
        redirect('index.php');
    }
}

/** Empresa con la que se esta trabajando (o null si el admin aun no elige) */
function empresa_actual()
{
    static $emp = false;
    if ($emp === false) {
        $emp = null;
        $u = usuario_actual();
        if ($u) {
            $id = $u['rol'] === 'empresa' ? (int)$u['empresa_id'] : (int)($_SESSION['empresa_id'] ?? 0);
            if ($id) {
                $emp = db_one('SELECT * FROM empresas WHERE id = ? AND activo = 1', [$id]);
                if (!$emp && $u['rol'] === 'admin') {
                    $_SESSION['empresa_id'] = null;
                }
            }
        }
    }
    return $emp;
}

function empresa_id()
{
    $e = empresa_actual();
    return $e ? (int)$e['id'] : 0;
}

/** Exige sesion y una empresa activa seleccionada */
function require_empresa()
{
    require_login();
    if (!empresa_actual()) {
        if (es_admin()) {
            redirect('seleccionar_empresa.php');
        }
        cerrar_sesion();
        redirect('login.php');
    }
}
