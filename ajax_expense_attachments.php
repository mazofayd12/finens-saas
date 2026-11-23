<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$expenseId = (int)($_GET['expense_id'] ?? 0);
if ($expenseId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid expense ID']);
    exit;
}

$currentUser = getCurrentUser();
$companyId = (int)($currentUser['active_company_id'] ?? 0);

ensureExpenseAttachmentsTable();
$db = getDBConnection();

$stmt = $db->prepare("
    SELECT id, expense_id, file_path, file_name, file_size, file_ext, created_at 
    FROM expense_attachments 
    WHERE expense_id = :eid AND company_id = :cid 
    ORDER BY id ASC
");
$stmt->execute([':eid' => $expenseId, ':cid' => $companyId]);
$attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($attachments)) {
    // Check if expense has legacy invoice_attachment
    $expStmt = $db->prepare("SELECT invoice_attachment FROM expenses WHERE id = :id AND company_id = :cid");
    $expStmt->execute([':id' => $expenseId, ':cid' => $companyId]);
    $invAtt = $expStmt->fetchColumn();
    if (!empty($invAtt)) {
        $cleanPath = ltrim($invAtt, '/');
        $attachments = [
            [
                'id' => 0,
                'expense_id' => $expenseId,
                'file_path' => $cleanPath,
                'file_name' => basename($cleanPath),
                'file_size' => 0,
                'file_ext' => strtolower(pathinfo($cleanPath, PATHINFO_EXTENSION)),
                'created_at' => null
            ]
        ];
    }
}

echo json_encode(['success' => true, 'attachments' => $attachments]);
