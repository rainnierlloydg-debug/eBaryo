<section class="auth-card register-card"><p class="eyebrow">JOIN YOUR COMMUNITY</p><h1>Registration Form</h1><p class="muted">Your account will remain Pending until the admin verifies your National ID.</p>
<form action="action.php" method="post" enctype="multipart/form-data" class="form-stack" novalidate>
    <?= csrf_field() ?><input type="hidden" name="action" value="register">
    <div class="form-grid">
    <label>First Name<input name="first_name" autocomplete="given-name" maxlength="75" value="<?= form_value('register', 'first_name') ?>" required></label>
    <label>Last Name<input name="last_name" autocomplete="family-name" maxlength="75" value="<?= form_value('register', 'last_name') ?>" required></label>
    <label>Email Address<input name="email" type="email" autocomplete="email" maxlength="255" value="<?= form_value('register', 'email') ?>" required></label>
    <label>Contact Number<input name="contact_number" type="tel" autocomplete="tel" maxlength="30" value="<?= form_value('register', 'contact_number') ?>" required></label>
    <label>Barangay<input name="barangay" autocomplete="address-level3" maxlength="100" value="<?= form_value('register', 'barangay') ?>" required></label>
    <label>Sitio<input name="sitio" maxlength="100" value="<?= form_value('register', 'sitio') ?>" required></label>
    <label>National ID (Front)<input name="national_id_front" type="file" accept=".jpg,.jpeg,.png" required><small>JPG or PNG, up to 5 MB.</small></label>
    <label>National ID (Back)<input name="national_id_back" type="file" accept=".jpg,.jpeg,.png" required><small>JPG or PNG, up to 5 MB.</small></label>
    <label>Password<input name="password" type="password" autocomplete="new-password" minlength="8" required><small>At least 8 characters.</small></label>
    <label>Confirm Password<input name="password_confirmation" type="password" autocomplete="new-password" minlength="8" required></label>
    </div><button class="button primary wide" type="submit">Register Account</button>
</form><p class="form-footer">Already registered? <a href="index.php?page=login">Login</a></p></section>
