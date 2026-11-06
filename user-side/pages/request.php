<?php
$selectedDocument = null;
$searchError = false;
if (isset($_GET['search'])) {
    $search = query_input('search');
    $statement = db()->prepare('SELECT * FROM documents WHERE name = :name AND is_active = 1 LIMIT 1');
    $statement->execute(['name' => $search]);
    $selectedDocument = $statement->fetch() ?: null;
    $searchError = !$selectedDocument;
} elseif (isset($_GET['document'])) {
    $selectedDocument = fetch_document((int) query_input('document'));
    $searchError = !$selectedDocument;
}
$draft = $_SESSION['request_draft'] ?? null;
$summary = query_input('stage') === 'summary' && $draft && $draft['user_id'] === (int) $user['id'] && $selectedDocument && (int) $draft['document']['id'] === (int) $selectedDocument['id'];
if ($summary) $selectedDocument = $draft['document'];
?>
<?php if ($selectedDocument): ?>
<div class="step-label"><span class="step-dot">1</span> Document <span>—</span><span class="step-dot <?= !$summary ? 'current' : '' ?>">2</span> Details <span>—</span><span class="step-dot <?= $summary ? 'current' : '' ?>">3</span> Summary</div>
<section class="panel document-details"><p class="eyebrow"><?= $summary ? 'REQUEST SUMMARY' : 'SELECTED DOCUMENT' ?></p><h2><?= e($selectedDocument['name']) ?></h2><p><?= e($selectedDocument['description']) ?></p><dl class="details"><div><dt>Requirements</dt><dd><?= nl2br(e($selectedDocument['requirements'])) ?></dd></div><div><dt>Fee</dt><dd>₱<?= number_format((float) $selectedDocument['fee'], 2) ?></dd></div><div><dt>Processing time</dt><dd><?= e($selectedDocument['processing_time']) ?></dd></div></dl>
<?php if ($summary): ?>
    <dl class="summary-details"><dt>Purpose</dt><dd><?= nl2br(e($draft['purpose'])) ?></dd><dt>Additional Information</dt><dd><?= nl2br(e($draft['additional_information'])) ?></dd></dl>
    <div class="button-row"><a class="button secondary" href="index.php?page=request&document=<?= (int) $selectedDocument['id'] ?>&focus=purpose">Back and Edit</a><form action="action.php" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="submit_request"><input type="hidden" name="submission_key" value="<?= e($draft['key']) ?>"><button class="button primary" type="submit">Submit Request</button></form></div>
<?php else:
    $saved = $_SESSION['forms']['request'] ?? [];
    if ((int) ($saved['document_id'] ?? 0) !== (int) $selectedDocument['id']) $saved = [];
?>
    <form action="action.php" method="post" class="form-stack request-form" novalidate>
        <?= csrf_field() ?><input type="hidden" name="action" value="review_request"><input type="hidden" name="document_id" value="<?= (int) $selectedDocument['id'] ?>">
        <label>Purpose<textarea id="purpose" name="purpose" rows="3" maxlength="2000" required <?= query_input('focus') !== 'additional_information' ? 'autofocus' : '' ?>><?= e($saved['purpose'] ?? '') ?></textarea></label>
        <label>Additional Information<textarea id="additional_information" name="additional_information" rows="4" maxlength="3000" required <?= query_input('focus') === 'additional_information' ? 'autofocus' : '' ?>><?= e($saved['additional_information'] ?? '') ?></textarea></label>
        <button class="button primary" type="submit">Review Request Summary</button>
    </form>
<?php endif; ?></section>
<?php else:
$documents = db()->query('SELECT * FROM documents WHERE is_active = 1 ORDER BY name')->fetchAll();
?>
<form class="search-form" action="index.php" method="get"><input type="hidden" name="page" value="request"><label class="sr-only" for="document-search">Search Document</label><input id="document-search" type="search" name="search" value="<?= e(query_input('search')) ?>" placeholder="Enter a document name"><button class="button primary" type="submit">Search Document</button></form>
<section class="document-grid" aria-label="Available documents"><?php foreach ($documents as $document): ?><article class="panel document-card"><span class="document-symbol" aria-hidden="true">▤</span><h2><?= e($document['name']) ?></h2><p><?= e($document['description']) ?></p><div class="document-meta"><strong>₱<?= number_format((float) $document['fee'], 2) ?></strong><span><?= e($document['processing_time']) ?></span></div><a class="button secondary" href="index.php?page=request&document=<?= (int) $document['id'] ?>">View and Request Document <span aria-hidden="true">↗</span></a></article><?php endforeach; ?></section>
<?php if (!$documents): ?><p class="empty">No available documents.</p><?php endif; ?>
<?php if ($searchError): ?><dialog data-auto-open data-lock-dialog aria-labelledby="document-error"><h2 id="document-error">Document Doesn't Exist</h2><a class="button primary" href="index.php?page=request">OK</a></dialog><?php endif; ?>
<?php endif; ?>
