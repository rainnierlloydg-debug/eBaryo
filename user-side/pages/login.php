<section class="auth-card"><p class="eyebrow">WELCOME BACK</p><h1>Login Form</h1><p class="muted">Sign in to access resident services.</p>
<form action="action.php" method="post" class="form-stack" novalidate>
    <?= csrf_field() ?><input type="hidden" name="action" value="login">
    <label>Email<input type="email" name="email" autocomplete="username" value="<?= form_value('login', 'email') ?>" required autofocus></label>
    <label>Password<input type="password" name="password" autocomplete="current-password" required></label>
    <button class="button primary wide" type="submit">Login</button>
</form><p class="form-footer">New here? <a href="index.php?page=register">Register</a></p></section>
