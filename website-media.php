<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
$user = require_roles(['admin']);

$mediaDirectory = __DIR__ . '/uploads/website-media';
$publicDirectory = 'uploads/website-media/';
$error = '';
$notice = '';
$imageLimit = 5 * 1024 * 1024;
$videoLimit = 20 * 1024 * 1024;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'upload') {
        $mediaType = $_POST['media_type'] ?? '';
        $roomIdInput = $_POST['room_id'] ?? '';
        $roomId = $roomIdInput === '' ? null : filter_var($roomIdInput, FILTER_VALIDATE_INT);
        $captionInput = $_POST['caption'] ?? '';
        $sortOrder = filter_var($_POST['sort_order'] ?? '0', FILTER_VALIDATE_INT);
        $caption = is_string($captionInput) ? trim($captionInput) : '';
        $upload = $_FILES['media_file'] ?? null;
        $isRoomImage = $mediaType === 'room_image';

        if (!in_array($mediaType, ['hero_image', 'room_image', 'hotel_video'], true)
            || $sortOrder === false || $sortOrder < 0 || $sortOrder > 65535
            || strlen($caption) > 160) {
            $error = 'Choose a media type and enter a valid caption and display order.';
        } elseif ($isRoomImage && (!$roomId || $roomId < 1)) {
            $error = 'Choose the room this photo belongs to.';
        } elseif ($roomIdInput !== '' && !is_int($roomId)) {
            $error = 'Choose a valid room.';
        } elseif (!$isRoomImage && $roomId !== null) {
            $error = 'Only room photos can be linked to a room.';
        } elseif (!is_array($upload) || !isset($upload['error'], $upload['tmp_name'], $upload['size'])) {
            $error = 'Choose a photo or video to upload.';
        } elseif (!is_int($upload['error']) || !is_string($upload['tmp_name']) || !is_int($upload['size'])) {
            $error = 'The media file could not be uploaded.';
        } elseif ($upload['error'] !== UPLOAD_ERR_OK) {
            $error = in_array($upload['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'The upload exceeds the server limit. Images may be up to 5 MB and videos up to 20 MB.'
                : 'The media file could not be uploaded.';
        } elseif ($isRoomImage && !is_int($roomId)) {
            $error = 'Choose a valid room.';
        } else {
            $temporaryFile = (string) $upload['tmp_name'];
            $fileSize = filesize($temporaryFile);
            $mimeType = is_uploaded_file($temporaryFile)
                ? (new finfo(FILEINFO_MIME_TYPE))->file($temporaryFile)
                : false;
            $imageExtensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            $videoExtensions = ['video/mp4' => 'mp4', 'video/webm' => 'webm'];
            $allowedExtensions = $mediaType === 'hotel_video' ? $videoExtensions : $imageExtensions;
            $fileLimit = $mediaType === 'hotel_video' ? $videoLimit : $imageLimit;
            $imageSize = isset($imageExtensions[$mimeType]) ? @getimagesize($temporaryFile) : false;
            $validImage = $mediaType === 'hotel_video'
                || (is_array($imageSize) && isset($imageSize[0], $imageSize[1])
                    && $imageSize[0] > 0 && $imageSize[1] > 0
                    && $imageSize[0] <= 6000 && $imageSize[1] <= 6000);
            if (!is_uploaded_file($temporaryFile) || !is_int($fileSize) || $fileSize < 1 || $fileSize > $fileLimit
                || !is_string($mimeType) || !isset($allowedExtensions[$mimeType]) || !$validImage) {
                $error = $mediaType === 'hotel_video'
                    ? 'Choose a valid MP4 or WebM video up to 20 MB.'
                    : 'Choose a valid JPEG, PNG, or WebP image up to 5 MB and 6000 pixels per side.';
            } elseif ($isRoomImage) {
                $roomCheck = $pdo->prepare('SELECT id FROM rooms WHERE id = ?');
                $roomCheck->execute([$roomId]);
                if (!$roomCheck->fetchColumn()) {
                    $error = 'Choose a valid room.';
                }
            }

            if ($error === '') {
                if (!is_dir($mediaDirectory)
                    && !mkdir($mediaDirectory, 0750, true)
                    && !is_dir($mediaDirectory)) {
                    throw new RuntimeException('Unable to create the website media directory.');
                }
                $fileName = bin2hex(random_bytes(20)) . '.' . $allowedExtensions[$mimeType];
                $mediaPath = $mediaDirectory . DIRECTORY_SEPARATOR . $fileName;
                $relativePath = $publicDirectory . $fileName;
                if (!move_uploaded_file($temporaryFile, $mediaPath)) {
                    throw new RuntimeException('Unable to save the website media file.');
                }

                try {
                    $insert = $pdo->prepare(
                        'INSERT INTO website_media (media_type, room_id, file_path, caption, sort_order, created_by)
                         VALUES (?, ?, ?, ?, ?, ?)'
                    );
                    $insert->execute([
                        $mediaType,
                        $isRoomImage ? $roomId : null,
                        $relativePath,
                        $caption,
                        $sortOrder,
                        $user['id'],
                    ]);
                    flash('Website media uploaded successfully.');
                } catch (Throwable $exception) {
                    if (is_file($mediaPath) && !unlink($mediaPath)) {
                        error_log('SmartHotel could not remove an unregistered website media file.');
                    }
                    throw $exception;
                }
                redirect('website-media.php');
            }
        }
    } elseif ($action === 'toggle') {
        $mediaId = filter_var($_POST['media_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$mediaId || !in_array($_POST['is_active'] ?? '', ['0', '1'], true)) {
            $error = 'Choose valid media and visibility settings.';
        } else {
            $update = $pdo->prepare('UPDATE website_media SET is_active = ? WHERE id = ?');
            $update->execute([(int) $_POST['is_active'], $mediaId]);
            if ($update->rowCount() > 0) {
                flash('Website media visibility updated.');
                redirect('website-media.php');
            }
            $check = $pdo->prepare('SELECT id FROM website_media WHERE id = ?');
            $check->execute([$mediaId]);
            $error = $check->fetchColumn() ? 'No visibility changes were made.' : 'Website media was not found.';
        }
    } elseif ($action === 'delete') {
        $mediaId = filter_var($_POST['media_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$mediaId) {
            $error = 'Choose valid website media.';
        } else {
            $pdo->beginTransaction();
            try {
                $find = $pdo->prepare('SELECT file_path FROM website_media WHERE id = ? FOR UPDATE');
                $find->execute([$mediaId]);
                $media = $find->fetch();
                if (!$media) {
                    $pdo->rollBack();
                    $error = 'Website media was not found.';
                } else {
                    $delete = $pdo->prepare('DELETE FROM website_media WHERE id = ?');
                    $delete->execute([$mediaId]);
                    $pdo->commit();
                    $fileName = basename((string) $media['file_path']);
                    $filePath = $mediaDirectory . DIRECTORY_SEPARATOR . $fileName;
                    if (str_starts_with((string) $media['file_path'], $publicDirectory)
                        && is_file($filePath) && !unlink($filePath)) {
                        error_log('SmartHotel could not remove deleted website media ' . $mediaId . '.');
                    }
                    flash('Website media deleted.');
                    redirect('website-media.php');
                }
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
        }
    } else {
        http_response_code(400);
        exit('Unknown website media action.');
    }
}

$roomList = $pdo->query('SELECT id, room_number, room_type FROM rooms ORDER BY floor, room_number')->fetchAll();
$mediaList = $pdo->query(
    'SELECT wm.id, wm.media_type, wm.room_id, wm.file_path, wm.caption, wm.sort_order, wm.is_active, wm.created_at,
            r.room_number, r.room_type
     FROM website_media wm
     LEFT JOIN rooms r ON r.id = wm.room_id
     ORDER BY FIELD(wm.media_type, \'hero_image\', \'room_image\', \'hotel_video\'), wm.sort_order, wm.id'
)->fetchAll();
$pageTitle = 'Website media';
require __DIR__ . '/includes/layout.php';
?>
<div class="welcome-row">
    <div><h2>Public website gallery</h2><p>Manage the booking-page slideshow, room photos, and short hotel videos.</p></div>
    <a class="button button-primary" href="stay.php" target="_blank" rel="noopener">View booking website ↗</a>
</div>
<?php if ($error !== ''): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<section class="panel table-panel">
    <div class="panel-heading"><div><h2>Add website media</h2><p>Images: JPEG, PNG, or WebP up to 5 MB. Video: MP4 or WebM up to 20 MB.</p></div></div>
    <form method="post" enctype="multipart/form-data" class="form-grid media-upload-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="upload">
        <label>Media type
            <select name="media_type" required>
                <option value="hero_image">Website slideshow image</option>
                <option value="room_image">Room photo</option>
                <option value="hotel_video">Hotel introduction video</option>
            </select>
        </label>
        <label>Room <span class="optional">(for room photos)</span>
            <select name="room_id">
                <option value="">Select a room</option>
                <?php foreach ($roomList as $room): ?>
                    <option value="<?= (int) $room['id'] ?>"><?= e($room['room_number'] . ' · ' . $room['room_type']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Caption <span class="optional">(optional)</span><input name="caption" maxlength="160"></label>
        <label>Display order <input name="sort_order" type="number" min="0" max="65535" value="0" required></label>
        <label class="media-file-field">Image or video file
            <input name="media_file" type="file" accept="image/jpeg,image/png,image/webp,video/mp4,video/webm" required>
        </label>
        <div class="form-actions"><button class="button button-primary" type="submit">Upload media</button></div>
    </form>
</section>
<section class="panel table-panel website-media-library">
    <div class="panel-heading"><div><h2>Current website media <span class="count-pill"><?= count($mediaList) ?></span></h2><p>Only active items are shown to guests.</p></div></div>
    <?php if (!$mediaList): ?>
        <div class="empty-state"><strong>No website media yet</strong><p>Upload a slideshow photo, room image, or hotel introduction video above.</p></div>
    <?php else: ?>
        <div class="website-media-grid">
        <?php foreach ($mediaList as $media): ?>
            <article class="website-media-item">
                <?php if ($media['media_type'] === 'hotel_video'): ?>
                    <video src="<?= e($media['file_path']) ?>" controls preload="metadata"></video>
                <?php else: ?>
                    <img src="<?= e($media['file_path']) ?>" alt="<?= e($media['caption'] ?: 'Hotel room and website gallery image') ?>" loading="lazy">
                <?php endif; ?>
                <div class="website-media-details">
                    <strong><?= e(match ($media['media_type']) {
                        'hero_image' => 'Website slideshow image',
                        'room_image' => 'Room photo',
                        'hotel_video' => 'Hotel introduction video',
                    }) ?></strong>
                    <small><?= $media['media_type'] === 'room_image'
                        ? e('Room ' . ($media['room_number'] ?? 'removed') . ' · ' . ($media['room_type'] ?? ''))
                        : e('Display order ' . $media['sort_order']) ?></small>
                    <?php if ($media['caption'] !== ''): ?><span><?= e($media['caption']) ?></span><?php endif; ?>
                    <div class="website-media-actions">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="media_id" value="<?= (int) $media['id'] ?>">
                            <input type="hidden" name="is_active" value="<?= $media['is_active'] ? '0' : '1' ?>">
                            <button class="button button-secondary button-small" type="submit"><?= $media['is_active'] ? 'Hide from website' : 'Show on website' ?></button>
                        </form>
                        <form method="post" data-confirm="Delete this media from the website?">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="media_id" value="<?= (int) $media['id'] ?>">
                            <button class="button button-quiet button-small" type="submit">Delete</button>
                        </form>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
