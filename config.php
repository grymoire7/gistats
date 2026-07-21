<?php
$defaults = [
    'db_path'  => getenv('GISTATS_DB_PATH') ?: __DIR__ . '/database.sqlite',
    'timezone' => 'America/Chicago',
    'base_url' => '',
];
if (file_exists(__DIR__ . '/config.local.php')) {
    return array_merge($defaults, require __DIR__ . '/config.local.php');
}
return $defaults;
