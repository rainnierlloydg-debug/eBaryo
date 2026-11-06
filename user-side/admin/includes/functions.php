<?php
declare(strict_types=1);
const REQUEST_STATUSES = ['Submitted','Under Review','Approved','Rejected','For Correction','Processing','Ready for Pickup','Released','Cancelled','Expired'];
function rows(string $sql, array $params = []): array {
    $stmt = db()->prepare($sql); $stmt->execute($params); return $stmt->fetchAll();
}
function run(string $sql, array $params = []): void {
    $stmt = db()->prepare($sql); $stmt->execute($params);
}
function audit(string $action, string $entity, ?int $id, array $changes = []): void {
    run('INSERT INTO admin_audit_logs(admin_id, action, entity_type, entity_id, changes) VALUES(?,?,?,?,?)', [(int)$_SESSION['user_id'], $action, $entity, $id, json_encode($changes, JSON_THROW_ON_ERROR)]);
}
function notify_resident(int $id, string $title, string $message, ?int $request = null, ?int $announcement = null): void {
    // Persist every required notification; the resident UI applies the in-app preference.
    run('INSERT INTO notifications(user_id,request_id,title,message,announcement_id) VALUES(?,?,?,?,?)', [$id,$request,$title,$message,$announcement]);
    $user = rows('SELECT u.email, COALESCE(p.email_updates,1) email_updates FROM users u LEFT JOIN user_preferences p ON p.user_id=u.id WHERE u.id=?',[$id])[0] ?? null;
    if ($user && $user['email_updates']) run('INSERT INTO email_outbox(user_id,recipient,subject,body) VALUES(?,?,?,?)',[$id,$user['email'],$title,$message]);
}
function post_id(string $key = 'id'): int {
    $value = text_input($key);
    if (!ctype_digit($value) || (int)$value < 1) throw new InvalidArgumentException('Choose a valid record.');
    return (int)$value;
}
function assert_valid(bool $valid, string $detail = 'Check the required fields.'): void {
    if (!$valid) throw new InvalidArgumentException($detail);
}
function money_valid(string $value): bool { return (bool)preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $value); }
function date_input(string $field): ?string {
    $value = text_input($field); if ($value === '') return null;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value);
    assert_valid($date !== false && $date->format('Y-m-d\TH:i') === $value, 'Enter a valid date and time.');
    return $date->format('Y-m-d H:i:s');
}
function clean_document(string $html): string {
    assert_valid(strlen($html) <= 200000, 'Document content is too long.');
    $doc = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><body>'.$html.'</body>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors(); libxml_use_internal_errors($previous);
    $allowed = ['p','br','b','strong','i','em','u','h1','h2','h3','ul','ol','li','blockquote','div'];
    $walk = function (DOMNode $node) use (&$walk, $allowed): string {
        if ($node instanceof DOMText) return e($node->textContent);
        if (!$node instanceof DOMElement) return '';
        if (in_array(strtolower($node->tagName), ['script','style','iframe','object','svg','math'],true)) return '';
        $inner = ''; foreach ($node->childNodes as $child) $inner .= $walk($child);
        $tag = strtolower($node->tagName);
        return in_array($tag,$allowed,true) ? ($tag === 'br' ? '<br>' : '<'.$tag.'>'.$inner.'</'.$tag.'>') : $inner;
    };
    $output = ''; foreach ($doc->getElementsByTagName('body')->item(0)->childNodes as $child) $output .= $walk($child);
    return $output;
}
function action_form(string $action, ?int $id = null): void {
    echo '<form method="post" action="action.php">'.csrf_field().'<input type="hidden" name="action" value="'.e($action).'">';
    if ($id !== null) echo '<input type="hidden" name="id" value="'.$id.'">';
}
function options(array $values, string $selected): void {
    foreach ($values as $value) echo '<option value="'.e($value).'"'.($value === $selected ? ' selected' : '').'>'.e($value).'</option>';
}
function request_rows(array $params = [], string $where = ''): array {
    return rows('SELECT r.*, CONCAT(u.first_name," ",u.last_name) resident_name, u.email FROM document_requests r JOIN users u ON u.id=r.user_id '.$where.' ORDER BY r.created_at DESC,r.id DESC', $params);
}
function status_label(string $status): string { return $status === 'Pending' ? 'Submitted' : $status; }
