<?php
/**
 * Unified OAuth Callback Handler for Social Media Integrations
 * Handles redirects from Meta (Facebook & Instagram), TikTok, and Snapchat
 */

require_once __DIR__ . '/config/app.php';
requireLogin();

require_once __DIR__ . '/includes/social/SocialSecurity.php';
require_once __DIR__ . '/includes/social/SocialMediaManager.php';

$currentUser = getCurrentUser();
$activeCompanyId = (int)($currentUser['active_company_id'] ?? $currentUser['company_id']);

if (empty($activeCompanyId)) {
    die('الحساب غير مرتبط بأي شركة نشطة.');
}

$platform = strtolower(trim($_GET['platform'] ?? ''));
$state = $_GET['state'] ?? '';
$code = $_GET['code'] ?? '';
$error = $_GET['error'] ?? '';
$errorDesc = $_GET['error_description'] ?? $_GET['error_message'] ?? '';

// Check if user cancelled authorization on the platform
if (!empty($error)) {
    $msg = 'تم إلغاء عملية الربط أو رفض الأذونات: ' . htmlspecialchars($errorDesc ?: $error);
    setFlash('error', $msg);
    header('Location: social_accounts.php');
    exit;
}

if (empty($platform) || empty($state) || empty($code)) {
    setFlash('error', 'طلب غير مكتمل أو مفقود (كود التحقق أو منصة الربط غير موجودة).');
    header('Location: social_accounts.php');
    exit;
}

// Validate OAuth State to prevent CSRF
$isValidState = SocialSecurity::validateOAuthState($state, $activeCompanyId, $platform);
if (!$isValidState) {
    setFlash('error', 'انتهت صلاحية جلسة التفويض أو فشل التحقق الأمني (CSRF State Mismatch). يرجى إعادة المحاولة.');
    header('Location: social_accounts.php');
    exit;
}

$manager = new SocialMediaManager();

try {
    $service = $manager->getService($platform);
    $redirectUri = SocialSecurity::getAppBaseUrl(getDBConnection()) . "/social_oauth_callback.php?platform={$platform}";

    $result = $service->handleOAuthCallback($code, $redirectUri);

    if (!$result['success']) {
        setFlash('error', 'فشل إتمام ربط الحساب: ' . $result['error']);
        header('Location: social_accounts.php');
        exit;
    }

    // Save account securely with encrypted tokens
    $accountId = $manager->saveAccount($activeCompanyId, [
        'platform' => $platform,
        'account_id' => $result['account_id'],
        'account_name' => $result['account_name'],
        'account_username' => $result['account_username'] ?? null,
        'avatar_url' => $result['avatar_url'] ?? null,
        'access_token' => $result['access_token'],
        'refresh_token' => $result['refresh_token'] ?? null,
        'expires_in' => $result['expires_in'] ?? null,
        'metadata' => $result['metadata'] ?? []
    ]);

    setFlash('success', "تم ربط حساب ({$result['account_name']}) بنجاح! أصبح جاهزاً لنشر المحتوى والجدولة.");
    header('Location: social_accounts.php');
    exit;
} catch (Exception $e) {
    setFlash('error', 'حدث خطأ أثناء معالجة تفويض المنصة: ' . $e->getMessage());
    header('Location: social_accounts.php');
    exit;
}
