<?php
$statement = db()->prepare('SELECT COUNT(*) AS total, COALESCE(SUM(status = :status), 0) AS pending FROM document_requests WHERE user_id = :id');
$statement->execute(['id' => $user['id'], 'status' => 'Pending']);
$counts = $statement->fetch();
$statement = db()->prepare('SELECT COUNT(*) FROM notifications n LEFT JOIN user_preferences p ON p.user_id=n.user_id WHERE n.user_id = :id AND n.read_at IS NULL AND COALESCE(p.in_app_notifications,1)=1');
$statement->execute(['id' => $user['id']]);
$unread = (int) $statement->fetchColumn();
?>
<section class="welcome-card"><p class="eyebrow">WELCOME HOME</p><h2>Hello, <?= e($user['first_name']) ?>.</h2><p>Your barangay services, all in one place.</p><span class="welcome-decoration" aria-hidden="true">B</span></section>
<section class="metrics" aria-label="Your summary">
    <?php foreach (['Total requests' => $counts['total'], 'Pending requests' => $counts['pending'], 'Unread notifications' => $unread] as $label => $number): ?>
    <article class="metric"><span><?= e($label) ?></span><strong><?= e($number) ?></strong><span class="metric-bottom">YOUR ACTIVITY</span></article>
    <?php endforeach; ?>
</section>
<aside class="service-hint"><strong>Stay up to date.</strong> Check your notifications for corrections, status changes, and pickup reminders. <a href="index.php?page=notifications">View notifications</a></aside>
<div class="button-row dashboard-actions"><a class="button primary" href="index.php?page=request">Start Request <span aria-hidden="true">↗</span></a><a class="button secondary" href="index.php?page=history">View History <span aria-hidden="true">↗</span></a></div>
