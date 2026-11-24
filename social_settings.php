<?php
/**
 * Social Media Center - Settings & Shared Hosting Cron Setup
 */

require_once __DIR__ . '/config/app.php';
requireLogin();
requireModule('social_media');

require_once __DIR__ . '/includes/social/SocialSecurity.php';
require_once __DIR__ . '/includes/social/SocialMediaManager.php';

$currentUser = getCurrentUser();
$activeCompanyId = (int)($currentUser['active_company_id'] ?? $currentUser['company_id']);

$manager = new SocialMediaManager();

// Only company admins and super admins can configure platform settings
$canEditSettings = isCompanyAdmin();

// Handle Save Settings POST action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEditSettings) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('خطأ في التحقق من صحة الطلب (CSRF)');
    }

    $action = $_POST['action'] ?? '';
    if ($action === 'save_platform_keys') {
        $data = [
            'meta_app_id' => trim($_POST['meta_app_id'] ?? ''),
            'meta_api_version' => trim($_POST['meta_api_version'] ?? 'v20.0'),
            'tiktok_client_key' => trim($_POST['tiktok_client_key'] ?? ''),
            'snapchat_client_id' => trim($_POST['snapchat_client_id'] ?? ''),
            'social_app_public_url' => trim($_POST['social_app_public_url'] ?? '')
        ];

        // Only update secrets if new values provided
        if (!empty($_POST['meta_app_secret'])) {
            $data['meta_app_secret'] = trim($_POST['meta_app_secret']);
        }
        if (!empty($_POST['tiktok_client_secret'])) {
            $data['tiktok_client_secret'] = trim($_POST['tiktok_client_secret']);
        }
        if (!empty($_POST['snapchat_client_secret'])) {
            $data['snapchat_client_secret'] = trim($_POST['snapchat_client_secret']);
        }

        $manager->savePlatformSettings($data);
        setFlash('success', 'تم حفظ وتحديث إعدادات منصات التواصل الاجتماعي وتشفير المفاتيح بنجاح!');
        header('Location: social_settings.php');
        exit;
    }
}

$settings = $manager->getPlatformSettings();
$baseUrl = SocialSecurity::getAppBaseUrl(getDBConnection());
$activeTab = $_GET['tab'] ?? 'keys';

$pageTitle = 'إدارة السوشيال ميديا - الإعدادات والجدولة';
include_once __DIR__ . '/includes/header.php';
?>

<?php include_once __DIR__ . '/includes/social_nav.php'; ?>

<!-- Settings Sub-Tabs -->
<div class="flex items-center space-x-2 space-x-reverse mb-6 border-b border-slate-200 pb-2">
    <a href="social_settings.php?tab=keys" class="flex items-center gap-2 px-4 py-2.5 rounded-2xl text-xs font-bold transition <?= $activeTab === 'keys' ? 'bg-purple-600 text-white shadow-md shadow-purple-600/20' : 'bg-white hover:bg-slate-100 text-slate-700' ?>">
        <i class="fa-solid fa-key"></i>
        <span>مفاتيح وتطبيقات المطورين (API Keys)</span>
    </a>
    <a href="social_settings.php?tab=cron" class="flex items-center gap-2 px-4 py-2.5 rounded-2xl text-xs font-bold transition <?= $activeTab === 'cron' ? 'bg-purple-600 text-white shadow-md shadow-purple-600/20' : 'bg-white hover:bg-slate-100 text-slate-700' ?>">
        <i class="fa-solid fa-server"></i>
        <span>إعداد الجدولة على الاستضافة المشتركة (Cron Job)</span>
    </a>
</div>

<?php if ($activeTab === 'keys'): ?>
    <!-- TAB 1: DEVELOPER CREDENTIALS & OAUTH CALLBACKS -->
    <div class="space-y-6">
        
        <!-- Callback URLs Helper Card -->
        <div class="bg-gradient-to-r from-purple-50 to-indigo-50/60 p-5 sm:p-6 rounded-3xl border border-purple-200 shadow-xs">
            <div class="flex items-start gap-3.5 mb-4">
                <div class="w-10 h-10 rounded-2xl bg-purple-600 text-white flex items-center justify-center text-lg shrink-0 shadow-md shadow-purple-600/20">
                    <i class="fa-solid fa-arrows-split-up-and-left"></i>
                </div>
                <div>
                    <h3 class="font-black text-slate-900 text-sm sm:text-base">روابط إعادة التوجيه الرسمية (OAuth Redirect URIs)</h3>
                    <p class="text-xs text-slate-600 font-medium mt-0.5">
                        انسخ هذه الروابط والصقها في بوابات المطورين (Meta Developers, TikTok Developer, Snapchat) لتفعيل تسجيل الدخول والتفويض الرسمي:
                    </p>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-xs">
                <!-- Meta Redirect -->
                <div class="p-3 bg-white rounded-2xl border border-purple-100 space-y-1">
                    <div class="flex items-center justify-between">
                        <strong class="font-bold text-blue-700 flex items-center gap-1">
                            <i class="fa-brands fa-meta"></i> Meta (Facebook & Instagram):
                        </strong>
                        <button type="button" onclick="copyToClipboard('<?= $baseUrl ?>/social_oauth_callback.php?platform=facebook')" class="text-purple-600 hover:text-purple-800 font-bold text-[11px]">
                            <i class="fa-regular fa-copy ml-1"></i> نسخ
                        </button>
                    </div>
                    <code class="block font-mono text-[10px] text-slate-600 bg-slate-50 p-1.5 rounded-lg truncate dir-ltr text-left">
                        <?= $baseUrl ?>/social_oauth_callback.php?platform=facebook
                    </code>
                </div>

                <!-- TikTok Redirect -->
                <div class="p-3 bg-white rounded-2xl border border-purple-100 space-y-1">
                    <div class="flex items-center justify-between">
                        <strong class="font-bold text-slate-900 flex items-center gap-1">
                            <i class="fa-brands fa-tiktok"></i> TikTok Developers:
                        </strong>
                        <button type="button" onclick="copyToClipboard('<?= $baseUrl ?>/social_oauth_callback.php?platform=tiktok')" class="text-purple-600 hover:text-purple-800 font-bold text-[11px]">
                            <i class="fa-regular fa-copy ml-1"></i> نسخ
                        </button>
                    </div>
                    <code class="block font-mono text-[10px] text-slate-600 bg-slate-50 p-1.5 rounded-lg truncate dir-ltr text-left">
                        <?= $baseUrl ?>/social_oauth_callback.php?platform=tiktok
                    </code>
                </div>

                <!-- Snapchat Redirect -->
                <div class="p-3 bg-white rounded-2xl border border-purple-100 space-y-1 col-span-1 md:col-span-2">
                    <div class="flex items-center justify-between">
                        <strong class="font-bold text-amber-700 flex items-center gap-1">
                            <i class="fa-brands fa-snapchat"></i> Snap Kit Developer Portal:
                        </strong>
                        <button type="button" onclick="copyToClipboard('<?= $baseUrl ?>/social_oauth_callback.php?platform=snapchat')" class="text-purple-600 hover:text-purple-800 font-bold text-[11px]">
                            <i class="fa-regular fa-copy ml-1"></i> نسخ
                        </button>
                    </div>
                    <code class="block font-mono text-[10px] text-slate-600 bg-slate-50 p-1.5 rounded-lg truncate dir-ltr text-left">
                        <?= $baseUrl ?>/social_oauth_callback.php?platform=snapchat
                    </code>
                </div>
            </div>
        </div>

        <!-- Form: API Keys Configuration -->
        <form action="social_settings.php" method="POST" class="bg-white rounded-3xl border border-slate-200/80 shadow-xs p-6 space-y-6">
            <?= getCSRFInput() ?>
            <input type="hidden" name="action" value="save_platform_keys">

            <!-- General Public URL Override -->
            <div class="space-y-1.5 pb-4 border-b border-slate-100">
                <label class="block text-xs font-bold text-slate-800">
                    رابط الموقع العام (Public Base URL):
                </label>
                <input type="url" name="social_app_public_url" value="<?= htmlspecialchars($settings['social_app_public_url'] ?: $baseUrl) ?>" placeholder="https://yourdomain.com" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3.5 py-2.5 text-xs font-mono focus:bg-white focus:ring-2 focus:ring-purple-500 focus:outline-none dir-ltr text-left">
                <span class="text-[11px] text-slate-400 block">
                    يستخدم هذا الرابط لاستدعاء الصور والفيديوهات من قبل خوادم فيسبوك وإنستغرام وتيك توك أثناء النشر.
                </span>
            </div>

            <!-- Meta Keys (Facebook & Instagram) -->
            <div class="space-y-3 pb-4 border-b border-slate-100">
                <div class="flex items-center space-x-2 space-x-reverse">
                    <div class="w-7 h-7 rounded-lg bg-blue-600 text-white flex items-center justify-center text-xs">
                        <i class="fa-brands fa-meta"></i>
                    </div>
                    <h4 class="font-black text-slate-900 text-sm">بيانات تطبيق Meta (فيسبوك وإنستغرام):</h4>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 mb-1">Meta App ID</label>
                        <input type="text" name="meta_app_id" value="<?= htmlspecialchars($settings['meta_app_id'] ?? '') ?>" placeholder="مثال: 123456789012345" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs font-mono focus:bg-white focus:ring-2 focus:ring-blue-500 focus:outline-none dir-ltr text-left">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 mb-1">Meta App Secret</label>
                        <input type="password" name="meta_app_secret" placeholder="<?= !empty($settings['meta_app_secret']) ? '••••••••••••••••' : 'أدخل Secret Key' ?>" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs font-mono focus:bg-white focus:ring-2 focus:ring-blue-500 focus:outline-none dir-ltr text-left">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 mb-1">إصدار Graph API</label>
                        <input type="text" name="meta_api_version" value="<?= htmlspecialchars($settings['meta_api_version'] ?? 'v20.0') ?>" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs font-mono focus:bg-white focus:ring-2 focus:ring-blue-500 focus:outline-none dir-ltr text-left">
                    </div>
                </div>
            </div>

            <!-- TikTok Keys -->
            <div class="space-y-3 pb-4 border-b border-slate-100">
                <div class="flex items-center space-x-2 space-x-reverse">
                    <div class="w-7 h-7 rounded-lg bg-slate-900 text-white flex items-center justify-center text-xs">
                        <i class="fa-brands fa-tiktok"></i>
                    </div>
                    <h4 class="font-black text-slate-900 text-sm">بيانات تطبيق تيك توك (TikTok Developers):</h4>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 mb-1">Client Key</label>
                        <input type="text" name="tiktok_client_key" value="<?= htmlspecialchars($settings['tiktok_client_key'] ?? '') ?>" placeholder="TikTok Client Key" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs font-mono focus:bg-white focus:ring-2 focus:ring-slate-800 focus:outline-none dir-ltr text-left">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 mb-1">Client Secret</label>
                        <input type="password" name="tiktok_client_secret" placeholder="<?= !empty($settings['tiktok_client_secret']) ? '••••••••••••••••' : 'أدخل Client Secret' ?>" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs font-mono focus:bg-white focus:ring-2 focus:ring-slate-800 focus:outline-none dir-ltr text-left">
                    </div>
                </div>
            </div>

            <!-- Snapchat Keys -->
            <div class="space-y-3 pb-2">
                <div class="flex items-center space-x-2 space-x-reverse">
                    <div class="w-7 h-7 rounded-lg bg-amber-400 text-black flex items-center justify-center text-xs">
                        <i class="fa-brands fa-snapchat"></i>
                    </div>
                    <h4 class="font-black text-slate-900 text-sm">بيانات تطبيق سناب شات (Snap Kit Portal):</h4>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 mb-1">Snapchat Client ID</label>
                        <input type="text" name="snapchat_client_id" value="<?= htmlspecialchars($settings['snapchat_client_id'] ?? '') ?>" placeholder="Snapchat Client ID" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs font-mono focus:bg-white focus:ring-2 focus:ring-amber-500 focus:outline-none dir-ltr text-left">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 mb-1">Snapchat Client Secret</label>
                        <input type="password" name="snapchat_client_secret" placeholder="<?= !empty($settings['snapchat_client_secret']) ? '••••••••••••••••' : 'أدخل Client Secret' ?>" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs font-mono focus:bg-white focus:ring-2 focus:ring-amber-500 focus:outline-none dir-ltr text-left">
                    </div>
                </div>
            </div>

            <!-- Submit Button -->
            <div class="pt-2">
                <button type="submit" class="bg-purple-600 hover:bg-purple-700 text-white font-bold py-3 px-6 rounded-2xl shadow-md transition text-xs sm:text-sm">
                    حفظ وتشفير مفاتيح المنصات
                </button>
            </div>
        </form>
    </div>

<?php else: ?>
    <!-- TAB 2: SHARED HOSTING CRON SETUP GUIDE -->
    <div class="space-y-6">
        
        <!-- Status Card -->
        <div class="bg-white p-5 sm:p-6 rounded-3xl border border-slate-200/80 shadow-xs flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div class="flex items-center space-x-3.5 space-x-reverse">
                <div class="w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-xl font-bold shadow-xs">
                    <i class="fa-solid fa-server"></i>
                </div>
                <div>
                    <h3 class="font-black text-slate-900 text-base">حالة جدولة الاستضافة المشتركة (Shared Hosting Scheduler)</h3>
                    <p class="text-xs text-slate-500 font-medium mt-0.5">
                        آخر تشغيل مسجل للجدولة: 
                        <?php if (!empty($settings['social_cron_last_run'])): ?>
                            <strong class="text-emerald-700 dir-ltr inline-block font-mono font-bold"><?= date('Y/m/d h:i:s A', strtotime($settings['social_cron_last_run'])) ?></strong>
                        <?php else: ?>
                            <span class="text-amber-600 font-bold">لم يتم تشغيل Cron Job بعد</span>
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <!-- Run Manually Button -->
            <button type="button" onclick="triggerManualCron()" id="btn_manual_cron" class="bg-purple-600 hover:bg-purple-700 text-white font-bold py-2.5 px-4 rounded-xl text-xs shadow-md transition flex items-center justify-center gap-1.5 shrink-0">
                <i class="fa-solid fa-play"></i>
                <span>تشغيل الجدولة الآن تجريبياً</span>
            </button>
        </div>

        <!-- Live Output Box for Manual Test -->
        <div id="manual_cron_output_box" class="hidden bg-slate-950 text-emerald-400 p-4 rounded-2xl font-mono text-xs dir-ltr overflow-x-auto space-y-1">
            <div class="text-slate-400">// نتيجة تشغيل محرك الجدولة:</div>
            <pre id="manual_cron_output_text"></pre>
        </div>

        <!-- Setup Instructions for Hostinger & cPanel -->
        <div class="bg-white p-6 rounded-3xl border border-slate-200/80 shadow-xs space-y-5">
            <div class="flex items-center space-x-2 space-x-reverse">
                <i class="fa-solid fa-book-open text-purple-600 text-lg"></i>
                <h3 class="font-black text-slate-900 text-base">دليل إعداد الـ Cron Job على لوحة تحكم الاستضافة (Hostinger hPanel / cPanel)</h3>
            </div>

            <div class="space-y-4 text-xs text-slate-700 leading-relaxed font-medium">
                <p>
                    نظراً لأن نظامك مستضاف على <strong>استضافة مشتركة (Shared Hosting)</strong>، فإن النظام مصمم خصيصاً ليعمل بأعلى كفاءة وسرعة عبر أمر Cron Job قصير ومحمي بقفل منع التكرار، دون الحاجة لأي خوادم خاصة VPS أو Redis أو عمليات دائمة.
                </p>

                <!-- Command Box 1: PHP Direct -->
                <div class="p-4 bg-slate-50 rounded-2xl border border-slate-200 space-y-2">
                    <div class="flex items-center justify-between">
                        <strong class="font-bold text-slate-900">الأمر الموصى به (Direct PHP):</strong>
                        <button type="button" onclick="copyToClipboard('php <?= APP_PATH ?>/cron_social_publish.php > /dev/null 2>&1')" class="text-purple-600 font-bold hover:underline">
                            <i class="fa-regular fa-copy ml-1"></i> نسخ الأمر
                        </button>
                    </div>
                    <code class="block font-mono text-[11px] text-purple-700 bg-white p-2.5 rounded-xl border border-slate-200 dir-ltr text-left select-all">
                        * * * * * php <?= APP_PATH ?>/cron_social_publish.php > /dev/null 2>&1
                    </code>
                </div>

                <!-- Command Box 2: Laravel Artisan Style -->
                <div class="p-4 bg-slate-50 rounded-2xl border border-slate-200 space-y-2">
                    <div class="flex items-center justify-between">
                        <strong class="font-bold text-slate-900">أو أمر Laravel Artisan المتوافق:</strong>
                        <button type="button" onclick="copyToClipboard('php <?= APP_PATH ?>/artisan schedule:run > /dev/null 2>&1')" class="text-purple-600 font-bold hover:underline">
                            <i class="fa-regular fa-copy ml-1"></i> نسخ الأمر
                        </button>
                    </div>
                    <code class="block font-mono text-[11px] text-indigo-700 bg-white p-2.5 rounded-xl border border-slate-200 dir-ltr text-left select-all">
                        * * * * * php <?= APP_PATH ?>/artisan schedule:run > /dev/null 2>&1
                    </code>
                </div>

                <!-- Steps in Hostinger -->
                <div class="p-4 bg-purple-50/50 rounded-2xl border border-purple-100 space-y-2">
                    <h5 class="font-black text-purple-900 text-xs">خطوات الضبط في Hostinger hPanel:</h5>
                    <ol class="list-decimal list-inside space-y-1 text-slate-600 text-[11px]">
                        <li>افتح لوحة تحكم <strong>hPanel</strong> الخاصة باستضافتك.</li>
                        <li>انتقل إلى قسم <strong>Advanced &rarr; Cron Jobs</strong>.</li>
                        <li>اختر نوع المهام: <strong>Custom</strong> والتكرار: <strong>Once a minute (* * * * *)</strong>.</li>
                        <li>الصق الأمر أعلاه في خانة <strong>Command to run</strong> واضغط <strong>Save</strong>.</li>
                    </ol>
                </div>

                <!-- Timing Precision Notice -->
                <div class="p-3.5 bg-amber-50 rounded-2xl border border-amber-200 text-amber-900 text-[11px] flex items-start gap-2.5">
                    <i class="fa-solid fa-triangle-exclamation text-amber-600 text-base mt-0.5 shrink-0"></i>
                    <div>
                        <strong class="font-bold block">ملاحظة دقة توقيت النشر (Publishing Precision):</strong>
                        <span>دقة النشر تعتمد على دورة الـ Cron Job ومعدل استجابة خادم الاستضافة (عادة ما يتم الفحص كل دقيقة واحدة). المنشور المجدول سيتم إطلاقه في غضون الدقيقة التالية لوصول وقته المحدد.</span>
                    </div>
                </div>
            </div>
        </div>

    </div>
<?php endif; ?>

<script>
var csrfToken = "<?= generateCSRFToken() ?>";

function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(function() {
        alert('تم نسخ الرابط بنجاح إلى الحافظة!');
    });
}

function triggerManualCron() {
    var btn = document.getElementById('btn_manual_cron');
    var box = document.getElementById('manual_cron_output_box');
    var textEl = document.getElementById('manual_cron_output_text');

    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin ml-1"></i> جاري التشغيل...';
    box.classList.remove('hidden');
    textEl.innerText = 'جاري تنفيذ دفعة الجدولة وفحص المنشورات...';

    var fd = new FormData();
    fd.append('action', 'trigger_cron_manual');
    fd.append('csrf_token', csrfToken);

    fetch('social_ajax.php', { method: 'POST', body: fd })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-play ml-1"></i> تشغيل الجدولة الآن تجريبياً';
        textEl.innerText = JSON.stringify(data.result || data, null, 2);
    })
    .catch(function(err) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-play ml-1"></i> تشغيل الجدولة الآن تجريبياً';
        textEl.innerText = 'حدث خطأ أثناء التنفيذ: ' + err;
    });
}
</script>

<?php include_once __DIR__ . '/includes/footer.php'; ?>
