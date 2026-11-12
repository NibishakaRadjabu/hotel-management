<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/paypal.php';
$user = require_roles(['admin']);

$error = '';
$savedSettings = paypal_saved_settings();
$supportedCurrencies = ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'NZD', 'CHF', 'SGD', 'HKD'];
$encryptionReady = true;
$encryptionError = '';
try {
    paypal_settings_encryption_key();
} catch (RuntimeException $exception) {
    $encryptionReady = false;
    $encryptionError = $exception->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'disconnect') {
        $delete = $pdo->prepare('DELETE FROM paypal_settings WHERE id = 1');
        $delete->execute();
        audit_event('paypal_settings_removed');
        flash('Saved PayPal settings removed. Server environment settings, if present, will be used.');
        redirect('paypal-settings.php');
    }

    $clientIdInput = $_POST['client_id'] ?? '';
    $secretInput = $_POST['client_secret'] ?? '';
    $receiverInput = $_POST['receiver_email'] ?? '';
    $currencyInput = $_POST['currency'] ?? '';
    $rateInput = $_POST['rwf_per_unit'] ?? '';
    $modeInput = $_POST['mode'] ?? '';
    $clientId = is_string($clientIdInput) ? trim($clientIdInput) : '';
    $secret = is_string($secretInput) ? $secretInput : '';
    $receiverEmail = is_string($receiverInput) ? trim($receiverInput) : '';
    $currency = is_string($currencyInput) ? strtoupper(trim($currencyInput)) : '';
    $rate = is_string($rateInput) ? trim($rateInput) : '';
    $mode = is_string($modeInput) ? $modeInput : '';

    if ($action !== 'save') {
        $error = 'Choose a valid PayPal settings action.';
    } elseif (!$encryptionReady) {
        $error = 'The host must configure PAYPAL_SETTINGS_ENCRYPTION_KEY before saving PayPal settings.';
    } elseif ($clientId === '' || strlen($clientId) > 255) {
        $error = 'Enter a valid PayPal REST app Client ID.';
    } elseif ($secret === '' && $savedSettings === null) {
        $error = 'Enter the PayPal REST app Secret.';
    } elseif (strlen($secret) > 2000) {
        $error = 'The PayPal Secret is too long.';
    } elseif (!filter_var($receiverEmail, FILTER_VALIDATE_EMAIL) || strlen($receiverEmail) > 190) {
        $error = 'Enter the email address of the PayPal account that receives payments.';
    } elseif (!in_array($currency, $supportedCurrencies, true)) {
        $error = 'Choose a supported PayPal currency.';
    } elseif (!is_numeric($rate) || !is_finite((float) $rate) || (float) $rate <= 0 || (float) $rate > 999999999) {
        $error = 'Enter a valid positive RWF exchange rate.';
    } elseif (!in_array($mode, ['sandbox', 'live'], true)) {
        $error = 'Choose Sandbox or Live mode.';
    } else {
        $encryptedSecret = $secret !== ''
            ? paypal_encrypt_secret($secret)
            : (string) $savedSettings['encrypted_secret'];
        $save = $pdo->prepare(
            'INSERT INTO paypal_settings
             (id, client_id, encrypted_secret, receiver_email, currency, rwf_per_unit, mode, updated_by)
             VALUES (1, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE client_id = VALUES(client_id), encrypted_secret = VALUES(encrypted_secret),
                 receiver_email = VALUES(receiver_email), currency = VALUES(currency),
                 rwf_per_unit = VALUES(rwf_per_unit), mode = VALUES(mode), updated_by = VALUES(updated_by)'
        );
        $save->execute([
            $clientId,
            $encryptedSecret,
            $receiverEmail,
            $currency,
            $rate,
            $mode,
            $user['id'],
        ]);
        audit_event('paypal_settings_updated');
        flash('PayPal settings saved. Test Sandbox before accepting live payments.');
        redirect('paypal-settings.php');
    }
}

$pageTitle = 'PayPal settings';
require __DIR__ . '/includes/layout.php';
?>
<div class="welcome-row">
    <div><h2>Connect the hotel’s PayPal account</h2><p>Enter the account’s REST app credentials. Do not enter your PayPal login password.</p></div>
</div>
<?php if ($error !== ''): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<?php if (!$encryptionReady): ?>
    <div class="alert alert-error">PayPal settings are not ready. <?= e($encryptionError) ?> The hosting administrator must resolve this before settings can be saved.</div>
<?php elseif (!empty($config['paypal_setup_error'])): ?>
    <div class="alert alert-error"><?= e($config['paypal_setup_error']) ?> Enter the current REST app Secret below to save the settings again.</div>
<?php endif; ?>
<section class="panel form-panel">
    <div class="panel-heading"><div><h2><?= $savedSettings ? 'Update PayPal connection' : 'Set up PayPal' ?></h2><p>Use credentials from PayPal Developer → Apps & Credentials.</p></div></div>
    <form method="post" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save">
        <label>PayPal REST app Client ID
            <input name="client_id" maxlength="255" value="<?= e($savedSettings['client_id'] ?? $config['paypal_client_id']) ?>" autocomplete="off" required>
        </label>
        <label>PayPal REST app Secret
            <input name="client_secret" type="password" maxlength="2000" autocomplete="new-password" <?= $savedSettings ? '' : 'required' ?>>
            <span class="password-hint"><?= $savedSettings ? 'Leave blank to keep the saved Secret. Enter a new one to replace it.' : 'The Secret is encrypted before it is stored.' ?></span>
        </label>
        <label>Receiving PayPal account email
            <input name="receiver_email" type="email" maxlength="190" value="<?= e($savedSettings['receiver_email'] ?? $config['paypal_receiver_email']) ?>" required>
        </label>
        <label>Currency
            <?php $selectedCurrency = $savedSettings['currency'] ?? $config['paypal_currency']; ?>
            <select name="currency" required>
                <option value="">Choose currency</option>
                <?php foreach ($supportedCurrencies as $currencyOption): ?>
                    <option value="<?= e($currencyOption) ?>" <?= $selectedCurrency === $currencyOption ? 'selected' : '' ?>><?= e($currencyOption) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>RWF per one currency unit
            <input name="rwf_per_unit" type="number" min="0.0001" max="999999999" step="0.0001" value="<?= e($savedSettings['rwf_per_unit'] ?? $config['paypal_rwf_per_unit']) ?>" required>
        </label>
        <label>PayPal environment
            <?php $selectedMode = $savedSettings['mode'] ?? $config['paypal_mode']; ?>
            <select name="mode" required>
                <option value="sandbox" <?= $selectedMode === 'sandbox' ? 'selected' : '' ?>>Sandbox — testing only</option>
                <option value="live" <?= $selectedMode === 'live' ? 'selected' : '' ?>>Live — real payments</option>
            </select>
        </label>
        <div class="form-actions"><button class="button button-primary" type="submit" <?= !$encryptionReady ? 'disabled' : '' ?>>Save PayPal settings</button></div>
    </form>
    <p class="password-hint">The Client ID and Secret come from a PayPal REST app created by the account owner. The receiving email must belong to, and be authorized for, that app. Use a publicly reachable HTTPS hotel site.</p>
</section>
<?php if ($savedSettings): ?>
<section class="panel form-panel">
    <div class="panel-heading"><div><h2>Remove saved PayPal settings</h2><p>This deletes the encrypted credentials entered here. Any PayPal values configured by the host environment may still be used.</p></div></div>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="disconnect">
        <button class="button button-secondary" type="submit">Remove saved settings</button>
    </form>
</section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
