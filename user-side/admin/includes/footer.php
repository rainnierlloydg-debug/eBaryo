</main></div>
<dialog id="logout-dialog"><h2>Are you sure you want to log out?</h2><div class="button-row"><a class="button secondary" href="index.php">No</a><?php action_form('logout'); ?><button class="button primary">Yes</button></form></div></dialog>
<?php if ($flash): ?><dialog id="message-dialog" data-auto-open><h2><?= e($flash['message']) ?></h2><p><?= e($flash['detail']) ?></p><a class="button primary" href="<?= e($flash['return_to']) ?>">OK</a></dialog><?php endif; ?>
</body></html>
