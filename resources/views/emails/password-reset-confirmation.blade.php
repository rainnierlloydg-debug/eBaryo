<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Confirm password change</title></head>
<body>
    <h1>Confirm your password change</h1>
    <p>We received a request to change your eBaryo password.</p>
    <p><a href="{{ $resetUrl }}">Confirm and change password</a></p>
    <p>This link expires in 10 minutes and can only be used once. Your password will change only after you enter and save a new password.</p>
    <p>If the button does not work, copy this link into your browser:</p>
    <p>{{ $resetUrl }}</p>
    <p>If you did not request this, ignore this email. Do not share this link.</p>
</body>
</html>
