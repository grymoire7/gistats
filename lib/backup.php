<?php
// lib/backup.php

function create_backup(string $dbPath): string
{
    $tempPath    = sys_get_temp_dir() . '/gistats_backup_' . bin2hex(random_bytes(8)) . '.sqlite';
    $escapedPath = str_replace("'", "''", $tempPath);
    DB::execute("VACUUM INTO '{$escapedPath}'");
    return $tempPath;
}
