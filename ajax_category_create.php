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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'طريقة الطلب غير صحيحة']);
    exit;
}

// Support both JSON payload and standard POST
$rawInput = file_get_contents('php://input');
$jsonData = json_decode($rawInput, true);

$csrfToken = $_POST['csrf_token'] ?? ($jsonData['csrf_token'] ?? '');
if (!verifyCSRFToken($csrfToken)) {
    echo json_encode(['success' => false, 'error' => 'خطأ في التحقق من صحة الطلب (CSRF)']);
    exit;
}

$name = trim($_POST['name'] ?? ($jsonData['name'] ?? ''));
if (empty($name)) {
    echo json_encode(['success' => false, 'error' => 'يرجى كتابة اسم البند']);
    exit;
}

$db = getDBConnection();

// Check if category already exists (case-insensitive)
$chk = $db->prepare("SELECT id, name FROM expense_categories WHERE company_id = :cid AND TRIM(LOWER(name)) = LOWER(:name) LIMIT 1");
$chk->execute([':cid' => $activeCompanyId, ':name' => $name]);
$existing = $chk->fetch();

if ($existing) {
    echo json_encode([
        'success' => true,
        'id' => (int)$existing['id'],
        'name' => $existing['name'],
        'already_exists' => true,
        'message' => 'البند موجود مسبقاً وتم تحديده'
    ]);
    exit;
}

try {
    $ins = $db->prepare("
        INSERT INTO expense_categories (company_id, name, icon, is_active, created_at)
        VALUES (:cid, :name, 'fa-tags', 1, :now)
    ");
    $ins->execute([
        ':cid' => $activeCompanyId,
        ':name' => $name,
        ':now' => date('Y-m-d H:i:s')
    ]);
    $newId = (int)$db->lastInsertId();

    echo json_encode([
        'success' => true,
        'id' => $newId,
        'name' => $name,
        'created' => true,
        'message' => 'تم إضافة البند الجديد واعتماده بنجاح'
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'حدث خطأ أثناء الحفظ: ' . $e->getMessage()]);
}
exit;
