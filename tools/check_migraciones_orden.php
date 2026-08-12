<?php

// Chequea que ningún archivo en migrate/*.sql haga ALTER TABLE sobre una
// tabla que otro archivo de migrate/ crea, si ese archivo ordena DESPUÉS
// alfabéticamente — el runner real (tauri-shell/src-tauri/src/server_manager.rs
// ::run_migrations(), antes electron/services/server-manager.js) ordena los
// archivos con un sort() lexicográfico simple y los corre en ese orden. Si
// una migración altera una tabla antes de que exista, en una instalación que
// tenga que correrla de verdad (no solo pegar contra "ya existe") falla
// silenciosamente y queda sellada como aplicada sin haber hecho nada — ver
// migrate/75_fix_orden_configuracion.sql para un caso real de este bug.
//
// Uso: php tools/check_migraciones_orden.php
// Exit code 0 si no hay problemas, 1 si encuentra alguno (pensado para correr
// antes de cada publish).
//
// Nota: el sort es lexicográfico, no numérico — "100_x" ordenaría ANTES que
// "99_y" (el '1' de "100" es menor que el '9' de "99"). Mientras la numeración
// se mantenga con 2 dígitos (00-99) esto no importa, pero al pasar de 99 hay
// que repensar el padding (ej. pasar a 3 dígitos desde el arranque) o este
// mismo chequeo va a empezar a marcar falsos positivos por esa razón, no por
// un error real de dependencia.

// Casos ya conocidos y resueltos vía migración de reparación (no vía
// renombrar el archivo original, que rompería el sellado en instalaciones
// ya corriendo) — ver migrate/75_fix_orden_configuracion.sql. Se excluyen acá
// para que el script quede limpio y sirva de gate real contra casos NUEVOS.
$excepcionesConocidas = [
    "11_afip_fantasia.sql altera 'configuracion' pero 12_configuracion.sql (que la crea) corre después",
    "12_color_tema.sql altera 'configuracion' pero 12_configuracion.sql (que la crea) corre después",
];

$migrateDir = __DIR__ . '/../migrate';
$files = array_values(array_filter(scandir($migrateDir), fn($f) => str_ends_with(strtolower($f), '.sql')));
sort($files); // mismo criterio que Vec<String>::sort() en Rust / Array.prototype.sort() en JS para strings ASCII

// Partir en statements por ';' es suficiente acá — no hay DELIMITER custom
// ni BEGIN/END con ';' internos en migrate/*.sql hoy. Si eso cambia en el
// futuro, este split deja de ser confiable.
$statementsPorArchivo = [];
foreach ($files as $file) {
    // Sacar comentarios de línea "-- ..." antes de partir en statements —
    // si no, un archivo que empieza con comentarios (el caso normal) rompe
    // el anclaje ^\s*ALTER/^\s*CREATE del primer statement.
    $sinComentarios = preg_replace('/--.*$/m', '', file_get_contents($migrateDir . '/' . $file));
    $statementsPorArchivo[$file] = preg_split('/;\s*(\r?\n|$)/', $sinComentarios);
}

// Pasada 1: qué archivo crea cada tabla.
$creaEn = []; // tabla => archivo que la crea
foreach ($files as $file) {
    foreach ($statementsPorArchivo[$file] as $stmt) {
        if (preg_match('/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z_]+)`?/i', $stmt, $m)) {
            $tabla = strtolower($m[1]);
            if (!isset($creaEn[$tabla])) {
                $creaEn[$tabla] = $file;
            }
        }
    }
}

// Pasada 2: cada ALTER TABLE tiene que apuntar a una tabla creada por un
// archivo que ordena <= el archivo que la altera (o a una tabla base, sin
// entrada en $creaEn — viene de 00_schema_bron.sql / schema_limpio.sql).
$pos = array_flip($files); // archivo => índice en el orden real de ejecución
$problemas = [];
foreach ($files as $file) {
    foreach ($statementsPorArchivo[$file] as $stmt) {
        if (!preg_match('/^\s*ALTER\s+TABLE\s+`?([a-zA-Z_]+)`?/i', $stmt, $m)) continue;
        $tabla = strtolower($m[1]);
        if (!isset($creaEn[$tabla])) continue;
        $creador = $creaEn[$tabla];
        if ($pos[$creador] > $pos[$file]) {
            $descripcion = "$file altera '$tabla' pero $creador (que la crea) corre después";
            if (!in_array($descripcion, $excepcionesConocidas, true)) {
                $problemas[] = $descripcion;
            }
        }
    }
}

if ($problemas) {
    fwrite(STDERR, "Problemas de orden encontrados:\n");
    foreach ($problemas as $p) {
        fwrite(STDERR, "  - $p\n");
    }
    exit(1);
}

echo "OK — sin problemas de orden entre CREATE TABLE y ALTER TABLE en migrate/*.sql (" . count($files) . " archivos).\n";
exit(0);
