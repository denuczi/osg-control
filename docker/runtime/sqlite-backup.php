<?php
// One-off consistent snapshot, never directly copy WAL-backed database file.
declare(strict_types=1);
$target = $argv[1] ?? '';
if (! preg_match('~^/data/sqlite/\.backup-[A-Za-z0-9-]+\.sqlite$~', $target)) {
    fwrite(STDERR, "Ruta de respaldo inválida\n"); exit(2);
}
if (file_exists($target)) { fwrite(STDERR, "No se sobrescribe un respaldo existente\n"); exit(2); }
$source = new SQLite3('/data/sqlite/database.sqlite', SQLITE3_OPEN_READONLY);
$source->busyTimeout(15000);
$dest = new SQLite3($target, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
$dest->busyTimeout(15000);
if (! $source->backup($dest)) { fwrite(STDERR, "Error de SQLite Online Backup\n"); exit(1); }
$dest->close(); $source->close();
@chmod($target, 0600);
