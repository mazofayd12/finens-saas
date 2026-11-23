<?php
require_once __DIR__ . '/config/app.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

$currentUser = getCurrentUser();
$activeCompanyId = (int)($currentUser['active_company_id'] ?? 0);
if ($activeCompanyId <= 0) {
    echo json_encode(['success' => false, 'error' => 'الشركة غير محددة']);
    exit;
}

$expenseId = (int)($_REQUEST['id'] ?? ($_REQUEST['expense_id'] ?? 0));
if ($expenseId <= 0) {
    echo json_encode(['success' => false, 'error' => 'معرف السند غير صالح']);
    exit;
}

$db = getDBConnection();
ensureAuditLogsTable();

// Verify expense belongs to tenant
$chk = $db->prepare("
    SELECT e.*, ec.name as category_name, p.name as project_name, u.name as creator_name
    FROM expenses e
    LEFT JOIN expense_categories ec ON e.category_id = ec.id
    LEFT JOIN projects p ON e.project_id = p.id
    LEFT JOIN users u ON e.user_id = u.id
    WHERE e.id = :id AND e.company_id = :cid
");
$chk->execute([':id' => $expenseId, ':cid' => $activeCompanyId]);
$expense = $chk->fetch(PDO::FETCH_ASSOC);

if (!$expense) {
    echo json_encode(['success' => false, 'error' => 'السند غير موجود أو ليس لديك صلاحية']);
    exit;
}

// Fetch all audit logs for this expense
$logsStmt = $db->prepare("
    SELECT *
    FROM audit_logs
    WHERE company_id = :cid AND module = 'expenses' AND record_id = :rid
    ORDER BY id DESC
");
$logsStmt->execute([':cid' => $activeCompanyId, ':rid' => $expenseId]);
$logs = $logsStmt->fetchAll(PDO::FETCH_ASSOC);

$formattedLogs = [];
foreach ($logs as $l) {
    $oldVals = !empty($l['old_values']) ? json_decode($l['old_values'], true) : null;
    $newVals = !empty($l['new_values']) ? json_decode($l['new_values'], true) : null;
    
    $formattedLogs[] = [
        'id' => (int)$l['id'],
        'action' => $l['action'],
        'summary' => $l['summary'],
        'user_name' => $l['user_name'] ?: 'مستخدم النظام',
        'user_role' => $l['user_role'] ?: 'عضو',
        'ip_address' => $l['ip_address'] ?: '—',
        'device_info' => $l['device_info'] ?: 'غير محدد',
        'created_at' => $l['created_at'],
        'created_at_formatted' => date('Y-m-d H:i:s', strtotime($l['created_at'])),
        'old_values' => $oldVals,
        'new_values' => $newVals
    ];
}

echo json_encode([
    'success' => true,
    'expense' => [
        'id' => (int)$expense['id'],
        'voucher_number' => $expense['voucher_number'] ?: ('VCH-' . $expense['id']),
        'title' => $expense['title'],
        'amount' => (float)$expense['amount'],
        'currency' => $expense['currency'] ?: 'ر.س',
        'is_edited' => (int)($expense['is_edited'] ?? 0),
        'edit_count' => (int)($expense['edit_count'] ?? 0),
        'last_edited_at' => $expense['last_edited_at'],
        'creator_name' => $expense['creator_name'] ?: 'غير محدد',
        'project_name' => $expense['project_name'] ?: 'عام'
    ],
    'logs' => $formattedLogs
], JSON_UNESCAPED_UNICODE);
