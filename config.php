<?php
return [
    'db_path'  => getenv('GISTATS_DB_PATH') ?: __DIR__ . '/database.sqlite',
    'timezone' => 'America/Chicago',
    'base_url' => '',
];
