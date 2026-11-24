<?php
/**
 * Social Media Center - Published Posts & All Posts Management
 */

require_once __DIR__ . '/config/app.php';
requireLogin();
requireModule('social_media');

require_once __DIR__ . '/includes/social/SocialSecurity.php';
require_once __DIR__ . '/includes/social/SocialMediaManager.php';

$currentUser = getCurrentUser();
$activeCompanyId = (int)($currentUser['active_company_id'] ?? $currentUser['company_id']);

$manager = new SocialMediaManager();

// Handle Delete Post POST action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireNotViewOnly();
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('خطأ في التحقق من صحة الطلب (CSRF)');
    }

    $action = $_POST['action'] ?? '';
    if ($action === 'delete_post') {
        $postId = (int)($_POST['post_id'] ?? 0);
        $ok = $manager->deletePost($activeCompanyId, $postId);
        if ($ok) {
            setFlash('success', 'تم حذف المنشور ومرفقاته بنجاح.');
        } else {
            setFlash('error', 'فشل حذف المنشور.');
        }
        header('Location: social_posts.php');
        exit;
    }

    // Immediate retry for failed post
    if ($action === 'retry_publish') {
        $postId = (int)($_POST['post_id'] ?? 0);
        $res = $manager->publishPostNow($activeCompanyId, $postId);
        if ($res['success']) {
            setFlash('success', 'تمت إعادة محاولة النشر بنجاح على القنوات المستهدفة!');
        } else {
            setFlash('error', 'فشلت محاولة إعادة النشر. يرجى مراجعة سجل الأخطاء.');
        }
        header('Location: social_posts.php');
        exit;
    }
}

// Active filters
$statusFilter = $_GET['status'] ?? '';
$searchQuery = trim($_GET['search'] ?? '');

$filters = [];
if (!empty($statusFilter)) {
    $filters['status'] = $statusFilter;
}
if (!empty($searchQuery)) {
    $filters['search'] = $searchQuery;
}

$posts = $manager->getPosts($activeCompanyId, $filters, 50);

$pageTitle = 'إدارة السوشيال ميديا - سجل المنشورات';
include_once __DIR__ . '/includes/header.php';
?>

<?php include_once __DIR__ . '/includes/social_nav.php'; ?>

<!-- Filter Tabs & Search Bar -->
<div class="bg-white p-4 sm:p-5 rounded-3xl border border-slate-200/80 shadow-xs mb-6 space-y-4">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <!-- Status Tabs -->
        <div class="flex items-center space-x-1 space-x-reverse overflow-x-auto pb-1 no-scrollbar">
            <?php
            $tabs = [
                '' => 'جميع المنشورات',
                'published' => 'المنشورة بنجاح',
                'scheduled' => 'المجدولة',
                'processing' => 'قيد المعالجة',
                'failed' => 'محاولات فاشلة'
            ];
            foreach ($tabs as $key => $title):
                $active = ($statusFilter === $key);
            ?>
                <a href="social_posts.php<?= $key !== '' ? "?status={$key}" : '' ?>" class="px-3.5 py-2 rounded-xl text-xs font-bold whitespace-nowrap transition <?= $active ? 'bg-purple-600 text-white shadow-xs' : 'bg-slate-50 hover:bg-slate-100 text-slate-600' ?>">
                    <?= $title ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Search Bar -->
        <form method="GET" action="social_posts.php" class="relative w-full sm:w-64">
            <?php if (!empty($statusFilter)): ?>
                <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
            <?php endif; ?>
            <input type="text" name="search" value="<?= htmlspecialchars($searchQuery) ?>" placeholder="بحث في نصوص المنشورات..." class="w-full bg-slate-50 border border-slate-300 rounded-xl pr-9 pl-3 py-2 text-xs font-medium focus:bg-white focus:ring-2 focus:ring-purple-500 focus:outline-none">
            <i class="fa-solid fa-magnifying-glass absolute right-3 top-2.5 text-slate-400 text-xs"></i>
        </form>
    </div>
</div>

<!-- Posts List -->
<?php if (empty($posts)): ?>
    <div class="bg-white rounded-3xl border border-slate-200/80 p-12 text-center text-slate-400 space-y-3">
        <div class="w-16 h-16 rounded-3xl bg-slate-100 text-slate-400 flex items-center justify-center text-3xl mx-auto">
            <i class="fa-regular fa-folder-open"></i>
        </div>
        <h4 class="font-bold text-slate-700 text-sm">لا توجد أي منشورات مطابقة للبحث</h4>
        <p class="text-xs text-slate-500">لم يتم العثور على منشورات ضمن هذا التصنيف حالياً.</p>
        <div class="pt-2">
            <a href="social_post_create.php" class="inline-flex items-center gap-2 bg-purple-600 hover:bg-purple-700 text-white font-bold px-5 py-2.5 rounded-xl text-xs transition">
                <i class="fa-solid fa-plus"></i>
                <span>إنشاء منشور جديد الآن</span>
            </a>
        </div>
    </div>
<?php else: ?>
    <div class="bg-white rounded-3xl border border-slate-200/80 shadow-xs overflow-hidden">
        <div class="divide-y divide-slate-100">
            <?php foreach ($posts as $p): ?>
                <div class="p-4 sm:p-5 flex flex-col md:flex-row items-start md:items-center justify-between gap-4 hover:bg-slate-50/50 transition">
                    
                    <!-- Left Block: Thumbnail & Caption -->
                    <div class="flex items-start space-x-3.5 space-x-reverse min-w-0 flex-1">
                        <!-- Media Thumbnail -->
                        <div class="w-16 h-16 rounded-2xl bg-slate-100 border border-slate-200 overflow-hidden shrink-0 flex items-center justify-center text-slate-400 relative">
                            <?php if (!empty($p['thumbnail_path']) && file_exists(APP_PATH . '/' . $p['thumbnail_path'])): ?>
                                <?php if ($p['thumbnail_type'] === 'video'): ?>
                                    <video src="<?= htmlspecialchars($p['thumbnail_path']) ?>" class="w-full h-full object-cover"></video>
                                    <span class="absolute bottom-1 right-1 bg-black/70 text-white text-[8px] px-1 rounded font-bold">فيديو</span>
                                <?php else: ?>
                                    <img src="<?= htmlspecialchars($p['thumbnail_path']) ?>" alt="صورة" class="w-full h-full object-cover">
                                <?php endif; ?>
                            <?php else: ?>
                                <i class="fa-solid fa-align-right text-lg text-slate-300"></i>
                            <?php endif; ?>
                        </div>

                        <!-- Caption & Details -->
                        <div class="space-y-1.5 min-w-0 flex-1">
                            <p class="text-xs sm:text-sm font-bold text-slate-900 line-clamp-2 leading-relaxed">
                                <?= htmlspecialchars($p['caption']) ?>
                            </p>

                            <div class="flex items-center gap-2 flex-wrap text-[11px] text-slate-500">
                                <!-- Status Badge -->
                                <?php if ($p['status'] === 'published'): ?>
                                    <span class="inline-flex items-center gap-1 font-bold px-2.5 py-0.5 rounded-full bg-emerald-100 text-emerald-800 border border-emerald-300">
                                        <i class="fa-solid fa-circle-check text-[10px]"></i> تم النشر
                                    </span>
                                <?php elseif ($p['status'] === 'scheduled'): ?>
                                    <span class="inline-flex items-center gap-1 font-bold px-2.5 py-0.5 rounded-full bg-blue-100 text-blue-800 border border-blue-300">
                                        <i class="fa-solid fa-clock text-[10px]"></i> مجدول
                                    </span>
                                <?php elseif ($p['status'] === 'partially_published'): ?>
                                    <span class="inline-flex items-center gap-1 font-bold px-2.5 py-0.5 rounded-full bg-amber-100 text-amber-800 border border-amber-300">
                                        <i class="fa-solid fa-triangle-exclamation text-[10px]"></i> نشر جزئي
                                    </span>
                                <?php elseif ($p['status'] === 'failed'): ?>
                                    <span class="inline-flex items-center gap-1 font-bold px-2.5 py-0.5 rounded-full bg-rose-100 text-rose-800 border border-rose-300">
                                        <i class="fa-solid fa-circle-xmark text-[10px]"></i> فشل النشر
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center gap-1 font-bold px-2.5 py-0.5 rounded-full bg-slate-100 text-slate-700">
                                        مسودة
                                    </span>
                                <?php endif; ?>

                                <!-- Timestamp -->
                                <span class="dir-ltr font-mono font-bold text-slate-600 bg-slate-100 px-2 py-0.5 rounded-md">
                                    <i class="fa-regular fa-calendar ml-1 text-slate-400"></i>
                                    <?= date('Y/m/d h:i A', strtotime($p['scheduled_at'] ?: ($p['published_at'] ?: $p['created_at']))) ?>
                                </span>

                                <?php if (!empty($p['author_name'])): ?>
                                    <span>بواسطة: <?= htmlspecialchars($p['author_name']) ?></span>
                                <?php endif; ?>
                            </div>

                            <!-- Target Platforms Badges with Links -->
                            <div class="flex items-center gap-1.5 flex-wrap pt-1">
                                <?php foreach ($p['platforms'] as $pl): ?>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-lg text-[10px] font-bold border <?= $pl['status'] === 'published' ? 'bg-emerald-50 text-emerald-800 border-emerald-200' : ($pl['status'] === 'failed' ? 'bg-rose-50 text-rose-800 border-rose-200' : 'bg-slate-100 text-slate-700 border-slate-200') ?>">
                                        <i class="fa-brands fa-<?= $pl['platform'] === 'facebook' ? 'facebook-f text-blue-600' : ($pl['platform'] === 'instagram' ? 'instagram text-pink-600' : ($pl['platform'] === 'tiktok' ? 'tiktok text-slate-900' : 'snapchat text-amber-500')) ?>"></i>
                                        <span><?= htmlspecialchars($pl['account_name'] ?? $pl['platform']) ?></span>
                                        <?php if (!empty($pl['external_post_url'])): ?>
                                            <a href="<?= htmlspecialchars($pl['external_post_url']) ?>" target="_blank" class="hover:text-purple-600 ml-1" title="معاينة الرابط المباشر">
                                                <i class="fa-solid fa-arrow-up-right-from-square text-[9px]"></i>
                                            </a>
                                        <?php endif; ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Right Block: Actions -->
                    <div class="flex items-center space-x-2 space-x-reverse self-end md:self-center shrink-0">
                        <?php if ($p['status'] === 'failed' || $p['status'] === 'partially_published'): ?>
                            <form method="POST">
                                <?= getCSRFInput() ?>
                                <input type="hidden" name="action" value="retry_publish">
                                <input type="hidden" name="post_id" value="<?= $p['id'] ?>">
                                <button type="submit" class="bg-rose-50 hover:bg-rose-100 text-rose-700 font-bold px-3 py-1.5 rounded-xl text-xs transition flex items-center gap-1" title="إعادة محاولة النشر الآن">
                                    <i class="fa-solid fa-rotate-right"></i>
                                    <span>إعادة المحاولة</span>
                                </button>
                            </form>
                        <?php endif; ?>

                        <a href="social_post_create.php?edit=<?= $p['id'] ?>" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold px-3 py-1.5 rounded-xl text-xs transition flex items-center gap-1" title="تعديل">
                            <i class="fa-solid fa-pen"></i>
                            <span>تعديل</span>
                        </a>

                        <form method="POST" onsubmit="return confirm('هل أنت متأكد من حذف هذا المنشور؟ سيتم حذف جميع ملفات الوسائط وسجلات النشر المرتبطة به.')">
                            <?= getCSRFInput() ?>
                            <input type="hidden" name="action" value="delete_post">
                            <input type="hidden" name="post_id" value="<?= $p['id'] ?>">
                            <button type="submit" class="bg-slate-100 hover:bg-rose-50 hover:text-rose-600 text-slate-500 p-2 rounded-xl text-xs transition" title="حذف">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </form>
                    </div>

                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php include_once __DIR__ . '/includes/footer.php'; ?>
