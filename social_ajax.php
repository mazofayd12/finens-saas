<?php
/**
 * AJAX Endpoint for Social Media Interactions
 */

require_once __DIR__ . '/config/app.php';
requireLogin();

require_once __DIR__ . '/includes/social/SocialSecurity.php';
require_once __DIR__ . '/includes/social/SocialMediaManager.php';
require_once __DIR__ . '/includes/social/SocialScheduler.php';

header('Content-Type: application/json; charset=UTF-8');

$currentUser = getCurrentUser();
$activeCompanyId = (int)($currentUser['active_company_id'] ?? $currentUser['company_id']);

if (empty($activeCompanyId)) {
    echo json_encode(['success' => false, 'error' => 'لا توجد شركة نشطة.']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$manager = new SocialMediaManager();

// 1. Upload Media File
if ($action === 'upload_media') {
    requireNotViewOnly();
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'خطأ في التحقق من أمان الطلب (CSRF).']);
        exit;
    }

    if (empty($_FILES['file'])) {
        echo json_encode(['success' => false, 'error' => 'لم يتم إرسال أي ملف.']);
        exit;
    }

    $postId = !empty($_POST['post_id']) ? (int)$_POST['post_id'] : null;
    $res = $manager->uploadMediaFile($activeCompanyId, $_FILES['file'], $postId);
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

// 2. Delete Media File
if ($action === 'delete_media') {
    requireNotViewOnly();
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'خطأ أمني (CSRF).']);
        exit;
    }

    $mediaId = (int)($_POST['media_id'] ?? 0);
    $ok = $manager->deleteMediaFile($activeCompanyId, $mediaId);
    echo json_encode(['success' => $ok]);
    exit;
}

// 3. Reschedule Post
if ($action === 'reschedule_post') {
    requireNotViewOnly();
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'خطأ أمني (CSRF).']);
        exit;
    }

    $postId = (int)($_POST['post_id'] ?? 0);
    $newDate = trim($_POST['scheduled_at'] ?? '');

    if (empty($postId) || empty($newDate)) {
        echo json_encode(['success' => false, 'error' => 'البيانات غير مكتملة.']);
        exit;
    }

    $ok = $manager->reschedulePost($activeCompanyId, $postId, $newDate);
    echo json_encode(['success' => $ok]);
    exit;
}

// 4. Manual Cron Execution (from Settings)
if ($action === 'trigger_cron_manual') {
    requireNotViewOnly();
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'خطأ أمني (CSRF).']);
        exit;
    }

    $scheduler = new SocialScheduler();
    $res = $scheduler->run();
    echo json_encode(['success' => true, 'result' => $res], JSON_UNESCAPED_UNICODE);
    exit;
}

// 5. Get Calendar Posts (JSON)
if ($action === 'get_calendar_posts') {
    $start = $_GET['start'] ?? date('Y-m-01');
    $end = $_GET['end'] ?? date('Y-m-t');
    $posts = $manager->getCalendarPosts($activeCompanyId, $start, $end);
    echo json_encode(['success' => true, 'posts' => $posts], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['success' => false, 'error' => 'إجراء غير معروف.']);
