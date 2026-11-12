<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/paypal.php';

$result = 'pending';
$orderId = $_GET['token'] ?? '';
$cancelled = $_GET['cancelled'] ?? '';
if ($cancelled === '1') {
    $result = 'failed';
} elseif (is_string($orderId) && preg_match('/\A[A-Za-z0-9-]{8,40}\z/', $orderId)
    && paypal_configured()) {
    try {
        $result = complete_paypal_booking_payment($orderId);
    } catch (Throwable $exception) {
        error_log('SmartHotel PayPal return verification failed: ' . $exception->getMessage());
        $result = 'pending';
    }
}

$pageTitle = 'Booking payment';
$messages = [
    'confirmed' => ['Payment received', 'Your advance payment is verified and your booking is confirmed.'],
    'review' => ['Payment received - hotel review needed', 'Your payment was received, but the reservation needs hotel staff review. Please contact the hotel before travelling.'],
    'failed' => ['Payment was not completed', 'Your payment could not be completed. You may return and try another booking.'],
    'pending' => ['Payment confirmation pending', 'We could not confirm your payment yet. Do not pay again until you have checked with the hotel or tried refreshing this page.'],
];
$message = $messages[$result];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | SmartHotel</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="public-booking-page">
<header class="public-header">
    <a class="brand public-brand" href="stay.php"><span class="brand-mark">S</span><span>SmartHotel<small>HOSPITALITY</small></span></a>
    <label class="language-control"><span>Language</span><select id="language-select">
        <option value="en">English</option><option value="fr">Français</option><option value="sw">Kiswahili</option>
    </select></label>
</header>
<main class="payment-result-card">
    <span class="payment-result-icon" aria-hidden="true"><?= in_array($result, ['confirmed', 'review'], true) ? '✓' : '…' ?></span>
    <h1><?= e($message[0]) ?></h1>
    <p><?= e($message[1]) ?></p>
    <a class="button button-primary" href="stay.php">Return to room booking</a>
</main>
<script src="assets/i18n.js"></script>
<script src="assets/app.js"></script>
</body>
</html>
