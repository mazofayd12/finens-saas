<?php
/**
 * Social Media Center - Drafts Management
 */

require_once __DIR__ . '/config/app.php';
requireLogin();
requireModule('social_media');

require_once __DIR__ . '/includes/social/SocialSecurity.php';
require_once __DIR__ . '/includes/social/SocialMediaManager.php';

$currentUser = getCurrentUser();
$activeCompanyId = (int)($currentUser['active_company_id'] ?? $currentUser['company_id']);

$manager = new SocialMediaManager();

// Handle Delete Draft POST action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireNotViewOnly();
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('خطأ في التحقق من صحة الطلب (CSRF)');
    }

    $action = $_POST['action'] ?? '';
    if ($action === 'delete_draft') {
        $postId = (int)($_POST['post_id'] ?? 0);
        $ok = $manager->deletePost($activeCompanyId, $postId);
        if ($ok) {
            setFlash('success', 'تم حذف المسودة بنجاح.');
        } else {
            setFlash('error', 'فشل حذف المسودة.');
        }
        header('Location: social_drafts.php');
        exit;
    }
}

$drafts = $manager->getPosts($activeCompanyId, ['status' => 'draft'], 50);

$pageTitle = 'إدارة السوشيال ميديا - المسودات';
include_once __DIR__ . '/includes/header.php';
?>

<?php include_once __DIR__ . '/includes/social_nav.php'; ?>

<!-- Drafts Header Banner -->
<div class="bg-white p-4 sm:p-5 rounded-3xl border border-slate-200/80 shadow-xs mb-6 flex flex-col sm:flex-row items-center justify-between gap-3">
    <div class="flex items-center space-x-3 space-x-reverse">
        <div class="w-11 h-11 rounded-2xl bg-slate-100 text-slate-700 flex items-center justify-center text-xl font-bold">
            <i class="fa-solid fa-file-pen"></i>
        </div>
        <div>
            <h2 class="text-base sm:text-lg font-black text-slate-900">مسودات المنشورات</h2>
            <p class="text-xs text-slate-500 font-medium">الأفكار والمنشورات المحفوظة قيد التجهيز والمراجعة قبل إطلاقها</p>
        </div>
    </div>

    <a href="social_post_create.php" class="inline-flex items-center gap-2 bg-purple-600 hover:bg-purple-700 text-white font-bold px-4 py-2.5 rounded-xl text-xs sm:text-sm transition">
        <i class="fa-solid fa-plus"></i>
        <span>إنشاء مسودة جديدة</span>
    </a>
</div>

<!-- Drafts List -->
<?php if (empty($drafts)): ?>
    <div class="bg-white rounded-3xl border border-slate-200/80 p-12 text-center text-slate-400 space-y-3">
        <div class="w-16 h-16 rounded-3xl bg-slate-100 text-slate-400 flex items-center justify-center text-3xl mx-auto">
            <i class="fa-regular fa-file-lines"></i>
        </div>
        <h4 class="font-bold text-slate-700 text-sm">لا توجد أي مسودات محفوظة حالياً</h4>
        <p class="text-xs text-slate-500">يمكنك حفظ المنشورات غير المكتملة كمسودة للرجوع إليها واستكمالها في أي وقت.</p>
        <div class="pt-2">
            <a href="social_post_create.php" class="inline-flex items-center gap-2 bg-purple-600 hover:bg-purple-700 text-white font-bold px-5 py-2.5 rounded-xl text-xs transition">
                <i class="fa-solid fa-plus"></i>
                <span>إنشاء منشور جديد</span>
            </a>
        </div>
    </div>
<?php else: ?>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
        <?php foreach ($drafts as $d): ?>
            <div class="bg-white rounded-3xl border border-slate-200/80 shadow-xs p-5 flex flex-col justify-between hover:border-purple-300 transition">
                <div>
                    <!-- Header: Date and Media Count -->
                    <div class="flex items-center justify-between text-xs text-slate-400 mb-3">
                        <span class="dir-ltr font-mono text-[11px] font-bold">
                            <?= date('Y/m/d h:i A', strtotime($d['created_at'])) ?>
                        </span>
                        <?php if ($d['media_count'] > 0): ?>
                            <span class="inline-flex items-center gap-1 text-[11px] font-bold text-purple-700 bg-purple-50 px-2 py-0.5 rounded-lg">
                                <i class="fa-solid fa-image"></i> <?= $d['media_count'] ?> مرفقات
                            </span>
                        <?php endif; ?>
                    </div>

                    <!-- Media Thumbnail if present -->
                    <?php if (!empty($d['thumbnail_path']) && file_exists(APP_PATH . '/' . $d['thumbnail_path'])): ?>
                        <div class="aspect-video bg-slate-100 rounded-2xl overflow-hidden border border-slate-200 mb-3">
                            <img src="<?= htmlspecialchars($d['thumbnail_path']) ?>" alt="صورة" class="w-full h-full object-cover">
                        </div>
                    <?php endif; ?>

                    <!-- Caption Preview -->
                    <p class="text-xs sm:text-sm font-bold text-slate-800 line-clamp-3 leading-relaxed mb-4">
                        <?= htmlspecialchars($d['caption'] ?: '(مسودة بدون نص)') ?>
                    </p>
                </div>

                <!-- Action Buttons Footer -->
                <div class="pt-3 border-t border-slate-100 flex items-center justify-between gap-2">
                    <a href="social_post_create.php?edit=<?= $d['id'] ?>" class="flex-1 inline-flex items-center justify-center gap-1.5 bg-purple-600 hover:bg-purple-700 text-white font-bold py-2 px-3 rounded-xl text-xs transition shadow-xs">
                        <i class="fa-solid fa-pen-to-square"></i>
                        <span>تحرير وإكمال النشر</span>
                    </a>

                    <form method="POST" onsubmit="return confirm('هل تريد حذف هذه المسودة نهائياً؟')">
                        <?= getCSRFInput() ?>
                        <input type="hidden" name="action" value="delete_draft">
                        <input type="hidden" name="post_id" value="<?= $d['id'] ?>">
                        <button type="submit" class="bg-slate-100 hover:bg-rose-50 hover:text-rose-600 text-slate-500 p-2 rounded-xl text-xs transition" title="حذف المسودة">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php include_once __DIR__ . '/includes/footer.php'; ?>
