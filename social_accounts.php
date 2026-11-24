<?php
/**
 * Social Media Center - Connected Accounts Page
 * Connect Facebook Pages, Instagram Professional, TikTok, Snapchat
 */

require_once __DIR__ . '/config/app.php';
requireLogin();
requireModule('social_media');

require_once __DIR__ . '/includes/social/SocialSecurity.php';
require_once __DIR__ . '/includes/social/SocialMediaManager.php';

$currentUser = getCurrentUser();
$activeCompanyId = (int)($currentUser['active_company_id'] ?? $currentUser['company_id']);

$manager = new SocialMediaManager();
$platformSettings = $manager->getPlatformSettings();

// Handle Disconnect POST action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireNotViewOnly();
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('خطأ في التحقق من صحة الطلب (CSRF)');
    }

    $action = $_POST['action'] ?? '';
    if ($action === 'disconnect_account') {
        $accountId = (int)($_POST['account_id'] ?? 0);
        $ok = $manager->disconnectAccount($activeCompanyId, $accountId);
        if ($ok) {
            setFlash('success', 'تم فصل حساب السوشيال ميديا بنجاح.');
        } else {
            setFlash('error', 'فشل فصل الحساب.');
        }
        header('Location: social_accounts.php');
        exit;
    }
}

// Handle Connect Account Initiation (GET ?connect=platform)
if (isset($_GET['connect'])) {
    requireNotViewOnly();
    $targetPlatform = strtolower(trim($_GET['connect']));

    try {
        $service = $manager->getService($targetPlatform);
        $redirectUri = SocialSecurity::getAppBaseUrl(getDBConnection()) . "/social_oauth_callback.php?platform={$targetPlatform}";
        $state = SocialSecurity::generateOAuthState($activeCompanyId, $targetPlatform);
        
        $authUrl = $service->getAuthorizationUrl($redirectUri, $state);
        header('Location: ' . $authUrl);
        exit;
    } catch (Exception $e) {
        setFlash('error', 'فشل بدء عملية التفويض: ' . $e->getMessage());
        header('Location: social_accounts.php');
        exit;
    }
}

// Fetch all connected accounts grouped by platform
$connectedAccounts = $manager->getConnectedAccounts($activeCompanyId);
$accountsByPlatform = [];
foreach ($connectedAccounts as $acc) {
    $accountsByPlatform[$acc['platform']][] = $acc;
}

// Platform definitions
$platforms = [
    'facebook' => [
        'name' => 'فيسبوك (Facebook Pages)',
        'icon' => 'fa-brands fa-facebook-f',
        'color' => 'bg-blue-600',
        'border' => 'border-blue-200 hover:border-blue-500',
        'badge_bg' => 'bg-blue-50 text-blue-700',
        'desc' => 'نشر المنشورات والصور والألبومات والفيديوهات على صفحات فيسبوك الرسمية التابعة لشركتك.',
        'req' => 'يتطلب صفحة فيسبوك عامة (Facebook Page) تديرها بصلاحية مسؤول.',
        'is_configured' => !empty($platformSettings['meta_app_id']) && !empty($platformSettings['meta_app_secret'])
    ],
    'instagram' => [
        'name' => 'إنستغرام (Instagram Professional)',
        'icon' => 'fa-brands fa-instagram',
        'color' => 'bg-gradient-to-tr from-amber-500 via-rose-500 to-purple-600',
        'border' => 'border-pink-200 hover:border-pink-500',
        'badge_bg' => 'bg-pink-50 text-pink-700',
        'desc' => 'نشر الصور المنفردة، الألبومات (Carousels)، ومقاطع ريلز (Reels) الترويجية لشركتك.',
        'req' => 'يتطلب حساب إنستغرام أعمال أو صانع محتوى (Business / Creator) مرتبط بصفحة فيسبوك.',
        'is_configured' => !empty($platformSettings['meta_app_id']) && !empty($platformSettings['meta_app_secret'])
    ],
    'tiktok' => [
        'name' => 'تيك توك (TikTok Content Posting)',
        'icon' => 'fa-brands fa-tiktok',
        'color' => 'bg-slate-900',
        'border' => 'border-slate-300 hover:border-slate-900',
        'badge_bg' => 'bg-slate-100 text-slate-900',
        'desc' => 'نشر ومزامنة مقاطع الفيديو القصيرة مباشرة على حساب تيك توك التابع لشركتك.',
        'req' => 'يتطلب حساب تيك توك نشط مع موافقة أذونات النشر المباشر Content Posting API v2.',
        'is_configured' => !empty($platformSettings['tiktok_client_key']) && !empty($platformSettings['tiktok_client_secret'])
    ],
    'snapchat' => [
        'name' => 'سناب شات (Snapchat Public Profiles)',
        'icon' => 'fa-brands fa-snapchat',
        'color' => 'bg-amber-400 text-black',
        'border' => 'border-amber-200 hover:border-amber-400',
        'badge_bg' => 'bg-amber-50 text-amber-800',
        'desc' => 'نشر القصص (Stories) والصور ومقاطع الفيديو العمودية على الملف التعريفي العام لشركتك.',
        'req' => 'يتطلب ملفاً تعريفياً عاماً (Public Profile) معتمد من إدارة Snap Inc.',
        'is_configured' => !empty($platformSettings['snapchat_client_id']) && !empty($platformSettings['snapchat_client_secret'])
    ]
];

$pageTitle = 'إدارة السوشيال ميديا - الحسابات المتصلة';
include_once __DIR__ . '/includes/header.php';
?>

<?php include_once __DIR__ . '/includes/social_nav.php'; ?>

<!-- Top Instructions Card -->
<div class="bg-blue-50 border border-blue-200/80 rounded-3xl p-4 sm:p-5 mb-6 flex items-start gap-3.5 text-xs text-blue-900">
    <div class="w-10 h-10 rounded-2xl bg-blue-600 text-white flex items-center justify-center text-lg shrink-0 shadow-md shadow-blue-600/20">
        <i class="fa-solid fa-shield-halved"></i>
    </div>
    <div class="space-y-1">
        <h4 class="font-black text-sm">ربط رسمي وآمن عبر بروتوكول OAuth 2.0</h4>
        <p class="leading-relaxed">
            جميع عمليات الربط تتم مباشرة مع الخوادم الرسمية لمنصات التواصل الاجتماعي بتشفير عالي <strong>AES-256</strong>. لا نقوم بتخزين كلمات مرورك الخاصة، ويقتصر الوصول على صلاحيات النشر التي توافق عليها.
        </p>
    </div>
</div>

<!-- Platforms Grid (4 Supported Networks) -->
<div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-8">
    <?php foreach ($platforms as $key => $p): ?>
        <?php
        $accs = $accountsByPlatform[$key] ?? [];
        $isConnected = !empty($accs);
        ?>
        <div class="bg-white rounded-3xl border-2 <?= $p['border'] ?> p-5 sm:p-6 shadow-xs flex flex-col justify-between transition-all">
            <!-- Platform Header -->
            <div>
                <div class="flex items-center justify-between mb-3">
                    <div class="flex items-center space-x-3 space-x-reverse">
                        <div class="w-12 h-12 rounded-2xl <?= $p['color'] ?> text-white flex items-center justify-center text-xl shadow-md shrink-0">
                            <i class="<?= $p['icon'] ?>"></i>
                        </div>
                        <div>
                            <h3 class="font-black text-slate-900 text-base"><?= $p['name'] ?></h3>
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded-full <?= $isConnected ? 'bg-emerald-100 text-emerald-800 border border-emerald-300' : 'bg-slate-100 text-slate-500' ?>">
                                <?= $isConnected ? '● متصل بنجاح' : '○ غير متصل' ?>
                            </span>
                        </div>
                    </div>

                    <?php if (!$p['is_configured']): ?>
                        <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-amber-100 text-amber-800 border border-amber-300 shrink-0" title="يجب ضبط مفاتيح المطور في الإعدادات">
                            <i class="fa-solid fa-wrench ml-1"></i> بانتظار الإعدادات
                        </span>
                    <?php endif; ?>
                </div>

                <p class="text-xs text-slate-600 font-medium leading-relaxed mb-3">
                    <?= $p['desc'] ?>
                </p>

                <!-- Requirements Callout -->
                <div class="p-2.5 bg-slate-50 rounded-xl border border-slate-200/60 text-[11px] text-slate-500 mb-4 flex items-center gap-2">
                    <i class="fa-solid fa-circle-info text-slate-400"></i>
                    <span><?= $p['req'] ?></span>
                </div>

                <!-- Connected Accounts Details if connected -->
                <?php if ($isConnected): ?>
                    <div class="space-y-2 mb-4 pt-2 border-t border-slate-100">
                        <span class="text-[11px] font-bold text-slate-500 block">الحسابات النشطة لشركتك:</span>
                        <?php foreach ($accs as $acc): ?>
                            <div class="p-3 bg-purple-50/60 rounded-2xl border border-purple-100 flex items-center justify-between gap-3">
                                <div class="flex items-center space-x-2.5 space-x-reverse min-w-0">
                                    <div class="w-10 h-10 rounded-full bg-white border border-purple-200 overflow-hidden shrink-0 flex items-center justify-center text-purple-600 font-bold text-sm">
                                        <?php if (!empty($acc['avatar_url'])): ?>
                                            <img src="<?= htmlspecialchars($acc['avatar_url']) ?>" alt="صورة" class="w-full h-full object-cover">
                                        <?php else: ?>
                                            <i class="<?= $p['icon'] ?>"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="min-w-0 truncate">
                                        <strong class="text-xs font-black text-slate-900 block truncate"><?= htmlspecialchars($acc['account_name']) ?></strong>
                                        <span class="text-[10px] text-slate-500 block truncate dir-ltr text-right">
                                            <?= htmlspecialchars($acc['account_username'] ?: $acc['account_id']) ?>
                                        </span>
                                    </div>
                                </div>

                                <div class="flex items-center gap-1 shrink-0">
                                    <form method="POST" onsubmit="return confirm('هل أنت متأكد من رغبتك في فصل هذا الحساب؟ لن يتم حذف المنشورات السابقة ولكن سيتوقف النشر التلقائي عليه.')">
                                        <?= getCSRFInput() ?>
                                        <input type="hidden" name="action" value="disconnect_account">
                                        <input type="hidden" name="account_id" value="<?= $acc['id'] ?>">
                                        <button type="submit" class="text-[11px] font-bold text-rose-600 hover:text-rose-700 bg-white hover:bg-rose-50 border border-rose-200 px-2.5 py-1.5 rounded-xl transition">
                                            فصل
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Action Buttons -->
            <div class="pt-3 border-t border-slate-100 flex items-center gap-2">
                <?php if ($p['is_configured']): ?>
                    <a href="social_accounts.php?connect=<?= $key ?>" class="flex-1 inline-flex items-center justify-center gap-1.5 font-bold py-2.5 px-4 rounded-xl text-xs transition shadow-xs <?= $isConnected ? 'bg-slate-100 hover:bg-slate-200 text-slate-700' : 'bg-purple-600 hover:bg-purple-700 text-white shadow-purple-600/20' ?>">
                        <i class="fa-solid fa-link"></i>
                        <span><?= $isConnected ? 'إعادة تفويض / ربط حساب إضافي' : 'ربط الحساب الآن' ?></span>
                    </a>
                <?php else: ?>
                    <a href="social_settings.php" class="flex-1 inline-flex items-center justify-center gap-1.5 font-bold py-2.5 px-4 rounded-xl text-xs bg-amber-500 hover:bg-amber-600 text-white transition shadow-xs">
                        <i class="fa-solid fa-gear"></i>
                        <span>تهيئة المفاتيح في الإعدادات</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php include_once __DIR__ . '/includes/footer.php'; ?>
