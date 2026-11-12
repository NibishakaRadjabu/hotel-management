<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/paypal.php';

expire_online_booking_payments();
$error = '';
$testBookingConfirmation = null;
$manualBookingConfirmation = null;
if (($_GET['test_payment'] ?? '') === 'complete') {
    $testResult = $_SESSION['public_test_booking_result'] ?? null;
    unset($_SESSION['public_test_booking_result']);
    if (is_array($testResult) && isset($testResult['booking_id'], $testResult['deposit'])) {
        $testBookingConfirmation = $testResult;
    }
}
if (($_GET['manual_payment'] ?? '') === 'submitted') {
    $manualResult = $_SESSION['public_manual_booking_result'] ?? null;
    unset($_SESSION['public_manual_booking_result']);
    if (is_array($manualResult) && isset($manualResult['booking_id'], $manualResult['reference'])) {
        $manualBookingConfirmation = $manualResult;
    }
}
$testPaymentEnabled = payment_simulation_enabled();
$paypalAvailable = paypal_configured();
$minimumDate = date('Y-m-d');
$checkinInput = $_REQUEST['checkin_date'] ?? null;
$checkoutInput = $_REQUEST['checkout_date'] ?? null;
$checkin = is_string($checkinInput) ? $checkinInput : $minimumDate;
$checkout = is_string($checkoutInput) ? $checkoutInput : date('Y-m-d', strtotime('+1 day'));
$selectedRoom = (int) ($_REQUEST['room_id'] ?? 0);
$fullNameInput = $_POST['full_name'] ?? '';
$emailInput = $_POST['email'] ?? '';
$phoneInput = $_POST['phone'] ?? '';
$identityInput = $_POST['id_passport'] ?? '';
$paymentOptionInput = $_POST['payment_option'] ?? '';
$transactionReferenceInput = $_POST['transaction_reference'] ?? '';
$fullName = is_string($fullNameInput) ? trim($fullNameInput) : '';
$email = is_string($emailInput) ? strtolower(trim($emailInput)) : '';
$phone = is_string($phoneInput) ? trim($phoneInput) : '';
$identity = is_string($identityInput) ? trim($identityInput) : '';
$paymentOption = is_string($paymentOptionInput) ? $paymentOptionInput : '';
$transactionReference = is_string($transactionReferenceInput) ? trim($transactionReferenceInput) : '';
$validDates = valid_date($checkin) && valid_date($checkout)
    && $checkin >= $minimumDate && $checkin < $checkout
    && (int) (new DateTime($checkin))->diff(new DateTime($checkout))->days <= 365;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
}
$manualReferenceExists = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $paymentOption === 'mobile_money'
    && strlen($transactionReference) >= 4 && strlen($transactionReference) <= 93) {
    $referenceCheck = $pdo->prepare(
        'SELECT COUNT(*) FROM manual_payment_submissions WHERE transaction_reference = ?'
    );
    $referenceCheck->execute([$transactionReference]);
    $manualReferenceExists = (int) $referenceCheck->fetchColumn() > 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($fullName === '' || strlen($fullName) > 160) {
        $error = 'Enter your full name using no more than 160 characters.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        $error = 'Enter a valid email address.';
    } elseif ($phone === '' || strlen($phone) > 40 || strlen($identity) > 80) {
        $error = 'Enter a valid telephone number and a valid ID or passport if provided.';
    } elseif (!$validDates) {
        $error = 'Choose valid stay dates within the next 365 nights.';
    } elseif (!$selectedRoom) {
        $error = 'Choose an available room.';
    } elseif (!(($paymentOption === 'paypal' && $paypalAvailable)
        || ($paymentOption === 'mobile_money')
        || ($paymentOption === 'test' && $testPaymentEnabled))) {
        $error = 'Choose an available payment option.';
    } elseif ($paymentOption === 'mobile_money'
        && (strlen($transactionReference) < 4 || strlen($transactionReference) > 93
            || preg_match('/[\x00-\x1F\x7F]/', $transactionReference))) {
        $error = 'Enter the Airtel Money transaction reference after sending the deposit.';
    } elseif ($paymentOption === 'mobile_money' && $manualReferenceExists) {
        $error = 'That Airtel Money transaction reference has already been submitted.';
    } else {
        $bookingId = null;
        $guestId = null;
        $paypalReference = 'SHB-' . bin2hex(random_bytes(16));
        try {
            $pdo->beginTransaction();
            $roomQuery = $pdo->prepare(
                'SELECT id, room_number, price, status FROM rooms WHERE id = ? FOR UPDATE'
            );
            $roomQuery->execute([$selectedRoom]);
            $room = $roomQuery->fetch();
            $conflictQuery = $pdo->prepare(
                "SELECT COUNT(*) FROM bookings
                 WHERE room_id = ? AND status IN ('pending', 'confirmed', 'checked_in')
                   AND checkin_date < ? AND checkout_date > ?
                   AND (booking_source <> 'online' OR payment_deadline_at IS NULL OR payment_deadline_at > NOW())"
            );
            $conflictQuery->execute([$selectedRoom, $checkout, $checkin]);
            if (!$room || !in_array($room['status'], ['available', 'cleaned'], true)
                || (int) $conflictQuery->fetchColumn() > 0) {
                $pdo->rollBack();
                $error = 'That room is no longer available for the selected dates. Choose another room.';
            } else {
                $nightCount = (int) (new DateTime($checkin))->diff(new DateTime($checkout))->days;
                $totalAmount = round($nightCount * (float) $room['price'], 2);
                $depositAmountRwf = ceil($totalAmount * 0.5);
                $paypalAmount = $paymentOption === 'paypal'
                    ? $depositAmountRwf / (float) $config['paypal_rwf_per_unit']
                    : 0.0;
                if ($depositAmountRwf <= 0
                    || ($paymentOption === 'paypal' && (!is_finite($paypalAmount) || $paypalAmount < 0.01))) {
                    $pdo->rollBack();
                    $error = 'The selected room rate cannot be used for online payment. Contact the hotel.';
                } else {
                    $guestInsert = $pdo->prepare(
                        'INSERT INTO guests (full_name, phone, email, id_passport) VALUES (?, ?, ?, ?)'
                    );
                    $guestInsert->execute([$fullName, $phone, $email, $identity !== '' ? $identity : null]);
                    $guestId = (int) $pdo->lastInsertId();
                    $bookingStatus = $paymentOption === 'test' ? 'confirmed' : 'pending';
                    $paymentDeadline = match ($paymentOption) {
                        'test' => 'NULL',
                        'mobile_money' => 'DATE_ADD(NOW(), INTERVAL 1 DAY)',
                        default => 'DATE_ADD(NOW(), INTERVAL 30 MINUTE)',
                    };
                    $bookingInsert = $pdo->prepare(
                        "INSERT INTO bookings
                         (guest_id, room_id, checkin_date, checkout_date, nightly_rate, total_amount, status, booking_source, payment_deadline_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, 'online', $paymentDeadline)"
                    );
                    $bookingInsert->execute([
                        $guestId,
                        $selectedRoom,
                        $checkin,
                        $checkout,
                        $room['price'],
                        $totalAmount,
                        $bookingStatus,
                    ]);
                    $bookingId = (int) $pdo->lastInsertId();
                    if ($paymentOption === 'test') {
                        $testPayment = $pdo->prepare(
                            "INSERT INTO payments (booking_id, amount, payment_method) VALUES (?, ?, 'test')"
                        );
                        $testPayment->execute([$bookingId, $depositAmountRwf]);
                        $pdo->commit();
                        $_SESSION['public_test_booking_result'] = [
                            'booking_id' => $bookingId,
                            'deposit' => $depositAmountRwf,
                        ];
                        header('Location: stay.php?test_payment=complete#booking', true, 303);
                        exit;
                    } elseif ($paymentOption === 'mobile_money') {
                        $manualPayment = $pdo->prepare(
                            "INSERT INTO manual_payment_submissions
                             (booking_id, payment_method, transaction_reference, amount)
                             VALUES (?, 'mobile_money', ?, ?)"
                        );
                        $manualPayment->execute([$bookingId, $transactionReference, $depositAmountRwf]);
                        $pdo->commit();
                        $_SESSION['public_manual_booking_result'] = [
                            'booking_id' => $bookingId,
                            'reference' => $transactionReference,
                        ];
                        header('Location: stay.php?manual_payment=submitted#booking', true, 303);
                        exit;
                    } else {
                        $paypalAmount = paypal_format_amount($paypalAmount);
                        $attemptInsert = $pdo->prepare(
                            "INSERT INTO online_payment_attempts
                             (booking_id, tx_ref, expected_amount, expected_amount_rwf, currency)
                             VALUES (?, ?, ?, ?, ?)"
                        );
                        $attemptInsert->execute([
                            $bookingId,
                            $paypalReference,
                            $paypalAmount,
                            $depositAmountRwf,
                            $config['paypal_currency'],
                        ]);
                        $pdo->commit();
                    }

                    try {
                        $order = paypal_create_order(
                            $paypalReference,
                            $bookingId,
                            $paypalAmount,
                            $config['app_url'] . '/payment-return.php',
                            $config['app_url'] . '/payment-return.php?cancelled=1'
                        );
                        $orderId = $order['id'] ?? null;
                        $approvalUrl = null;
                        foreach (($order['links'] ?? []) as $link) {
                            if (is_array($link) && ($link['rel'] ?? '') === 'approve') {
                                $approvalUrl = $link['href'] ?? null;
                                break;
                            }
                        }
                        $approvalHost = is_string($approvalUrl) ? parse_url($approvalUrl, PHP_URL_HOST) : null;
                        $expectedApprovalHost = $config['paypal_mode'] === 'live'
                            ? 'www.paypal.com'
                            : 'www.sandbox.paypal.com';
                        if (!is_string($orderId) || !preg_match('/\A[A-Za-z0-9-]{8,40}\z/', $orderId)
                            || !is_string($approvalUrl) || !is_string($approvalHost)
                            || strtolower($approvalHost) !== $expectedApprovalHost
                            || parse_url($approvalUrl, PHP_URL_SCHEME) !== 'https') {
                            throw new RuntimeException('PayPal returned an invalid approval link.');
                        }
                        $saveOrder = $pdo->prepare(
                            "UPDATE online_payment_attempts SET gateway_order_id = ?
                             WHERE tx_ref = ? AND status = 'initiated'"
                        );
                        $saveOrder->execute([$orderId, $paypalReference]);
                        if ($saveOrder->rowCount() !== 1) {
                            throw new RuntimeException('Unable to save the PayPal order for this booking.');
                        }
                        header('Location: ' . $approvalUrl, true, 303);
                        exit;
                    } catch (Throwable $exception) {
                        error_log('SmartHotel PayPal checkout initialization failed: ' . $exception->getMessage());
                        $pdo->beginTransaction();
                        $failAttempt = $pdo->prepare(
                            "UPDATE online_payment_attempts SET status = 'failed'
                             WHERE tx_ref = ? AND status = 'initiated'"
                        );
                        $failAttempt->execute([$paypalReference]);
                        $cancelBooking = $pdo->prepare(
                            "UPDATE bookings SET status = 'cancelled'
                             WHERE id = ? AND status = 'pending' AND booking_source = 'online'"
                        );
                        $cancelBooking->execute([$bookingId]);
                        $deleteUnusedGuest = $pdo->prepare(
                            'DELETE FROM guests WHERE id = ? AND NOT EXISTS
                             (SELECT 1 FROM bookings WHERE guest_id = ?)'
                        );
                        $deleteUnusedGuest->execute([$guestId, $guestId]);
                        $pdo->commit();
                        $error = 'We could not start the secure payment. Your room was not reserved; please try again.';
                    }
                }
            }
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}

$rooms = [];
$roomImages = [];
if ($validDates) {
    $roomList = $pdo->prepare(
        "SELECT r.id, r.room_number, r.room_type, r.floor, r.price
         FROM rooms r
         WHERE r.status IN ('available', 'cleaned')
           AND NOT EXISTS (
               SELECT 1 FROM bookings b
               WHERE b.room_id = r.id AND b.status IN ('pending', 'confirmed', 'checked_in')
                 AND b.checkin_date < ? AND b.checkout_date > ?
                 AND (b.booking_source <> 'online' OR b.payment_deadline_at IS NULL OR b.payment_deadline_at > NOW())
           )
         ORDER BY r.floor, r.room_number"
    );
    $roomList->execute([$checkout, $checkin]);
    $rooms = $roomList->fetchAll();
    $roomMediaQuery = $pdo->query(
        "SELECT id, room_id, file_path, caption FROM website_media
         WHERE media_type = 'room_image' AND is_active = 1
         ORDER BY sort_order, id"
    );
    foreach ($roomMediaQuery->fetchAll() as $roomMedia) {
        $roomImages[(int) $roomMedia['room_id']][] = $roomMedia;
    }
}
$heroSlides = $pdo->query(
    "SELECT file_path, caption FROM website_media
     WHERE media_type = 'hero_image' AND is_active = 1
     ORDER BY sort_order, id"
)->fetchAll();
$hotelVideos = $pdo->query(
    "SELECT file_path, caption FROM website_media
     WHERE media_type = 'hotel_video' AND is_active = 1
     ORDER BY sort_order, id"
)->fetchAll();
$pageTitle = 'Book your stay';
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
    <div class="public-header-inner">
        <a class="brand public-brand" href="stay.php"><span class="brand-mark">S</span><span>SmartHotel<small>HOSPITALITY</small></span></a>
        <nav class="public-nav" aria-label="Main navigation">
            <a href="#booking">Rooms</a>
            <?php if ($hotelVideos): ?><a href="#hotel-story">Our story</a><?php endif; ?>
            <a class="public-nav-cta" href="#booking">Book your stay</a>
        </nav>
        <div class="topbar-tools">
            <label class="language-control"><span>Language</span><select id="language-select">
                <option value="en">English</option><option value="fr">Français</option><option value="sw">Kiswahili</option>
            </select></label>
            <a class="public-staff-link" href="login.php">Staff sign in</a>
        </div>
    </div>
</header>
<main class="public-main">
    <section class="public-hero">
        <div class="public-hero-copy">
            <p class="eyebrow">WELCOME TO SMARTHOTEL</p>
            <h1>Stay well. Feel at home.</h1>
            <p>Explore available rooms, compare nightly rates, and book directly for your chosen dates.</p>
            <div class="public-hero-actions">
                <a class="button button-primary" href="#booking">Book your stay</a>
                <a class="public-text-link" href="#booking">View available rooms</a>
            </div>
            <p class="public-hero-note"><span aria-hidden="true">✦</span> A simpler way to plan your next stay.</p>
        </div>
        <div class="public-hero-visual">
            <?php if ($heroSlides): ?>
            <div class="hotel-slideshow" data-hotel-slideshow aria-label="Hotel photo slideshow">
                <div class="hotel-slideshow-track">
                    <?php foreach ($heroSlides as $slideIndex => $slide): ?>
                    <figure class="hotel-slide<?= $slideIndex === 0 ? ' is-active' : '' ?>"<?= $slideIndex === 0 ? '' : ' hidden' ?>>
                        <img src="<?= e($slide['file_path']) ?>" alt="<?= e($slide['caption'] ?: 'SmartHotel exterior and hospitality') ?>">
                        <?php if ($slide['caption'] !== ''): ?><figcaption><?= e($slide['caption']) ?></figcaption><?php endif; ?>
                    </figure>
                    <?php endforeach; ?>
                </div>
                <?php if (count($heroSlides) > 1): ?>
                <button type="button" class="slideshow-control slideshow-previous" data-slide-previous aria-label="Previous photo">‹</button>
                <button type="button" class="slideshow-control slideshow-next" data-slide-next aria-label="Next photo">›</button>
                <div class="slideshow-dots" aria-label="Slideshow pages">
                    <?php foreach ($heroSlides as $slideIndex => $_slide): ?>
                        <button type="button" class="slideshow-dot<?= $slideIndex === 0 ? ' is-active' : '' ?>" data-slide-to="<?= $slideIndex ?>" aria-label="Choose slide" aria-current="<?= $slideIndex === 0 ? 'true' : 'false' ?>"></button>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="public-hero-placeholder" role="img" aria-label="Illustration of SmartHotel">
                <span class="public-illustration-sun"></span>
                <div class="public-illustration-building">
                    <span class="public-illustration-roof"></span>
                    <span class="public-illustration-name">S</span>
                    <span class="public-illustration-windows"><i></i><i></i><i></i><i></i><i></i><i></i></span>
                    <span class="public-illustration-door"></span>
                </div>
                <span class="public-illustration-hill"></span>
                <span class="public-illustration-caption">SmartHotel <small>HOSPITALITY</small></span>
            </div>
            <?php endif; ?>
        </div>
    </section>
    <section class="public-benefits" aria-label="Plan your stay">
        <article>
            <span class="public-benefit-number">01</span>
            <div><h2>Choose your dates</h2><p>Set your arrival and departure to see live availability.</p></div>
        </article>
        <article>
            <span class="public-benefit-number">02</span>
            <div><h2>Compare available rooms</h2><p>Review room details and nightly rates before you choose.</p></div>
        </article>
        <article>
            <span class="public-benefit-number">03</span>
            <div><h2>Book with confidence</h2><p>See your stay total and payment details before continuing.</p></div>
        </article>
    </section>
    <?php if ($hotelVideos): ?>
    <section class="public-story-section" id="hotel-story">
        <div class="public-section-heading">
            <p class="eyebrow">A CLOSER LOOK</p>
            <h2>Discover SmartHotel</h2>
            <p>Take a look around and get to know the spaces featured by our hotel team.</p>
        </div>
        <div class="hotel-video-section" aria-label="Hotel introduction">
            <?php foreach ($hotelVideos as $video): ?>
            <figure class="hotel-video-card">
                <video controls preload="metadata" playsinline>
                    <source src="<?= e($video['file_path']) ?>" type="<?= str_ends_with(strtolower($video['file_path']), '.webm') ? 'video/webm' : 'video/mp4' ?>">
                    Your browser does not support the hotel introduction video.
                </video>
                <?php if ($video['caption'] !== ''): ?><figcaption><?= e($video['caption']) ?></figcaption><?php endif; ?>
            </figure>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
    <section class="public-booking-section" id="booking">
        <div class="public-section-heading">
            <p class="eyebrow">RESERVATIONS</p>
            <h2>Find a room for your stay</h2>
            <p>Check dates, compare available rooms, and make your reservation directly.</p>
        </div>
        <div class="public-booking-card">
        <?php if ($error !== ''): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <?php if (!$paypalAvailable): ?><div class="alert alert-test">PayPal checkout is unavailable. Choose Airtel Money for manual staff verification<?= $testPaymentEnabled ? ' or the local test simulation' : '' ?>.</div><?php endif; ?>
        <?php if ($testPaymentEnabled): ?><div class="alert alert-test" role="note"><strong>TEST MODE ONLY:</strong> No real payment will be made. The simulation option creates a test-marked booking and deposit.</div><?php endif; ?>
        <?php if ($manualBookingConfirmation): ?>
            <div class="alert alert-test" role="status"><strong>PAYMENT REFERENCE SUBMITTED.</strong> Booking request #<?= (int) $manualBookingConfirmation['booking_id'] ?> is waiting for hotel staff to verify the Airtel Money deposit. Reference: <?= e($manualBookingConfirmation['reference']) ?>. This site has not verified receipt; contact the hotel for payment instructions if needed.</div>
        <?php endif; ?>
        <?php if ($testBookingConfirmation): ?>
            <div class="alert alert-test" role="status"><strong>TEST PAYMENT SIMULATED — NO MONEY WAS CHARGED.</strong> Test booking #<?= (int) $testBookingConfirmation['booking_id'] ?> was recorded locally with a simulated deposit of <?= e(money($testBookingConfirmation['deposit'])) ?>. It is not a real-world reservation or payment.</div>
        <?php endif; ?>
        <div class="public-card-heading"><div><h2>Book a room</h2><p>Select an advance-payment option. Airtel Money references are checked manually by hotel staff.</p></div><span class="deposit-chip">50% advance</span></div>
        <form method="get" class="date-search form-grid">
            <label>Check-in date<input type="date" name="checkin_date" min="<?= e($minimumDate) ?>" value="<?= e($checkin) ?>" required></label>
            <label>Check-out date<input type="date" name="checkout_date" min="<?= e(date('Y-m-d', strtotime('+1 day'))) ?>" value="<?= e($checkout) ?>" required></label>
            <div class="form-actions"><button class="button button-secondary" type="submit">Check availability</button></div>
        </form>
        <?php if (!$validDates): ?>
            <div class="empty-state"><strong>Choose valid stay dates</strong><p>Check-in must be today or later, and stays can be up to 365 nights.</p></div>
        <?php elseif (!$rooms): ?>
            <div class="empty-state"><strong>No rooms available for these dates</strong><p>Try different dates or contact the hotel for assistance.</p></div>
        <?php else: ?>
        <form method="post" class="public-booking-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="checkin_date" value="<?= e($checkin) ?>">
            <input type="hidden" name="checkout_date" value="<?= e($checkout) ?>">
            <fieldset class="payment-method-list">
                <legend>Choose how to pay your advance</legend>
                <?php if ($paypalAvailable): ?>
                <label class="payment-method-choice"><input type="radio" name="payment_option" value="paypal" checked required>
                    <span><strong>PayPal</strong><small>Pay securely through PayPal. Availability depends on your country and account.</small></span>
                </label>
                <?php endif; ?>
                <label class="payment-method-choice"><input type="radio" name="payment_option" value="mobile_money" <?= !$paypalAvailable && !$testPaymentEnabled ? 'checked' : '' ?> required>
                    <span><strong>Airtel Money (DRC)</strong><small>Pay using the hotel's Airtel Money instructions, then enter your transaction reference. Staff must verify the payment before confirming.</small></span>
                </label>
                <?php if ($testPaymentEnabled): ?>
                <label class="payment-method-choice"><input type="radio" name="payment_option" value="test" <?= !$paypalAvailable ? 'checked' : '' ?> required>
                    <span><strong>Test simulation — no real payment</strong><small>For local testing only. No money is transferred.</small></span>
                </label>
                <?php endif; ?>
            </fieldset>
            <fieldset class="room-choice-list">
                <legend>Available rooms</legend>
                <?php $roomIndex = 0; foreach ($rooms as $room): ?>
                <?php $roomNights = (int) (new DateTime($checkin))->diff(new DateTime($checkout))->days; $roomTotal = $roomNights * (float) $room['price']; ?>
                <label class="public-room-choice<?= isset($roomImages[(int) $room['id']]) ? ' has-room-image' : '' ?>">
                    <input type="radio" name="room_id" value="<?= (int) $room['id'] ?>" data-deposit-rwf="<?= e((string) ceil($roomTotal * 0.5)) ?>" <?= $selectedRoom === (int) $room['id'] || (!$selectedRoom && $roomIndex === 0) ? 'checked' : '' ?> required>
                    <?php if (!empty($roomImages[(int) $room['id']])): ?>
                        <?php $roomImage = $roomImages[(int) $room['id']][0]; ?>
                        <img class="public-room-image" src="<?= e($roomImage['file_path']) ?>" alt="<?= e($roomImage['caption'] ?: 'Room ' . $room['room_number']) ?>" loading="lazy">
                    <?php endif; ?>
                    <span class="public-room-details"><strong>Room <?= e($room['room_number']) ?> · <?= e(ucwords($room['room_type'])) ?></strong><small>Floor <?= (int) $room['floor'] ?> · <?= e(money($room['price'])) ?> per night</small></span>
                    <span class="public-room-price"><strong><?= e(money($roomTotal)) ?></strong><small>stay total</small></span>
                </label>
                <?php $roomIndex++; endforeach; ?>
            </fieldset>
            <div class="form-grid public-guest-fields">
                <label>Full name<input name="full_name" maxlength="160" value="<?= e($fullName) ?>" autocomplete="name" required></label>
                <label>Email address<input name="email" type="email" maxlength="190" value="<?= e($email) ?>" autocomplete="email" required></label>
                <label>Telephone<input name="phone" type="tel" maxlength="40" value="<?= e($phone) ?>" autocomplete="tel" required></label>
                <label>ID or passport <span class="optional">(optional)</span><input name="id_passport" maxlength="80" value="<?= e($identity) ?>"></label>
            </div>
            <label class="manual-payment-reference">Airtel Money transaction reference <span class="optional">(enter only if choosing Airtel Money)</span>
                <input name="transaction_reference" maxlength="93" value="<?= e($transactionReference) ?>" autocomplete="off">
            </label>
            <p class="public-payment-note">The advance is 50% of the stay total. PayPal is confirmed after provider verification; Airtel Money requests remain pending until hotel staff verify receipt.</p>
            <?php if ($paypalAvailable): ?>
            <p class="paypal-deposit-preview" data-paypal-deposit-preview
               data-currency="<?= e($config['paypal_currency']) ?>"
               data-rwf-per-unit="<?= e($config['paypal_rwf_per_unit']) ?>">
                Estimated PayPal deposit: <strong data-paypal-deposit-amount>—</strong>.
                The final charge uses the hotel’s configured exchange rate; the remaining balance is recorded in RWF.
            </p>
            <?php endif; ?>
            <button class="button button-primary public-pay-button" type="submit">Continue with selected payment</button>
        </form>
        <?php endif; ?>
        </div>
    </section>
</main>
<footer class="public-footer">
    <a class="brand public-brand" href="stay.php"><span class="brand-mark">S</span><span>SmartHotel<small>HOSPITALITY</small></span></a>
    <p>SmartHotel · Simple operations, better stays</p>
    <a class="public-staff-link" href="login.php">Staff sign in</a>
</footer>
<script src="assets/i18n.js"></script>
<script src="assets/app.js"></script>
</body>
</html>
