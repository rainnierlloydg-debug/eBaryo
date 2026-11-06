<?php
$statement = db()->prepare('SELECT email_updates, in_app_notifications FROM user_preferences WHERE user_id = :id');
$statement->execute(['id' => $user['id']]);
$preferences = $statement->fetch() ?: ['email_updates' => 1, 'in_app_notifications' => 1];
?>
<div class="settings-grid"><section class="panel"><p class="eyebrow">SECURITY</p><details <?= query_input('change_password') === '1' ? 'open' : '' ?>><summary>Change Password</summary><form action="action.php" method="post" class="form-stack" novalidate>
    <?= csrf_field() ?><input type="hidden" name="action" value="change_password">
    <label>Current Password<input name="current_password" type="password" autocomplete="current-password" required></label>
    <label>New Password<input name="new_password" type="password" autocomplete="new-password" minlength="8" required><small>8–72 characters.</small></label>
    <label>Confirm New Password<input name="confirm_password" type="password" autocomplete="new-password" minlength="8" required></label>
    <button class="button primary" type="submit">Update Password</button>
</form></details></section>
<section class="panel"><p class="eyebrow">PREFERENCES</p><h2>Stay connected</h2><form action="action.php" method="post" class="form-stack">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_preferences">
    <label class="toggle-row"><span>Email Updates</span><input type="checkbox" name="email_updates" value="1" <?= $preferences['email_updates'] ? 'checked' : '' ?>></label>
    <label class="toggle-row"><span>In-app Notifications</span><input type="checkbox" name="in_app_notifications" value="1" <?= $preferences['in_app_notifications'] ? 'checked' : '' ?>></label>
    <button class="button primary" type="submit">Save Preference</button>
</form></section></div>
