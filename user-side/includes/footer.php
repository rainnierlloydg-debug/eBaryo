</main>
<?php if ($protected): ?></div>
<dialog id="logout-dialog" aria-labelledby="logout-title"><div class="dialog-mark">?</div><h2 id="logout-title">Are you sure you want to log out?</h2><div class="button-row"><a class="button secondary" href="index.php?page=dashboard">No</a><form action="action.php" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="logout"><button class="button primary" type="submit">Yes</button></form></div></dialog>
<?php endif; ?>
<?php if ($flash): ?>
<dialog id="message-dialog" data-auto-open aria-labelledby="message-title" data-lock-dialog><div class="dialog-mark"><?= in_array($flash['message'], ['Invalid', 'Invalid Input'], true) ? '!' : '✓' ?></div><h2 id="message-title"><?= e($flash['message']) ?></h2><?php if ($flash['detail']): ?><p><?= e($flash['detail']) ?></p><?php endif; ?><a class="button primary" href="<?= e($flash['return_to']) ?>">OK</a></dialog>
<?php endif; ?>
</body>
</html>
