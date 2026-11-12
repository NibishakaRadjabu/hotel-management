<?php
declare(strict_types=1);

return [
    'db_host' => getenv('HOTEL_DB_HOST') ?: '127.0.0.1',
    'db_port' => getenv('HOTEL_DB_PORT') ?: '3306',
    'db_name' => getenv('HOTEL_DB_NAME') ?: 'hotel_management',
    'db_user' => getenv('HOTEL_DB_USER') ?: 'root',
    'db_password' => getenv('HOTEL_DB_PASSWORD') ?: '',
    'app_url' => rtrim(getenv('HOTEL_APP_URL') ?: 'http://localhost/hotel%20management', '/'),
    'paypal_client_id' => getenv('PAYPAL_CLIENT_ID') ?: '',
    'paypal_client_secret' => getenv('PAYPAL_CLIENT_SECRET') ?: '',
    'paypal_receiver_email' => getenv('PAYPAL_RECEIVER_EMAIL') ?: '',
    'paypal_currency' => strtoupper(getenv('PAYPAL_CURRENCY') ?: ''),
    'paypal_rwf_per_unit' => getenv('PAYPAL_RWF_PER_UNIT') ?: '',
    'paypal_mode' => strtolower(getenv('PAYPAL_MODE') ?: 'sandbox'),
    'paypal_settings_encryption_key' => getenv('PAYPAL_SETTINGS_ENCRYPTION_KEY') ?: '',
    'hotel_payment_test_mode' => getenv('HOTEL_PAYMENT_TEST_MODE') === '1',
];
