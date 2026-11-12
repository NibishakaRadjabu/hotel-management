<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'mark_read') {
        $notificationId = filter_var($_POST['notification_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$notificationId) {
            flash('Choose a valid notification.', 'error');
        } else {
            $markRead = $pdo->prepare(
                'UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ? AND is_read = 0'
            );
            $markRead->execute([$notificationId, $user['id']]);
        }
    } elseif ($action === 'mark_all_read') {
        $markAllRead = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
        $markAllRead->execute([$user['id']]);
    } else {
        http_response_code(400);
        exit('Unknown notification action.');
    }
    $page = filter_var($_POST['page'] ?? 1, FILTER_VALIDATE_INT);
    redirect('notifications.php?page=' . max(1, (int) $page));
}

$pageNumber = max(1, (int) (filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1));
$totalCount = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ?');
$totalCount->execute([$user['id']]);
$notificationCount = (int) $totalCount->fetchColumn();
$pageCount = max(1, (int) ceil($notificationCount / 50));
$pageNumber = min($pageNumber, $pageCount);
$unreadQuery = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
$unreadQuery->execute([$user['id']]);
$unreadCount = (int) $unreadQuery->fetchColumn();
$statement = $pdo->prepare(
    'SELECT id, event_type, reference_text, is_read, created_at
     FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?'
);
$statement->bindValue(1, (int) $user['id'], PDO::PARAM_INT);
$statement->bindValue(2, 50, PDO::PARAM_INT);
$statement->bindValue(3, ($pageNumber - 1) * 50, PDO::PARAM_INT);
$statement->execute();
$notifications = $statement->fetchAll();
$pageTitle = 'Notifications';
require __DIR__ . '/includes/layout.php';
?>
<div class="welcome-row">
    <div><h2>Your activity notifications</h2><p>Only you can see your notifications and mark them as read.</p></div>
    <?php if ($unreadCount > 0): ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <button class="button button-secondary" type="submit" name="action" value="mark_all_read">Mark all as read</button>
    </form>
    <?php endif; ?>
</div>
<section class="panel notification-list">
    <?php if (!$notifications): ?>
        <div class="empty-state"><strong>No notifications yet</strong><p>Updates about your bookings and hotel actions will appear here.</p></div>
    <?php else: ?>
        <?php foreach ($notifications as $notification): ?>
        <article class="notification-item <?= $notification['is_read'] ? 'is-read' : 'is-unread' ?>">
            <span class="notification-item-icon" aria-hidden="true"><?= $notification['is_read'] ? '✓' : '•' ?></span>
            <div class="notification-item-body">
                <strong><?= e(notification_label($notification['event_type'])) ?></strong>
                <?php if ($notification['reference_text'] !== null && $notification['reference_text'] !== ''): ?><span><?= e($notification['reference_text']) ?></span><?php endif; ?>
                <time datetime="<?= e(date('Y-m-d', strtotime($notification['created_at']))) ?>" data-localized-date data-date-format="long"><?= e(date('d M Y', strtotime($notification['created_at']))) ?></time>
            </div>
            <?php if (!(bool) $notification['is_read']): ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="notification_id" value="<?= (int) $notification['id'] ?>">
                <input type="hidden" name="page" value="<?= $pageNumber ?>">
                <button class="button button-small button-secondary" type="submit" name="action" value="mark_read">Mark as read</button>
            </form>
            <?php endif; ?>
        </article>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
<?php if ($pageCount > 1): ?>
<nav class="notification-pagination" aria-label="Notification pages">
    <?php if ($pageNumber > 1): ?><a class="button button-secondary" href="notifications.php?page=<?= $pageNumber - 1 ?>">Previous</a><?php endif; ?>
    <span><?= $pageNumber ?> / <?= $pageCount ?></span>
    <?php if ($pageNumber < $pageCount): ?><a class="button button-secondary" href="notifications.php?page=<?= $pageNumber + 1 ?>">Next</a><?php endif; ?>
</nav>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
