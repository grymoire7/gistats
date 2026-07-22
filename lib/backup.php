<?php
// lib/backup.php

function create_backup(string $dbPath): string
{
    $tempPath    = sys_get_temp_dir() . '/gistats_backup_' . bin2hex(random_bytes(8)) . '.sqlite';
    $escapedPath = str_replace("'", "''", $tempPath);
    DB::execute("VACUUM INTO '{$escapedPath}'");
    return $tempPath;
}

function validate_sqlite_upload(string $tmpPath): ?string
{
    $handle = fopen($tmpPath, 'rb');
    if ($handle === false) {
        return 'Unable to read uploaded file.';
    }
    $header = fread($handle, 16);
    fclose($handle);
    if ($header !== "SQLite format 3\0") {
        return 'The uploaded file is not a valid SQLite database.';
    }

    try {
        $testPdo = new PDO('sqlite:' . $tmpPath);
        $testPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $testPdo->query('SELECT COUNT(*) FROM users')->fetch();
    } catch (\PDOException $e) {
        return 'The uploaded file does not match the expected database schema.';
    }

    return null;
}

function restore_from_upload(string $dbPath, string $uploadedTmpPath): bool
{
    DB::reset();

    $walPath = $dbPath . '-wal';
    $shmPath = $dbPath . '-shm';
    if (file_exists($walPath)) {
        unlink($walPath);
    }
    if (file_exists($shmPath)) {
        unlink($shmPath);
    }

    $swapPath = dirname($dbPath) . '/.' . basename($dbPath) . '.restoring';
    if (!copy($uploadedTmpPath, $swapPath)) {
        @unlink($swapPath);
        return false;
    }
    if (!rename($swapPath, $dbPath)) {
        @unlink($swapPath);
        return false;
    }
    return true;
}
