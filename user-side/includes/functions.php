<?php
declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: '.$url, true, 303);
    exit;
}

function text_input(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function query_input(string $key): string
{
    $value = $_GET[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function valid_text(string $value, int $limit): bool
{
    return $value !== '' && mb_strlen($value) <= $limit && !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', $value);
}

function valid_phone(string $value): bool
{
    return (bool) preg_match('/^\+?[0-9][0-9 ()-]{5,28}[0-9]$/D', $value);
}

function csrf_field(): string
{
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    return '<input type="hidden" name="csrf" value="'.e($_SESSION['csrf']).'">';
}

function verify_csrf(): void
{
    if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], text_input('csrf'))) {
        http_response_code(419);
        exit('Session expired. Return to the form and reload the page.');
    }
}

function flash(string $message, string $returnTo, string $detail = '', ?string $displayPage = null): never
{
    $_SESSION['flash'] = ['message' => $message, 'return_to' => $returnTo, 'detail' => $detail];
    redirect($displayPage ?? $returnTo);
}

function current_user(): ?array
{
    $id = $_SESSION['user_id'] ?? null;
    if (!$id) return null;
    $statement = db()->prepare('SELECT * FROM users WHERE id = :id');
    $statement->execute(['id' => $id]);
    $user = $statement->fetch();
    return $user ?: null;
}

function require_user(): array
{
    $user = current_user();
    if (!$user || $user['status'] !== 'Verified') {
        unset($_SESSION['user_id'], $_SESSION['request_draft']);
        redirect('index.php?page=login');
    }
    return $user;
}

function fetch_document(int $id): ?array
{
    $statement = db()->prepare('SELECT * FROM documents WHERE id = :id AND is_active = 1');
    $statement->execute(['id' => $id]);
    return $statement->fetch() ?: null;
}

function remember_form(string $name, array $values): void
{
    $_SESSION['forms'][$name] = $values;
}

function form_value(string $name, string $field, mixed $default = ''): string
{
    return e($_SESSION['forms'][$name][$field] ?? $default);
}

function upload_image(string $field, string $folder, int $limit): string
{
    $file = $_FILES[$field] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null)) {
        throw new InvalidArgumentException('Choose a JPG or PNG image for each required upload.');
    }
    if (!is_uploaded_file($file['tmp_name']) || $file['size'] <= 0 || $file['size'] > $limit) {
        throw new InvalidArgumentException('The image exceeds the allowed size.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
    $originalExtension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!isset($extensions[$mime]) || !in_array($originalExtension, ['jpg', 'jpeg', 'png'], true) || !getimagesize($file['tmp_name'])) {
        throw new InvalidArgumentException('Only JPG and PNG images are accepted.');
    }
    $directory = __DIR__.'/../uploads/'.$folder;
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not prepare the upload directory.');
    }
    $relative = $folder.'/'.bin2hex(random_bytes(24)).'.'.$extensions[$mime];
    if (!move_uploaded_file($file['tmp_name'], __DIR__.'/../uploads/'.$relative)) {
        throw new RuntimeException('Could not save the uploaded image.');
    }
    return $relative;
}

function remove_upload(?string $path): void
{
    if (!$path || !preg_match('#^(national-ids|profiles)/[a-f0-9]{48}\.(jpg|png)$#D', $path)) return;
    $file = __DIR__.'/../uploads/'.$path;
    if (is_file($file)) unlink($file);
}

function request_id(int $id): string
{
    return 'REQ-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
}

function date_label(string $value): string
{
    return date('M j, Y · g:i A', strtotime($value));
}
