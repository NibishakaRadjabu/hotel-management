<?php
declare(strict_types=1);

const PAYPAL_AES_GCM_KEY_BYTES = 32;
const PAYPAL_AES_GCM_IV_BYTES = 12;
const PAYPAL_AES_GCM_TAG_BYTES = 16;

function payment_simulation_enabled(): bool
{
    global $config;
    if (!$config['hotel_payment_test_mode']) {
        return false;
    }

    $appUrl = parse_url($config['app_url']);
    $requestHost = parse_url('http://' . (string) ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
    $localHosts = ['localhost', '127.0.0.1', '::1'];

    return is_array($appUrl)
        && ($appUrl['scheme'] ?? '') === 'http'
        && in_array(strtolower((string) ($appUrl['host'] ?? '')), $localHosts, true)
        && is_string($requestHost)
        && in_array(strtolower($requestHost), $localHosts, true)
        && (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === '' || strtolower((string) $_SERVER['HTTPS']) === 'off');
}

function paypal_settings_encryption_key(): string
{
    global $config;
    if (!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) {
        throw new RuntimeException('The PHP OpenSSL extension is required for encrypted PayPal settings.');
    }
    $encodedKey = $config['paypal_settings_encryption_key'];
    $key = is_string($encodedKey) ? base64_decode($encodedKey, true) : false;
    if (!is_string($key) || strlen($key) !== PAYPAL_AES_GCM_KEY_BYTES) {
        throw new RuntimeException('Set PAYPAL_SETTINGS_ENCRYPTION_KEY to a base64-encoded 32-byte key.');
    }

    return $key;
}

function paypal_encrypt_secret(string $secret): string
{
    $iv = random_bytes(PAYPAL_AES_GCM_IV_BYTES);
    $tag = '';
    $ciphertext = openssl_encrypt(
        $secret,
        'aes-256-gcm',
        paypal_settings_encryption_key(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );
    if (!is_string($ciphertext) || strlen($tag) !== PAYPAL_AES_GCM_TAG_BYTES) {
        throw new RuntimeException('Unable to encrypt the PayPal Secret.');
    }

    return base64_encode($iv . $tag . $ciphertext);
}

function paypal_decrypt_secret(string $encryptedSecret): string
{
    $decoded = base64_decode($encryptedSecret, true);
    $minimumLength = PAYPAL_AES_GCM_IV_BYTES + PAYPAL_AES_GCM_TAG_BYTES + 1;
    if (!is_string($decoded) || strlen($decoded) < $minimumLength) {
        throw new RuntimeException('Stored PayPal credentials are invalid.');
    }
    $iv = substr($decoded, 0, PAYPAL_AES_GCM_IV_BYTES);
    $tag = substr($decoded, PAYPAL_AES_GCM_IV_BYTES, PAYPAL_AES_GCM_TAG_BYTES);
    $ciphertext = substr($decoded, PAYPAL_AES_GCM_IV_BYTES + PAYPAL_AES_GCM_TAG_BYTES);
    $secret = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        paypal_settings_encryption_key(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );
    if (!is_string($secret)) {
        throw new RuntimeException('Stored PayPal credentials could not be decrypted.');
    }

    return $secret;
}

function paypal_saved_settings(): ?array
{
    global $pdo;
    $statement = $pdo->query(
        'SELECT client_id, encrypted_secret, receiver_email, currency, rwf_per_unit, mode
         FROM paypal_settings WHERE id = 1'
    );
    $settings = $statement->fetch();

    return $settings ?: null;
}

function paypal_apply_saved_settings(): void
{
    global $config;
    $settings = paypal_saved_settings();
    if ($settings === null) {
        return;
    }

    try {
        $config['paypal_client_id'] = $settings['client_id'];
        $config['paypal_client_secret'] = paypal_decrypt_secret($settings['encrypted_secret']);
        $config['paypal_receiver_email'] = $settings['receiver_email'];
        $config['paypal_currency'] = $settings['currency'];
        $config['paypal_rwf_per_unit'] = $settings['rwf_per_unit'];
        $config['paypal_mode'] = $settings['mode'];
        $config['paypal_setup_error'] = '';
    } catch (RuntimeException $exception) {
        $config['paypal_client_id'] = '';
        $config['paypal_client_secret'] = '';
        $config['paypal_setup_error'] = $exception->getMessage();
        error_log('SmartHotel PayPal settings could not be loaded: ' . $exception->getMessage());
    }
}

function paypal_configured(): bool
{
    global $config;
    $appUrl = parse_url($config['app_url']);
    $currency = $config['paypal_currency'];
    $supportedCurrencies = ['AUD', 'CAD', 'CHF', 'EUR', 'GBP', 'HKD', 'NZD', 'SGD', 'USD'];

    return function_exists('curl_init')
        && $config['paypal_client_id'] !== ''
        && $config['paypal_client_secret'] !== ''
        && filter_var($config['paypal_receiver_email'], FILTER_VALIDATE_EMAIL) !== false
        && in_array($currency, $supportedCurrencies, true)
        && is_numeric($config['paypal_rwf_per_unit'])
        && (float) $config['paypal_rwf_per_unit'] > 0
        && in_array($config['paypal_mode'], ['sandbox', 'live'], true)
        && is_array($appUrl)
        && ($appUrl['scheme'] ?? '') === 'https'
        && isset($appUrl['host'])
        && strtolower($appUrl['host']) !== 'localhost';
}

function paypal_api_base_url(): string
{
    global $config;
    return $config['paypal_mode'] === 'live'
        ? 'https://api-m.paypal.com'
        : 'https://api-m.sandbox.paypal.com';
}

function paypal_http_json(string $url, array $options): array
{
    $handle = curl_init($url);
    if ($handle === false) {
        throw new RuntimeException('Unable to initialize the PayPal request.');
    }

    curl_setopt_array($handle, $options + [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    ]);
    try {
        $body = curl_exec($handle);
        $curlError = curl_error($handle);
        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    } finally {
        curl_close($handle);
    }

    if (!is_string($body)) {
        throw new RuntimeException('PayPal request failed: ' . $curlError);
    }
    $response = json_decode($body, true);
    if (!is_array($response)) {
        throw new RuntimeException('PayPal returned an invalid response.');
    }
    if ($statusCode < 200 || $statusCode >= 300) {
        $description = is_string($response['message'] ?? null)
            ? $response['message']
            : (is_string($response['name'] ?? null) ? $response['name'] : 'Unknown provider error.');
        throw new RuntimeException('PayPal request rejected: ' . $description);
    }

    return $response;
}

function paypal_access_token(): string
{
    global $config;
    static $accessToken = null;
    if (is_string($accessToken)) {
        return $accessToken;
    }

    $response = paypal_http_json(paypal_api_base_url() . '/v1/oauth2/token', [
        CURLOPT_POST => true,
        CURLOPT_USERPWD => $config['paypal_client_id'] . ':' . $config['paypal_client_secret'],
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Accept-Language: en_US',
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
    ]);
    $accessToken = $response['access_token'] ?? null;
    if (!is_string($accessToken) || $accessToken === '') {
        throw new RuntimeException('PayPal did not provide an access token.');
    }

    return $accessToken;
}

function paypal_api_request(string $method, string $path, ?array $payload = null, ?string $requestId = null): array
{
    if (!paypal_configured()) {
        throw new RuntimeException('PayPal checkout is not fully configured.');
    }

    $headers = [
        'Authorization: Bearer ' . paypal_access_token(),
        'Accept: application/json',
        'Content-Type: application/json',
    ];
    if ($requestId !== null) {
        $headers[] = 'PayPal-Request-Id: ' . $requestId;
    }

    $options = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($payload !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($payload === [] ? (object) [] : $payload, JSON_THROW_ON_ERROR);
    }

    return paypal_http_json(paypal_api_base_url() . $path, $options);
}

function paypal_format_amount(float $amount): string
{
    return number_format($amount, 2, '.', '');
}

function expire_online_booking_payments(): void
{
    global $pdo;
    $pdo->beginTransaction();
    try {
        $expireAttempts = $pdo->prepare(
            "UPDATE online_payment_attempts a
             JOIN bookings b ON b.id = a.booking_id
             SET a.status = 'failed'
             WHERE a.status = 'initiated' AND b.booking_source = 'online'
               AND b.status = 'pending' AND b.payment_deadline_at <= NOW()"
        );
        $expireAttempts->execute();
        $expireManualPayments = $pdo->prepare(
            "UPDATE manual_payment_submissions m
             JOIN bookings b ON b.id = m.booking_id
             SET m.status = 'expired', m.reviewed_at = NOW()
             WHERE m.status = 'pending' AND b.booking_source = 'online'
               AND b.status = 'pending' AND b.payment_deadline_at <= NOW()"
        );
        $expireManualPayments->execute();
        $expireBookings = $pdo->prepare(
            "UPDATE bookings SET status = 'cancelled'
             WHERE booking_source = 'online' AND status = 'pending'
               AND payment_deadline_at <= NOW()"
        );
        $expireBookings->execute();
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function notify_public_booking_paid(int $bookingId): void
{
    global $pdo;
    $staff = $pdo->query(
        "SELECT id FROM users WHERE is_active = 1 AND role IN ('admin', 'manager', 'receptionist')"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($staff as $staffId) {
        create_notification((int) $staffId, 'public_booking_paid', 'Booking #' . $bookingId);
    }
}

function notify_public_payment_review(int $bookingId): void
{
    global $pdo;
    $staff = $pdo->query(
        "SELECT id FROM users WHERE is_active = 1 AND role IN ('admin', 'manager', 'receptionist')"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($staff as $staffId) {
        create_notification((int) $staffId, 'public_payment_review', 'Booking #' . $bookingId);
    }
}

function mark_paypal_payment_for_review(int $attemptId, ?string $captureId): void
{
    global $pdo;
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare(
            'SELECT a.status, a.booking_id FROM online_payment_attempts a WHERE a.id = ? FOR UPDATE'
        );
        $lock->execute([$attemptId]);
        $attempt = $lock->fetch();
        if (!$attempt) {
            throw new RuntimeException('Payment attempt disappeared during review.');
        }
        if (!in_array($attempt['status'], ['successful', 'needs_review'], true)) {
            $update = $pdo->prepare(
                "UPDATE online_payment_attempts SET status = 'needs_review', gateway_transaction_id = ?
                 WHERE id = ?"
            );
            $update->execute([$captureId, $attemptId]);
            notify_public_payment_review((int) $attempt['booking_id']);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function paypal_create_order(
    string $transactionReference,
    int $bookingId,
    string $amount,
    string $returnUrl,
    string $cancelUrl
): array {
    global $config;
    return paypal_api_request('POST', '/v2/checkout/orders', [
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'reference_id' => (string) $bookingId,
            'custom_id' => $transactionReference,
            'invoice_id' => $transactionReference,
            'description' => '50% advance payment for SmartHotel booking #' . $bookingId,
            'amount' => [
                'currency_code' => $config['paypal_currency'],
                'value' => $amount,
            ],
            'payee' => [
                'email_address' => $config['paypal_receiver_email'],
            ],
        ]],
        'application_context' => [
            'brand_name' => 'SmartHotel',
            'shipping_preference' => 'NO_SHIPPING',
            'user_action' => 'PAY_NOW',
            'return_url' => $returnUrl,
            'cancel_url' => $cancelUrl,
        ],
    ], $transactionReference);
}

function paypal_get_completed_order(string $orderId, string $transactionReference): array
{
    $encodedOrderId = rawurlencode($orderId);
    $captureRequestId = 'SHC-' . substr(hash('sha256', $transactionReference), 0, 32);
    try {
        $capturedOrder = paypal_api_request(
            'POST',
            '/v2/checkout/orders/' . $encodedOrderId . '/capture',
            [],
            $captureRequestId
        );
        if (($capturedOrder['status'] ?? '') === 'COMPLETED') {
            return $capturedOrder;
        }
    } catch (Throwable $captureException) {
        $order = paypal_api_request('GET', '/v2/checkout/orders/' . $encodedOrderId);
        if (($order['status'] ?? '') !== 'COMPLETED') {
            throw $captureException;
        }

        return $order;
    }

    $order = paypal_api_request('GET', '/v2/checkout/orders/' . $encodedOrderId);
    if (($order['status'] ?? '') !== 'COMPLETED') {
        throw new RuntimeException('PayPal did not complete the order capture.');
    }

    return $order;
}

function complete_paypal_booking_payment(string $orderId): string
{
    global $config, $pdo;
    if (!preg_match('/\A[A-Za-z0-9-]{8,40}\z/', $orderId)) {
        throw new InvalidArgumentException('Invalid PayPal order ID.');
    }

    $attemptQuery = $pdo->prepare(
        'SELECT a.id, a.booking_id, a.tx_ref, a.expected_amount, a.expected_amount_rwf, a.currency,
                a.status, a.gateway_transaction_id, a.gateway_order_id,
                b.status AS booking_status, b.booking_source, b.payment_deadline_at
         FROM online_payment_attempts a
         JOIN bookings b ON b.id = a.booking_id
         WHERE a.gateway_order_id = ?'
    );
    $attemptQuery->execute([$orderId]);
    $attempt = $attemptQuery->fetch();
    if (!$attempt) {
        throw new RuntimeException('PayPal order was not found.');
    }
    if ($attempt['status'] === 'successful') {
        return 'confirmed';
    }
    if ($attempt['status'] === 'needs_review') {
        return 'review';
    }
    if (!is_numeric($attempt['expected_amount_rwf']) || !$attempt['currency']) {
        throw new RuntimeException('The payment attempt is missing its currency configuration.');
    }

    $order = paypal_get_completed_order($orderId, $attempt['tx_ref']);
    $purchaseUnits = $order['purchase_units'] ?? null;
    $unit = is_array($purchaseUnits) && count($purchaseUnits) === 1 ? reset($purchaseUnits) : null;
    $captures = is_array($unit) ? ($unit['payments']['captures'] ?? null) : null;
    $capture = is_array($captures) && count($captures) === 1 ? reset($captures) : null;
    $paidAmount = is_array($capture) ? ($capture['amount']['value'] ?? null) : null;
    $paidCurrency = is_array($capture) ? ($capture['amount']['currency_code'] ?? null) : null;
    $receiver = is_array($unit) ? ($unit['payee']['email_address'] ?? '') : '';
    $captureId = is_array($capture) ? ($capture['id'] ?? '') : '';

    if (($order['id'] ?? '') !== $orderId || ($order['status'] ?? '') !== 'COMPLETED') {
        throw new RuntimeException('PayPal capture details did not match the booking.');
    }
    $captureId = is_string($captureId) && preg_match('/\A[A-Za-z0-9-]{1,40}\z/', $captureId)
        ? $captureId
        : null;
    $captureMatches = is_array($unit)
        && hash_equals($attempt['tx_ref'], (string) ($unit['custom_id'] ?? ''))
        && is_array($capture)
        && ($capture['status'] ?? '') === 'COMPLETED'
        && $captureId !== null
        && is_numeric($paidAmount)
        && strtoupper((string) $paidCurrency) === $attempt['currency']
        && paypal_format_amount((float) $paidAmount) === paypal_format_amount((float) $attempt['expected_amount'])
        && is_string($receiver)
        && hash_equals(strtolower($config['paypal_receiver_email']), strtolower($receiver));
    if (!$captureMatches) {
        mark_paypal_payment_for_review((int) $attempt['id'], $captureId);
        return 'review';
    }

    $pdo->beginTransaction();
    try {
        $lockAttempt = $pdo->prepare(
            'SELECT a.status, a.booking_id, a.tx_ref, a.expected_amount, a.expected_amount_rwf,
                    a.currency, a.gateway_order_id,
                    b.status AS booking_status, b.booking_source, b.payment_deadline_at
             FROM online_payment_attempts a
             JOIN bookings b ON b.id = a.booking_id
             WHERE a.id = ? FOR UPDATE'
        );
        $lockAttempt->execute([$attempt['id']]);
        $locked = $lockAttempt->fetch();
        if (!$locked) {
            throw new RuntimeException('Payment attempt disappeared during verification.');
        }
        if (in_array($locked['status'], ['successful', 'needs_review'], true)) {
            $pdo->commit();
            return $locked['status'] === 'successful' ? 'confirmed' : 'review';
        }
        if ($locked['gateway_order_id'] !== $orderId
            || $locked['tx_ref'] !== $attempt['tx_ref']
            || $locked['currency'] !== $attempt['currency']
            || paypal_format_amount((float) $locked['expected_amount']) !== paypal_format_amount((float) $paidAmount)) {
            throw new RuntimeException('The saved payment details changed during verification.');
        }

        $payment = $pdo->prepare(
            "INSERT INTO payments
             (booking_id, amount, payment_method, gateway_reference, gateway_amount, gateway_currency)
             VALUES (?, ?, 'paypal', ?, ?, ?)"
        );
        $payment->execute([
            $locked['booking_id'],
            $locked['expected_amount_rwf'],
            $captureId,
            paypal_format_amount((float) $paidAmount),
            $paidCurrency,
        ]);

        $bookingIsReserved = $locked['booking_source'] === 'online'
            && $locked['booking_status'] === 'pending'
            && $locked['payment_deadline_at'] !== null
            && strtotime((string) $locked['payment_deadline_at']) > time();
        $newAttemptStatus = $bookingIsReserved ? 'successful' : 'needs_review';
        $updateAttempt = $pdo->prepare(
            'UPDATE online_payment_attempts SET status = ?, gateway_transaction_id = ? WHERE id = ?'
        );
        $updateAttempt->execute([$newAttemptStatus, $captureId, $attempt['id']]);

        if ($bookingIsReserved) {
            $confirm = $pdo->prepare(
                "UPDATE bookings SET status = 'confirmed', payment_deadline_at = NULL
                 WHERE id = ? AND status = 'pending' AND booking_source = 'online'"
            );
            $confirm->execute([$locked['booking_id']]);
            if ($confirm->rowCount() !== 1) {
                throw new RuntimeException('The booking could not be confirmed after payment.');
            }
            notify_public_booking_paid((int) $locked['booking_id']);
        } else {
            notify_public_payment_review((int) $locked['booking_id']);
        }
        $pdo->commit();

        return $bookingIsReserved ? 'confirmed' : 'review';
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

paypal_apply_saved_settings();
