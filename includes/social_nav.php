<?php
/**
 * Social Media Module Sub-navigation Pills Header
 */
$currentSocialScript = basename($_SERVER['PHP_SELF']);
$socialNavTabs = [
    ['file' => 'social_dashboard.php', 'title' => 'لوحة التحكم', 'icon' => 'fa-solid fa-chart-pie', 'badge' => null],
    ['file' => 'social_accounts.php', 'title' => 'الحسابات المتصلة', 'icon' => 'fa-solid fa-link', 'badge' => null],
    ['file' => 'social_post_create.php', 'title' => 'إنشاء منشور', 'icon' => 'fa-solid fa-pen-to-square', 'badge' => 'جديد', 'badge_class' => 'bg-emerald-500 text-white'],
    ['file' => 'social_calendar.php', 'title' => 'تقويم المحتوى', 'icon' => 'fa-solid fa-calendar-days', 'badge' => null],
    ['file' => 'social_posts.php', 'title' => 'المنشورات', 'icon' => 'fa-solid fa-newspaper', 'badge' => null],
    ['file' => 'social_drafts.php', 'title' => 'المسودات', 'icon' => 'fa-solid fa-file-pen', 'badge' => null],
    ['file' => 'social_history.php', 'title' => 'سجل العمليات', 'icon' => 'fa-solid fa-clock-rotate-left', 'badge' => null],
    ['file' => 'social_settings.php', 'title' => 'الإعدادات والجدولة', 'icon' => 'fa-solid fa-gear', 'badge' => null],
];
?>

<div class="mb-6 space-y-4">
    <!-- Header Title & Company Context -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-white p-4 sm:p-5 rounded-3xl border border-slate-200/80 shadow-xs">
        <div class="flex items-center space-x-3.5 space-x-reverse">
            <div class="w-12 h-12 rounded-2xl bg-gradient-to-tr from-purple-600 via-indigo-600 to-pink-500 text-white flex items-center justify-center text-xl shadow-md shadow-purple-600/20 shrink-0">
                <i class="fa-solid fa-share-nodes"></i>
            </div>
            <div>
                <div class="flex items-center gap-2">
                    <h1 class="text-lg sm:text-xl font-black text-slate-900 tracking-tight">إدارة السوشيال ميديا والتسويق</h1>
                    <span class="bg-purple-100 text-purple-700 text-[10px] font-black px-2.5 py-0.5 rounded-full border border-purple-200">
                        Multi-Tenant SaaS
                    </span>
                </div>
                <p class="text-xs text-slate-500 font-medium">
                    ربط وجدولة ونشر المحتوى على فيسبوك، إنستغرام، تيك توك، وسناب شات بحسابات شركتك المستقلة
                </p>
            </div>
        </div>

        <div class="flex items-center gap-2 shrink-0">
            <a href="social_post_create.php" class="inline-flex items-center gap-2 bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white font-bold px-4 py-2.5 rounded-xl shadow-md text-xs sm:text-sm transition-all transform active:scale-95">
                <i class="fa-solid fa-plus"></i>
                <span>إنشاء منشور جديد</span>
            </a>
            <a href="social_accounts.php" class="inline-flex items-center gap-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold px-3 py-2.5 rounded-xl text-xs sm:text-sm transition">
                <i class="fa-solid fa-link text-purple-600"></i>
                <span class="hidden sm:inline">ربط حساب</span>
            </a>
        </div>
    </div>

    <!-- Navigation Tabs Bar -->
    <div class="flex items-center space-x-2 space-x-reverse overflow-x-auto pb-1 no-scrollbar border-b border-slate-200">
        <?php foreach ($socialNavTabs as $tab): ?>
            <?php $isActive = ($currentSocialScript === $tab['file']); ?>
            <a href="<?= $tab['file'] ?>" class="flex items-center space-x-2 space-x-reverse px-3.5 py-2.5 rounded-2xl text-xs font-bold whitespace-nowrap transition-all shrink-0 <?= $isActive ? 'bg-purple-600 text-white shadow-md shadow-purple-600/20' : 'bg-white hover:bg-slate-100 text-slate-600 border border-slate-200/80 hover:text-slate-900' ?>">
                <i class="<?= $tab['icon'] ?> text-xs <?= $isActive ? 'text-white' : 'text-slate-400' ?>"></i>
                <span><?= $tab['title'] ?></span>
                <?php if (!empty($tab['badge'])): ?>
                    <span class="text-[9px] px-1.5 py-0.2 rounded-full font-black <?= $tab['badge_class'] ?? 'bg-slate-200 text-slate-700' ?>">
                        <?= $tab['badge'] ?>
                    </span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>
