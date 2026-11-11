<?php
declare(strict_types=1);

return [
    'db_host' => getenv('HOTEL_DB_HOST') ?: '127.0.0.1',
    'db_port' => getenv('HOTEL_DB_PORT') ?: '3306',
    'db_name' => getenv('HOTEL_DB_NAME') ?: 'hotel_management',
    'db_user' => getenv('HOTEL_DB_USER') ?: 'root',
    'db_password' => getenv('HOTEL_DB_PASSWORD') ?: '',
];
