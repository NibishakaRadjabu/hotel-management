<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$error = '';
$maxImageBytes = 2 * 1024 * 1024;
$avatarDirectory = __DIR__ . '/uploads/avatars';
$avatarUrl = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmation = (string) ($_POST['password_confirmation'] ?? '');
    $passwordChanged = $newPassword !== '';
    $emailChanged = $email !== strtolower((string) $user['email']);
    $upload = $_FILES['profile_image'] ?? null;

    if ($fullName === '' || strlen($fullName) > 160) {
        $error = 'Enter a name using no more than 160 characters.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        $error = 'Enter a valid email address.';
    } elseif (($passwordChanged || $emailChanged) && strlen($currentPassword) > 200) {
        $error = 'Current password is incorrect.';
    } elseif ($passwordChanged && (strlen($newPassword) < 12 || strlen($newPassword) > 200)) {
        $error = 'Choose a password between 12 and 200 characters.';
    } elseif ($passwordChanged && $newPassword !== $confirmation) {
        $error = 'The new passwords do not match.';
    } elseif ($passwordChanged || $emailChanged) {
        $passwordQuery = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $passwordQuery->execute([$user['id']]);
        $passwordHash = $passwordQuery->fetchColumn();
        if (!is_string($passwordHash) || !password_verify($currentPassword, $passwordHash)) {
            $error = 'Current password is incorrect.';
        } elseif ($passwordChanged && password_verify($newPassword, $passwordHash)) {
            $error = 'Choose a new password that is different from your current one.';
        }
    }

    if ($error === '' && $upload !== null) {
        if (!is_array($upload) || !isset($upload['error'], $upload['tmp_name'], $upload['size'])) {
            $error = 'The profile photo could not be uploaded.';
        } else {
            $uploadError = (int) $upload['error'];
            if (!in_array($uploadError, [UPLOAD_ERR_NO_FILE, UPLOAD_ERR_OK], true)) {
                $error = $uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE
                    ? 'Profile photos must be 2 MB or smaller.'
                    : 'The profile photo could not be uploaded.';
            }
        }
    }

    if ($error === '' && is_array($upload) && (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $temporaryFile = (string) $upload['tmp_name'];
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!is_uploaded_file($temporaryFile)) {
            $error = 'The profile photo could not be uploaded.';
        } else {
            $imageBytes = filesize($temporaryFile);
            $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryFile);
            $imageSize = isset($extensions[$mimeType]) ? @getimagesize($temporaryFile) : false;
        }
        if ($error === '' && (!is_int($imageBytes) || $imageBytes < 1 || $imageBytes > $maxImageBytes
            || !is_array($imageSize) || !isset($extensions[$mimeType])
            || $imageSize[0] < 1 || $imageSize[1] < 1
            || $imageSize[0] > 4096 || $imageSize[1] > 4096)) {
            $error = 'Choose a valid JPEG, PNG, or WebP image up to 2 MB and 4096 pixels per side.';
        } elseif ($error === '') {
            $avatarUrl = 'uploads/avatars/' . bin2hex(random_bytes(16)) . '.' . $extensions[$mimeType];
        }
    }

    if ($error === '' && ($fullName !== $user['full_name'] || $emailChanged || $passwordChanged || $avatarUrl !== null)) {
        $newAvatarPath = null;
        if ($avatarUrl !== null) {
            if (!is_dir($avatarDirectory) && !mkdir($avatarDirectory, 0750, true) && !is_dir($avatarDirectory)) {
                throw new RuntimeException('Unable to create the profile-photo directory.');
            }
            $newAvatarPath = __DIR__ . '/' . $avatarUrl;
            if (!move_uploaded_file((string) $upload['tmp_name'], $newAvatarPath)) {
                throw new RuntimeException('Unable to save the uploaded profile photo.');
            }
        }

        try {
            $pdo->beginTransaction();
            $update = $pdo->prepare(
                'UPDATE users
                 SET full_name = ?, email = ?, profile_image = ?,
                     password_hash = CASE WHEN ? = 1 THEN ? ELSE password_hash END,
                     must_change_password = CASE WHEN ? = 1 THEN 0 ELSE must_change_password END,
                     session_version = session_version + ?
                 WHERE id = ?'
            );
            $update->execute([
                $fullName,
                $email,
                $avatarUrl ?? $user['profile_image'],
                $passwordChanged ? 1 : 0,
                $passwordChanged ? password_hash($newPassword, PASSWORD_DEFAULT) : null,
                $passwordChanged ? 1 : 0,
                $passwordChanged ? 1 : 0,
                $user['id'],
            ]);
            audit_event($passwordChanged ? 'auth.password_changed' : 'profile.updated', (int) $user['id']);
            create_notification((int) $user['id'], $passwordChanged ? 'password_changed' : 'profile_updated');
            $pdo->commit();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($newAvatarPath !== null && is_file($newAvatarPath)) {
                unlink($newAvatarPath);
            }
            if ($exception->getCode() === '23000') {
                $error = 'An account already uses that email address.';
            } else {
                throw $exception;
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($newAvatarPath !== null && is_file($newAvatarPath)) {
                unlink($newAvatarPath);
            }
            throw $exception;
        }

        if ($error === '') {
            if ($avatarUrl !== null && $user['profile_image'] !== null) {
                $oldName = basename((string) $user['profile_image']);
                if (preg_match('/\A[a-f0-9]{32}\.(?:jpg|png|webp)\z/', $oldName)) {
                    $oldPath = $avatarDirectory . '/' . $oldName;
                    if (is_file($oldPath)) {
                        unlink($oldPath);
                    }
                }
            }
            if ($passwordChanged) {
                session_regenerate_id(true);
                $_SESSION['session_version']++;
                $_SESSION['last_activity'] = time();
            }
            flash('Profile updated successfully.');
            redirect('profile.php');
        }
    } elseif ($error === '') {
        $error = 'No profile changes were made.';
    }
}

$pageTitle = 'My profile';
require __DIR__ . '/includes/layout.php';
?>
<div class="welcome-row">
    <div><h2>Personal account</h2><p>Update your details and profile photo. Confirm your current password to change your email or password.</p></div>
</div>
<section class="panel profile-panel">
    <?php if ($error !== ''): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <label>Full name<input name="full_name" maxlength="160" value="<?= e($user['full_name']) ?>" required></label>
        <label>Email address<input name="email" type="email" maxlength="190" value="<?= e($user['email']) ?>" required></label>
        <label class="profile-photo-field">Profile photo
            <span class="profile-photo-preview">
                <?php if ($user['profile_image']): ?><img src="<?= e($user['profile_image']) ?>" alt=""><?php else: ?><span><?= e(strtoupper(substr($user['full_name'], 0, 1))) ?></span><?php endif; ?>
            </span>
            <input name="profile_image" type="file" accept="image/jpeg,image/png,image/webp">
            <small>JPEG, PNG, or WebP; maximum 2 MB and 4096 pixels per side.</small>
        </label>
        <label>Current password <span class="optional">(required to change email or password)</span><input name="current_password" type="password" autocomplete="current-password"></label>
        <label>New password <span class="optional">(leave blank to keep current)</span><input name="new_password" type="password" autocomplete="new-password" minlength="12" maxlength="200"></label>
        <label>Confirm new password<input name="password_confirmation" type="password" autocomplete="new-password" minlength="12" maxlength="200"></label>
        <div class="form-actions"><button class="button button-primary" type="submit">Save profile</button></div>
    </form>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
