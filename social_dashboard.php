<?php
/**
 * Social Media Center - Overview Dashboard
 */

require_once __DIR__ . '/config/app.php';
requireLogin();
requireModule('social_media');

require_once __DIR__ . '/includes/social/SocialSecurity.php';
require_once __DIR__ . '/includes/social/SocialMediaManager.php';

$currentUser = getCurrentUser();
$activeCompanyId = (int)($currentUser['active_company_id'] ?? $currentUser['company_id']);

$manager = new SocialMediaManager();
$stats = $manager->getDashboardStats($activeCompanyId);
$connectedAccounts = $manager->getConnectedAccounts($activeCompanyId);

$pageTitle = 'إدارة السوشيال ميديا - لوحة التحكم';
include_once __DIR__ . '/includes/header.php';
?>

<?php include_once __DIR__ . '/includes/social_nav.php'; ?>

<!-- Stat Cards Grid -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <!-- 1. Connected Accounts -->
    <div class="bg-white p-5 rounded-3xl border border-slate-200/80 shadow-xs flex items-center justify-between">
        <div>
            <p class="text-xs font-bold text-slate-500 mb-1">الحسابات المتصلة</p>
            <h3 class="text-2xl font-black text-slate-900"><?= $stats['total_accounts'] ?></h3>
            <p class="text-[11px] text-purple-600 font-bold mt-1">
                <a href="social_accounts.php" class="hover:underline">إدارة الحسابات &larr;</a>
            </p>
        </div>
        <div class="w-12 h-12 rounded-2xl bg-purple-50 text-purple-600 flex items-center justify-center text-xl shadow-xs">
            <i class="fa-solid fa-link"></i>
        </div>
    </div>

    <!-- 2. Scheduled Posts -->
    <div class="bg-white p-5 rounded-3xl border border-slate-200/80 shadow-xs flex items-center justify-between">
        <div>
            <p class="text-xs font-bold text-slate-500 mb-1">منشورات مجدولة</p>
            <h3 class="text-2xl font-black text-blue-600"><?= $stats['scheduled_posts'] ?></h3>
            <p class="text-[11px] text-blue-600 font-bold mt-1">
                <a href="social_calendar.php" class="hover:underline">عرض التقويم &larr;</a>
            </p>
        </div>
        <div class="w-12 h-12 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center text-xl shadow-xs">
            <i class="fa-solid fa-clock"></i>
        </div>
    </div>

    <!-- 3. Published Posts -->
    <div class="bg-white p-5 rounded-3xl border border-slate-200/80 shadow-xs flex items-center justify-between">
        <div>
            <p class="text-xs font-bold text-slate-500 mb-1">تم نشرها بنجاح</p>
            <h3 class="text-2xl font-black text-emerald-600"><?= $stats['published_posts'] ?></h3>
            <p class="text-[11px] text-emerald-600 font-bold mt-1">
                <a href="social_posts.php?status=published" class="hover:underline">سجل المنشورات &larr;</a>
            </p>
        </div>
        <div class="w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-xl shadow-xs">
            <i class="fa-solid fa-circle-check"></i>
        </div>
    </div>

    <!-- 4. Failed / Issues -->
    <div class="bg-white p-5 rounded-3xl border border-slate-200/80 shadow-xs flex items-center justify-between">
        <div>
            <p class="text-xs font-bold text-slate-500 mb-1">محاولات فاشلة / معلقة</p>
            <h3 class="text-2xl font-black <?= $stats['failed_posts'] > 0 ? 'text-rose-600' : 'text-slate-400' ?>"><?= $stats['failed_posts'] ?></h3>
            <p class="text-[11px] text-slate-500 font-medium mt-1">
                <?php if ($stats['failed_posts'] > 0): ?>
                    <a href="social_posts.php?status=failed" class="text-rose-600 font-bold hover:underline">فحص الأخطاء وإعادة المحاولة &larr;</a>
                <?php else: ?>
                    <span>لا توجد أي أخطاء نشطة</span>
                <?php endif; ?>
            </p>
        </div>
        <div class="w-12 h-12 rounded-2xl <?= $stats['failed_posts'] > 0 ? 'bg-rose-50 text-rose-600' : 'bg-slate-50 text-slate-400' ?> flex items-center justify-center text-xl shadow-xs">
            <i class="fa-solid fa-triangle-exclamation"></i>
        </div>
    </div>
</div>

<?php if (empty($connectedAccounts)): ?>
    <!-- Empty State: Onboarding for New Tenants -->
    <div class="bg-gradient-to-br from-purple-50 via-indigo-50/50 to-pink-50/40 p-6 sm:p-8 rounded-3xl border-2 border-dashed border-purple-200 mb-6 text-center">
        <div class="max-w-xl mx-auto space-y-4">
            <div class="w-16 h-16 rounded-3xl bg-purple-600 text-white flex items-center justify-center text-2xl mx-auto shadow-lg shadow-purple-600/30">
                <i class="fa-solid fa-rocket"></i>
            </div>
            <div>
                <h3 class="text-lg sm:text-xl font-black text-slate-900">مرحباً بك في مركز إدارة السوشيال ميديا لشركتك</h3>
                <p class="text-xs sm:text-sm text-slate-600 font-medium mt-1 leading-relaxed">
                    لم تقم بربط أي حسابات تواصل اجتماعي لشركتك حتى الآن. يمكنك ربط صفحات فيسبوك، إنستغرام للأعمال، تيك توك، وسناب شات لنشر المحتوى وإدارته من مكان واحد بكل سهولة وأمان.
                </p>
            </div>

            <!-- 3 Easy Steps -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-right pt-2">
                <div class="p-3.5 bg-white rounded-2xl border border-purple-100 shadow-xs">
                    <span class="w-6 h-6 rounded-full bg-purple-100 text-purple-700 font-black text-xs inline-flex items-center justify-center mb-2">1</span>
                    <strong class="block text-xs font-bold text-slate-900">اربط حساباتك الرسمية</strong>
                    <span class="text-[11px] text-slate-500">اختر المنصة وسجّل الدخول عبر التفويض الرسمي OAuth.</span>
                </div>
                <div class="p-3.5 bg-white rounded-2xl border border-purple-100 shadow-xs">
                    <span class="w-6 h-6 rounded-full bg-indigo-100 text-indigo-700 font-black text-xs inline-flex items-center justify-center mb-2">2</span>
                    <strong class="block text-xs font-bold text-slate-900">أنشئ منشورك وجهّزه</strong>
                    <span class="text-[11px] text-slate-500">ارفع الصور أو الفيديوهات، اكتب الوصف ونسّق الهاشتاجات.</span>
                </div>
                <div class="p-3.5 bg-white rounded-2xl border border-purple-100 shadow-xs">
                    <span class="w-6 h-6 rounded-full bg-emerald-100 text-emerald-700 font-black text-xs inline-flex items-center justify-center mb-2">3</span>
                    <strong class="block text-xs font-bold text-slate-900">انشر فوراً أو جدول</strong>
                    <span class="text-[11px] text-slate-500">انشر بضغطة واحدة على جميع القنوات أو جدول للتوقيت الأنسب.</span>
                </div>
            </div>

            <div class="pt-2">
                <a href="social_accounts.php" class="inline-flex items-center gap-2 bg-purple-600 hover:bg-purple-700 text-white font-bold px-6 py-3 rounded-2xl shadow-lg shadow-purple-600/30 text-xs sm:text-sm transition transform active:scale-95">
                    <i class="fa-solid fa-link"></i>
                    <span>ابدأ بربط حسابك الأول الآن</span>
                </a>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- 2-Column Content Layout -->
<div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
    <!-- Right Column: Upcoming Scheduled Posts (lg:col-span-7) -->
    <div class="lg:col-span-7 space-y-6">
        <div class="bg-white rounded-3xl border border-slate-200/80 shadow-xs p-5">
            <div class="flex items-center justify-between pb-4 border-b border-slate-100 mb-4">
                <div class="flex items-center space-x-2.5 space-x-reverse">
                    <div class="w-9 h-9 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center text-base font-bold">
                        <i class="fa-solid fa-calendar-check"></i>
                    </div>
                    <div>
                        <h3 class="font-black text-slate-900 text-sm sm:text-base">المنشورات المجدولة القادمة</h3>
                        <p class="text-[11px] text-slate-500">المنشورات التي سيتم نشرها تلقائياً حسب الموعد المحدد</p>
                    </div>
                </div>
                <a href="social_calendar.php" class="text-xs text-blue-600 font-bold hover:underline">
                    التقويم الكامل &larr;
                </a>
            </div>

            <?php if (empty($stats['upcoming_posts'])): ?>
                <div class="py-8 text-center text-slate-400 space-y-2">
                    <i class="fa-regular fa-calendar-xmark text-4xl opacity-50 block"></i>
                    <p class="text-xs font-medium">لا توجد منشورات مجدولة حالياً</p>
                    <a href="social_post_create.php" class="inline-block text-xs font-bold text-purple-600 hover:underline">
                        + جدولة منشور جديد
                    </a>
                </div>
            <?php else: ?>
                <div class="divide-y divide-slate-100">
                    <?php foreach ($stats['upcoming_posts'] as $up): ?>
                        <div class="py-3.5 flex items-start space-x-3.5 space-x-reverse first:pt-0 last:pb-0">
                            <!-- Thumbnail -->
                            <div class="w-14 h-14 rounded-2xl bg-slate-100 overflow-hidden shrink-0 border border-slate-200 flex items-center justify-center text-slate-400">
                                <?php if (!empty($up['thumbnail']) && file_exists(APP_PATH . '/' . $up['thumbnail'])): ?>
                                    <img src="<?= htmlspecialchars($up['thumbnail']) ?>" alt="معاينة" class="w-full h-full object-cover">
                                <?php else: ?>
                                    <i class="fa-solid fa-pen-nib text-lg text-slate-300"></i>
                                <?php endif; ?>
                            </div>

                            <!-- Content -->
                            <div class="flex-1 min-w-0">
                                <p class="text-xs font-bold text-slate-800 line-clamp-2 leading-relaxed mb-1">
                                    <?= htmlspecialchars($up['caption']) ?>
                                </p>
                                <div class="flex items-center gap-2 flex-wrap text-[11px] text-slate-500">
                                    <span class="inline-flex items-center gap-1 font-mono font-bold text-blue-700 bg-blue-50 px-2 py-0.5 rounded-lg dir-ltr">
                                        <i class="fa-regular fa-clock text-[10px]"></i>
                                        <?= date('Y/m/d h:i A', strtotime($up['scheduled_at'])) ?>
                                    </span>
                                    <!-- Platform Pills -->
                                    <?php foreach ($up['platforms'] as $pl): ?>
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md font-bold text-[10px] <?= $pl['platform'] === 'facebook' ? 'bg-blue-100 text-blue-800' : ($pl['platform'] === 'instagram' ? 'bg-pink-100 text-pink-800' : ($pl['platform'] === 'tiktok' ? 'bg-slate-900 text-white' : 'bg-yellow-100 text-yellow-900')) ?>">
                                            <i class="fa-brands fa-<?= $pl['platform'] === 'facebook' ? 'facebook-f' : ($pl['platform'] === 'instagram' ? 'instagram' : ($pl['platform'] === 'tiktok' ? 'tiktok' : 'snapchat')) ?>"></i>
                                            <?= htmlspecialchars($pl['account_name'] ?? $pl['platform']) ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <a href="social_post_create.php?edit=<?= $up['id'] ?>" class="text-xs font-bold text-slate-400 hover:text-purple-600 p-2 rounded-xl hover:bg-slate-50 transition" title="تعديل">
                                <i class="fa-solid fa-pen"></i>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Left Column: Recent Activity & Logs (lg:col-span-5) -->
    <div class="lg:col-span-5 space-y-6">
        <div class="bg-white rounded-3xl border border-slate-200/80 shadow-xs p-5">
            <div class="flex items-center justify-between pb-4 border-b border-slate-100 mb-4">
                <div class="flex items-center space-x-2 space-x-reverse">
                    <div class="w-9 h-9 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center text-base font-bold">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                    </div>
                    <div>
                        <h3 class="font-black text-slate-900 text-sm sm:text-base">سجل النشاط الأخير</h3>
                        <p class="text-[11px] text-slate-500">محاولات النشر والتفويض المباشرة</p>
                    </div>
                </div>
                <a href="social_history.php" class="text-xs text-purple-600 font-bold hover:underline">
                    السجل الكامل &larr;
                </a>
            </div>

            <?php if (empty($stats['recent_logs'])): ?>
                <div class="py-8 text-center text-slate-400 space-y-2">
                    <i class="fa-solid fa-list-check text-4xl opacity-50 block"></i>
                    <p class="text-xs font-medium">لا توجد عمليات مسجلة بعد</p>
                </div>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($stats['recent_logs'] as $log): ?>
                        <div class="p-3 bg-slate-50 rounded-2xl border border-slate-100 flex items-start justify-between gap-2 text-xs">
                            <div class="space-y-0.5 min-w-0">
                                <div class="flex items-center gap-1.5">
                                    <span class="w-2 h-2 rounded-full <?= $log['action'] === 'publish_success' || $log['action'] === 'account_connected' ? 'bg-emerald-500' : ($log['action'] === 'publish_failure' ? 'bg-rose-500' : 'bg-blue-500') ?>"></span>
                                    <strong class="font-bold text-slate-800">
                                        <?= $log['action'] === 'publish_success' ? 'تم النشر بنجاح' : ($log['action'] === 'publish_failure' ? 'فشل النشر' : ($log['action'] === 'account_connected' ? 'ربط حساب جديد' : htmlspecialchars($log['action']))) ?>
                                    </strong>
                                    <span class="text-[10px] text-slate-400 font-medium">على <?= htmlspecialchars($log['platform']) ?></span>
                                </div>
                                <?php if (!empty($log['error_message'])): ?>
                                    <p class="text-[11px] text-rose-600 font-medium truncate"><?= htmlspecialchars($log['error_message']) ?></p>
                                <?php elseif (!empty($log['account_name'])): ?>
                                    <p class="text-[11px] text-slate-500 font-medium truncate"><?= htmlspecialchars($log['account_name']) ?></p>
                                <?php endif; ?>
                            </div>
                            <span class="text-[10px] text-slate-400 font-mono shrink-0">
                                <?= date('h:i A', strtotime($log['created_at'])) ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include_once __DIR__ . '/includes/footer.php'; ?>
