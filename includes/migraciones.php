<?php
/**
 * Aplica automaticamente los archivos de sql/migraciones/ que falten.
 *
 * - Se registra cada migracion aplicada en la tabla schema_migraciones.
 * - Para no consultar la base en cada visita, la ultima version aplicada se guarda en
 *   uploads/.schema_version; solo si cambia se revisa la base.
 * - Si una sentencia falla porque el cambio ya existia (tabla, columna, indice o llave duplicada,
 *   por ejemplo porque se corrio a mano en phpMyAdmin) se ignora y la migracion se marca como aplicada.
 */

function migraciones_archivos()
{
    $lista = glob(APP_ROOT . '/sql/migraciones/*.sql') ?: [];
    sort($lista, SORT_STRING);
    return $lista;
}

function migraciones_marca()
{
    return APP_ROOT . '/uploads/.schema_version';
}

/** Divide un archivo .sql en sentencias (sin comentarios de linea) */
function migraciones_sentencias($sql)
{
    $lineas = [];
    foreach (preg_split('/\R/', $sql) as $l) {
        $t = ltrim($l);
        if ($t === '' || strpos($t, '--') === 0 || strpos($t, '#') === 0) {
            continue;
        }
        $lineas[] = $l;
    }
    $partes = preg_split('/;\s*(\R|$)/', implode("\n", $lineas));
    return array_values(array_filter(array_map('trim', $partes), 'strlen'));
}

function migraciones_error_ignorable(PDOException $e)
{
    $codigo = (int)($e->errorInfo[1] ?? 0);
    // 1050 tabla existe, 1060 columna duplicada, 1061 indice duplicado, 1022/1826 llave duplicada,
    // 1091 no se puede borrar (no existe), 1005 errno 121 (llave foranea duplicada en MariaDB)
    if (in_array($codigo, [1050, 1060, 1061, 1022, 1826, 1091], true)) {
        return true;
    }
    return $codigo === 1005 && strpos($e->getMessage(), '121') !== false;
}

/**
 * Revisa y aplica migraciones pendientes. Devuelve lista de nombres aplicados.
 */
function aplicar_migraciones($forzar = false)
{
    $archivos = migraciones_archivos();
    if (!$archivos) {
        return [];
    }
    $ultima = basename(end($archivos));
    $marca = migraciones_marca();
    if (!$forzar && is_file($marca) && trim((string)@file_get_contents($marca)) === $ultima) {
        return [];
    }

    $pdo = db();
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migraciones (
        nombre VARCHAR(150) NOT NULL PRIMARY KEY,
        aplicada_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    // Evita que dos visitas simultaneas apliquen lo mismo
    $lock = (int)db_val("SELECT GET_LOCK('cotizador_migraciones', 20)");
    $aplicadas = [];
    try {
        $hechas = array_flip(array_column(db_all('SELECT nombre FROM schema_migraciones'), 'nombre'));
        foreach ($archivos as $archivo) {
            $nombre = basename($archivo);
            if (isset($hechas[$nombre])) {
                continue;
            }
            foreach (migraciones_sentencias((string)file_get_contents($archivo)) as $sentencia) {
                try {
                    $pdo->exec($sentencia);
                } catch (PDOException $e) {
                    if (!migraciones_error_ignorable($e)) {
                        throw new RuntimeException('Error en la migracion ' . $nombre . ': ' . $e->getMessage(), 0, $e);
                    }
                }
            }
            db_exec('INSERT IGNORE INTO schema_migraciones (nombre) VALUES (?)', [$nombre]);
            $aplicadas[] = $nombre;
        }
        @file_put_contents($marca, $ultima);
    } finally {
        if ($lock) {
            db_val("SELECT RELEASE_LOCK('cotizador_migraciones')");
        }
    }
    return $aplicadas;
}
