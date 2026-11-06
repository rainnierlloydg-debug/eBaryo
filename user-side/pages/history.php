<?php
$statement = db()->prepare('SELECT id, document_name, created_at, status FROM document_requests WHERE user_id = :id ORDER BY created_at DESC, id DESC');
$statement->execute(['id' => $user['id']]);
$requests = $statement->fetchAll();
?>
<section class="panel"><div class="section-title"><h2>Submitted Requests Table</h2><span class="muted"><?= count($requests) ?> requests</span></div><div class="table-scroll"><table><thead><tr><th>Request ID</th><th>Document Type</th><th>Date</th><th>Status</th></tr></thead><tbody><?php foreach ($requests as $request): ?><tr><td class="request-number"><?= e(request_id((int) $request['id'])) ?></td><td><?= e($request['document_name']) ?></td><td><?= e(date_label($request['created_at'])) ?></td><td><span class="badge"><?= e($request['status']) ?></span></td></tr><?php endforeach; ?></tbody></table></div><?php if (!$requests): ?><p class="empty">No submitted requests.</p><?php endif; ?></section>
