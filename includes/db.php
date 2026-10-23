<?php
/**
 * Conexion PDO unica.
 */
function db()
{
    static $pdo = null;
    if ($pdo === null) {
        $c = $GLOBALS['CONFIG']['db'];
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $c['host'], $c['name'], $c['charset'] ?? 'utf8mb4');
        try {
            $pdo = new PDO($dsn, $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            $pdo->exec("SET time_zone = '" . date('P') . "'");
        } catch (PDOException $e) {
            http_response_code(500);
            if (!empty($GLOBALS['CONFIG']['app']['debug'])) {
                exit('Error de conexion: ' . $e->getMessage());
            }
            exit('No se pudo conectar a la base de datos.');
        }
    }
    return $pdo;
}

function db_all($sql, array $params = [])
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function db_one($sql, array $params = [])
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

function db_val($sql, array $params = [])
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $v = $st->fetchColumn();
    return $v === false ? null : $v;
}

function db_exec($sql, array $params = [])
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}
