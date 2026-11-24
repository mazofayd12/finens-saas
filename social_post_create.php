<?php
/**
 * Social Media Center - Post Composer (Create / Edit / Draft / Schedule / Live Previews)
 */

require_once __DIR__ . '/config/app.php';
requireLogin();
requireModule('social_media');

require_once __DIR__ . '/includes/social/SocialSecurity.php';
require_once __DIR__ . '/includes/social/SocialMediaManager.php';

$currentUser = getCurrentUser();
$activeCompanyId = (int)($currentUser['active_company_id'] ?? $currentUser['company_id']);

$manager = new SocialMediaManager();
$connectedAccounts = $manager->getConnectedAccounts($activeCompanyId, null, true);

// Edit mode detection
$editPostId = !empty($_GET['edit']) ? (int)$_GET['edit'] : null;
$editingPost = $editPostId ? $manager->getPostById($activeCompanyId, $editPostId) : null;

// Handle Form Submission (Create or Update Post)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireNotViewOnly();
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('خطأ في التحقق من صحة الطلب (CSRF)');
    }

    $action = $_POST['action'] ?? '';
    if ($action === 'save_social_post') {
        $caption = trim($_POST['caption'] ?? '');
        $publishType = $_POST['publish_type'] ?? 'now'; // 'now', 'schedule', 'draft'
        $scheduledDate = trim($_POST['scheduled_date'] ?? '');
        $scheduledTime = trim($_POST['scheduled_time'] ?? '');
        $targetAccountIds = $_POST['target_accounts'] ?? [];
        $mediaIds = $_POST['media_ids'] ?? [];

        // Platform specific captions
        $platformCaptions = [];
        if (!empty($_POST['custom_caps_enabled'])) {
            if (!empty($_POST['caption_facebook'])) $platformCaptions['facebook'] = trim($_POST['caption_facebook']);
            if (!empty($_POST['caption_instagram'])) $platformCaptions['instagram'] = trim($_POST['caption_instagram']);
            if (!empty($_POST['caption_tiktok'])) $platformCaptions['tiktok'] = trim($_POST['caption_tiktok']);
            if (!empty($_POST['caption_snapchat'])) $platformCaptions['snapchat'] = trim($_POST['caption_snapchat']);
        }

        if (empty($caption) && empty($mediaIds)) {
            setFlash('error', 'يرجى كتابة نص للمنشور أو إرفاق ملف وسائط واحد على الأقل.');
            header('Location: social_post_create.php' . ($editPostId ? "?edit={$editPostId}" : ''));
            exit;
        }

        if ($publishType !== 'draft' && empty($targetAccountIds)) {
            setFlash('error', 'يرجى اختيار حساب واحد على الأقل لنشر المحتوى.');
            header('Location: social_post_create.php' . ($editPostId ? "?edit={$editPostId}" : ''));
            exit;
        }

        $scheduledAt = null;
        $status = 'draft';

        if ($publishType === 'schedule') {
            if (empty($scheduledDate) || empty($scheduledTime)) {
                setFlash('error', 'يرجى تحديد تاريخ ووقت الجدولة بدقة.');
                header('Location: social_post_create.php' . ($editPostId ? "?edit={$editPostId}" : ''));
                exit;
            }
            $scheduledAt = "{$scheduledDate} {$scheduledTime}:00";
            $status = 'scheduled';
        } elseif ($publishType === 'now') {
            $status = 'draft'; // Temporarily created as draft, then published immediately
        }

        try {
            $postId = null;
            if ($editingPost) {
                $postId = $editingPost['id'];
                $manager->updatePost($activeCompanyId, $postId, [
                    'caption' => $caption,
                    'status' => $status,
                    'scheduled_at' => $scheduledAt,
                    'platform_captions' => $platformCaptions
                ], $targetAccountIds, $mediaIds);
            } else {
                $postId = $manager->createPost($activeCompanyId, (int)$_SESSION['user_id'], [
                    'caption' => $caption,
                    'status' => $status,
                    'scheduled_at' => $scheduledAt,
                    'platform_captions' => $platformCaptions,
                    'timezone' => date_default_timezone_get()
                ], $targetAccountIds, $mediaIds);
            }

            // If "Publish Now" selected, trigger immediate execution
            if ($publishType === 'now') {
                $pubResult = $manager->publishPostNow($activeCompanyId, $postId);
                if ($pubResult['success']) {
                    setFlash('success', 'تم نشر المنشور بنجاح على الحسابات المحددة!');
                } else {
                    setFlash('error', 'تم حفظ المنشور ولكن حدثت أخطاء أثناء النشر المباشر. يرجى مراجعة سجل النشر.');
                }
                header('Location: social_posts.php');
                exit;
            } elseif ($publishType === 'schedule') {
                setFlash('success', "تمت جدولة المنشور بنجاح لموعد ({$scheduledAt}). سيقوم النظام بنشره تلقائياً.");
                header('Location: social_calendar.php');
                exit;
            } else {
                setFlash('info', 'تم حفظ المنشور كمسودة بنجاح.');
                header('Location: social_drafts.php');
                exit;
            }
        } catch (Exception $e) {
            setFlash('error', 'حدث خطأ أثناء حفظ المنشور: ' . $e->getMessage());
            header('Location: social_post_create.php' . ($editPostId ? "?edit={$editPostId}" : ''));
            exit;
        }
    }
}

// Prefill data if editing
$initialCaption = $editingPost['caption'] ?? '';
$initialTargets = array_column($editingPost['targets'] ?? [], 'social_account_id');
$initialMedia = $editingPost['media'] ?? [];
$initialPlatformCaps = $editingPost['platform_captions'] ?? [];
$initialScheduledAt = $editingPost['scheduled_at'] ?? '';
$initialDate = $initialScheduledAt ? date('Y-m-d', strtotime($initialScheduledAt)) : date('Y-m-d');
$initialTime = $initialScheduledAt ? date('H:i', strtotime($initialScheduledAt)) : date('H:i', strtotime('+1 hour'));

$pageTitle = $editingPost ? 'تعديل المنشور' : 'إنشاء منشور سوشيال ميديا جديد';
include_once __DIR__ . '/includes/header.php';
?>

<?php include_once __DIR__ . '/includes/social_nav.php'; ?>

<?php if (empty($connectedAccounts)): ?>
    <!-- Notice if no accounts connected -->
    <div class="bg-amber-50 border border-amber-200 rounded-3xl p-4 sm:p-5 mb-6 flex items-center justify-between gap-3 text-xs text-amber-900">
        <div class="flex items-center gap-3">
            <i class="fa-solid fa-triangle-exclamation text-amber-600 text-xl"></i>
            <div>
                <strong class="font-bold block">لم تقم بربط أي حسابات سوشيال ميديا بعد:</strong>
                <span>يمكنك تجهيز المنشور وحفظه كمسودة الآن، ولكن للنشر المباشر أو الجدولة يلزم ربط حساب واحد على الأقل.</span>
            </div>
        </div>
        <a href="social_accounts.php" class="bg-amber-600 hover:bg-amber-700 text-white font-bold px-4 py-2 rounded-xl shrink-0 transition">
            ربط حساب الآن
        </a>
    </div>
<?php endif; ?>

<!-- 2-Column Responsive Layout: Composer on Right (lg:col-span-7), Live Preview on Left (lg:col-span-5) -->
<div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
    
    <!-- Right Column: The Composer Form (lg:col-span-7) -->
    <div class="lg:col-span-7 space-y-6">
        <form id="social_post_form" action="social_post_create.php<?= $editPostId ? "?edit={$editPostId}" : '' ?>" method="POST" class="space-y-6">
            <?= getCSRFInput() ?>
            <input type="hidden" name="action" value="save_social_post">
            <div id="hidden_media_inputs">
                <?php foreach ($initialMedia as $im): ?>
                    <input type="hidden" name="media_ids[]" value="<?= $im['id'] ?>" id="media_input_<?= $im['id'] ?>">
                <?php endforeach; ?>
            </div>

            <!-- Card 1: Channel & Account Selection -->
            <div class="bg-white rounded-3xl border border-slate-200/80 shadow-xs p-5 sm:p-6 space-y-3">
                <div class="flex items-center justify-between">
                    <label class="block text-xs font-black text-slate-900">
                        <i class="fa-solid fa-bullhorn text-purple-600 ml-1"></i>
                        حدد الحسابات والقنوات المستهدفة للنشر: <span class="text-rose-500">*</span>
                    </label>
                    <span class="text-[11px] text-slate-400 font-medium">يمكنك اختيار عدة منصات معاً</span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                    <?php if (empty($connectedAccounts)): ?>
                        <div class="col-span-2 text-center py-4 bg-slate-50 rounded-2xl border border-dashed border-slate-200 text-xs text-slate-400 italic">
                            لا توجد حسابات متصلة حالياً. <a href="social_accounts.php" class="text-purple-600 font-bold hover:underline">اضغط هنا لربط الحسابات</a>
                        </div>
                    <?php else: ?>
                        <?php foreach ($connectedAccounts as $acc): ?>
                            <?php $isSelected = in_array($acc['id'], $initialTargets); ?>
                            <label class="relative flex items-center space-x-3 space-x-reverse p-3 rounded-2xl border-2 cursor-pointer transition select-none hover:bg-slate-50 <?= $isSelected ? 'border-purple-600 bg-purple-50/50' : 'border-slate-200 bg-white' ?>">
                                <input type="checkbox" name="target_accounts[]" value="<?= $acc['id'] ?>" <?= $isSelected ? 'checked' : '' ?> onchange="onAccountSelectChange(this, '<?= $acc['platform'] ?>')" class="rounded text-purple-600 focus:ring-purple-500 w-4 h-4 ml-2">
                                <div class="w-8 h-8 rounded-full bg-slate-100 border border-slate-200 overflow-hidden shrink-0 flex items-center justify-center text-xs">
                                    <?php if (!empty($acc['avatar_url'])): ?>
                                        <img src="<?= htmlspecialchars($acc['avatar_url']) ?>" alt="أفاتار" class="w-full h-full object-cover">
                                    <?php else: ?>
                                        <i class="fa-brands fa-<?= $acc['platform'] === 'facebook' ? 'facebook-f text-blue-600' : ($acc['platform'] === 'instagram' ? 'instagram text-pink-600' : ($acc['platform'] === 'tiktok' ? 'tiktok text-slate-900' : 'snapchat text-amber-500')) ?>"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="min-w-0 flex-1 truncate">
                                    <strong class="text-xs font-bold text-slate-800 block truncate"><?= htmlspecialchars($acc['account_name']) ?></strong>
                                    <span class="text-[10px] text-slate-400 block truncate"><?= ucfirst($acc['platform']) ?></span>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Card 2: Caption & Hashtag Manager -->
            <div class="bg-white rounded-3xl border border-slate-200/80 shadow-xs p-5 sm:p-6 space-y-4">
                <div class="flex items-center justify-between">
                    <label class="block text-xs font-black text-slate-900">
                        <i class="fa-solid fa-pen-nib text-purple-600 ml-1"></i>
                        محتوى المنشور (الوصف والهاشتاجات): <span class="text-rose-500">*</span>
                    </label>
                    <div id="char_counter_badge" class="text-[11px] font-mono font-bold text-slate-500 bg-slate-100 px-2 py-0.5 rounded-lg">
                        <span id="char_count">0</span> حرف
                    </div>
                </div>

                <div class="relative">
                    <textarea name="caption" id="post_caption_input" rows="5" required placeholder="اكتب وصف المنشور هنا... شارك آخر أخبار شركتك، عروضك، ومشاريعك الجديدة مع متابعيك." oninput="onCaptionChange(this.value)" class="w-full bg-slate-50 border border-slate-300 rounded-2xl p-4 text-xs sm:text-sm font-medium focus:bg-white focus:ring-2 focus:ring-purple-500 focus:outline-none leading-relaxed"><?= htmlspecialchars($initialCaption) ?></textarea>
                </div>

                <!-- Quick Hashtag Suggestions Bar -->
                <div class="space-y-1.5">
                    <span class="text-[11px] font-bold text-slate-500 block">
                        <i class="fa-solid fa-hashtag text-purple-500 ml-1"></i> هاشتاجات مقترحة سريعة (اضغط للإضافة):
                    </span>
                    <div class="flex flex-wrap gap-1.5">
                        <?php
                        $suggestedTags = ['#عقارات', '#تسويق', '#شركة', '#جديد', '#عروض', '#تصميم', '#استثمار', '#أعمال', '#خدمات', '#السعودية', '#مصر'];
                        foreach ($suggestedTags as $tag):
                        ?>
                            <button type="button" onclick="appendHashtag('<?= $tag ?>')" class="text-[11px] font-bold bg-slate-100 hover:bg-purple-100 hover:text-purple-700 text-slate-600 px-2.5 py-1 rounded-xl transition">
                                <?= $tag ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Platform Specific Custom Captions Toggle -->
                <div class="pt-3 border-t border-slate-100">
                    <button type="button" onclick="toggleCustomPlatformCaptions()" class="text-xs font-bold text-purple-600 hover:text-purple-700 flex items-center gap-1.5">
                        <i class="fa-solid fa-sliders text-xs"></i>
                        <span>تخصيص نص مختلف لكل منصة (اختياري)</span>
                        <i id="custom_caps_chevron" class="fa-solid fa-chevron-down text-[10px] transition-transform"></i>
                    </button>
                    <input type="hidden" name="custom_caps_enabled" id="custom_caps_enabled" value="<?= !empty($initialPlatformCaps) ? '1' : '0' ?>">

                    <div id="custom_caps_container" class="<?= empty($initialPlatformCaps) ? 'hidden' : '' ?> space-y-3 pt-3">
                        <div>
                            <label class="block text-[11px] font-bold text-blue-700 mb-1"><i class="fa-brands fa-facebook-f ml-1"></i> نص خاص بفيسبوك:</label>
                            <textarea name="caption_facebook" rows="2" placeholder="اتركه فارغاً لاستخدام النص الرئيسي..." class="w-full bg-slate-50 border border-slate-200 rounded-xl p-2.5 text-xs"><?= htmlspecialchars($initialPlatformCaps['facebook'] ?? '') ?></textarea>
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-pink-700 mb-1"><i class="fa-brands fa-instagram ml-1"></i> نص خاص بإنستغرام:</label>
                            <textarea name="caption_instagram" rows="2" placeholder="اتركه فارغاً لاستخدام النص الرئيسي..." class="w-full bg-slate-50 border border-slate-200 rounded-xl p-2.5 text-xs"><?= htmlspecialchars($initialPlatformCaps['instagram'] ?? '') ?></textarea>
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-800 mb-1"><i class="fa-brands fa-tiktok ml-1"></i> نص خاص بتيك توك:</label>
                            <textarea name="caption_tiktok" rows="2" placeholder="اتركه فارغاً لاستخدام النص الرئيسي..." class="w-full bg-slate-50 border border-slate-200 rounded-xl p-2.5 text-xs"><?= htmlspecialchars($initialPlatformCaps['tiktok'] ?? '') ?></textarea>
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-amber-700 mb-1"><i class="fa-brands fa-snapchat ml-1"></i> نص خاص بسناب شات:</label>
                            <textarea name="caption_snapchat" rows="2" placeholder="اتركه فارغاً لاستخدام النص الرئيسي..." class="w-full bg-slate-50 border border-slate-200 rounded-xl p-2.5 text-xs"><?= htmlspecialchars($initialPlatformCaps['snapchat'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Card 3: Media Upload Dropzone -->
            <div class="bg-white rounded-3xl border border-slate-200/80 shadow-xs p-5 sm:p-6 space-y-3">
                <div class="flex items-center justify-between">
                    <label class="block text-xs font-black text-slate-900">
                        <i class="fa-solid fa-photo-film text-purple-600 ml-1"></i>
                        الصور ومقاطع الفيديو:
                    </label>
                    <span class="text-[11px] text-slate-400">JPG, PNG, WebP, MP4, MOV</span>
                </div>

                <!-- Dropzone Area -->
                <div id="media_dropzone" onclick="document.getElementById('media_file_input').click()" class="border-2 border-dashed border-purple-200 hover:border-purple-500 bg-purple-50/20 hover:bg-purple-50/40 rounded-2xl p-6 text-center cursor-pointer transition">
                    <input type="file" id="media_file_input" multiple accept="image/*,video/*" class="hidden" onchange="handleMediaUpload(this.files)">
                    <div class="flex flex-col items-center justify-center pointer-events-none">
                        <div class="w-12 h-12 rounded-2xl bg-purple-100 text-purple-600 flex items-center justify-center text-xl mb-2">
                            <i class="fa-solid fa-cloud-arrow-up"></i>
                        </div>
                        <p class="text-xs font-bold text-slate-800">اضغط لاختيار الصور والفيديوهات أو اسحبها وأفلتها هنا</p>
                        <p class="text-[10px] text-slate-400 mt-0.5">يدعم رفع ملفات وصور متعددة للألبومات وريلز</p>
                    </div>
                </div>

                <!-- Loading progress indicator -->
                <div id="media_upload_progress" class="hidden p-3 bg-purple-50 rounded-xl border border-purple-200 text-xs text-purple-700 flex items-center justify-center gap-2">
                    <i class="fa-solid fa-spinner fa-spin"></i>
                    <span>جاري رفع ومعالجة الوسائط...</span>
                </div>

                <!-- Selected Media Previews Container -->
                <div id="media_preview_list" class="grid grid-cols-2 sm:grid-cols-4 gap-3 pt-2">
                    <?php foreach ($initialMedia as $im): ?>
                        <div class="relative group rounded-2xl overflow-hidden border border-slate-200 bg-slate-100 aspect-square" id="preview_card_<?= $im['id'] ?>">
                            <?php if ($im['file_type'] === 'video'): ?>
                                <video src="<?= htmlspecialchars($im['file_path']) ?>" class="w-full h-full object-cover"></video>
                                <span class="absolute bottom-1 right-1 bg-black/70 text-white text-[9px] px-1.5 py-0.5 rounded font-bold">فيديو</span>
                            <?php else: ?>
                                <img src="<?= htmlspecialchars($im['file_path']) ?>" alt="وسائط" class="w-full h-full object-cover">
                            <?php endif; ?>
                            <button type="button" onclick="removeMedia(<?= $im['id'] ?>)" class="absolute top-1 left-1 bg-rose-600 hover:bg-rose-700 text-white w-6 h-6 rounded-full flex items-center justify-center text-xs shadow-md transition" title="حذف">
                                <i class="fa-solid fa-xmark"></i>
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Card 4: Publishing Timing (Now / Schedule / Draft) -->
            <div class="bg-white rounded-3xl border border-slate-200/80 shadow-xs p-5 sm:p-6 space-y-4">
                <label class="block text-xs font-black text-slate-900">
                    <i class="fa-solid fa-calendar-days text-purple-600 ml-1"></i>
                    طريقة وتوقيت النشر:
                </label>

                <!-- Timing Options -->
                <div class="grid grid-cols-3 gap-2.5">
                    <label class="p-3.5 rounded-2xl border-2 cursor-pointer transition text-center select-none flex flex-col items-center justify-center gap-1.5 timing-card border-purple-600 bg-purple-50/60" id="card_type_now">
                        <input type="radio" name="publish_type" value="now" checked onchange="onPublishTypeChange('now')" class="hidden">
                        <i class="fa-solid fa-paper-plane text-purple-600 text-base"></i>
                        <strong class="text-xs font-black text-slate-900 block">نشر فوري الآن</strong>
                        <span class="text-[10px] text-slate-500">إطلاق مباشر للقنوات</span>
                    </label>

                    <label class="p-3.5 rounded-2xl border-2 cursor-pointer transition text-center select-none flex flex-col items-center justify-center gap-1.5 timing-card border-slate-200 bg-white hover:bg-slate-50" id="card_type_schedule">
                        <input type="radio" name="publish_type" value="schedule" <?= ($editingPost['status'] ?? '') === 'scheduled' ? 'checked' : '' ?> onchange="onPublishTypeChange('schedule')" class="hidden">
                        <i class="fa-solid fa-clock text-blue-600 text-base"></i>
                        <strong class="text-xs font-black text-slate-900 block">جدولة لوقت لاحق</strong>
                        <span class="text-[10px] text-slate-500">تحديد تاريخ ووقت</span>
                    </label>

                    <label class="p-3.5 rounded-2xl border-2 cursor-pointer transition text-center select-none flex flex-col items-center justify-center gap-1.5 timing-card border-slate-200 bg-white hover:bg-slate-50" id="card_type_draft">
                        <input type="radio" name="publish_type" value="draft" <?= ($editingPost['status'] ?? '') === 'draft' ? 'checked' : '' ?> onchange="onPublishTypeChange('draft')" class="hidden">
                        <i class="fa-solid fa-file-pen text-slate-500 text-base"></i>
                        <strong class="text-xs font-black text-slate-900 block">حفظ كمسودة</strong>
                        <span class="text-[10px] text-slate-500">للمراجعة لاحقاً</span>
                    </label>
                </div>

                <!-- Schedule Date & Time Picker Container (Shown when schedule chosen) -->
                <div id="schedule_picker_wrap" class="<?= ($editingPost['status'] ?? '') === 'scheduled' ? '' : 'hidden' ?> p-4 bg-blue-50/60 rounded-2xl border border-blue-200 space-y-3">
                    <span class="text-xs font-bold text-blue-900 block">
                        <i class="fa-regular fa-clock ml-1"></i> اختر موعد النشر المستهدف بتوقيت النظام:
                    </span>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[11px] font-bold text-slate-600 mb-1">تاريخ النشر</label>
                            <input type="date" name="scheduled_date" id="scheduled_date_input" value="<?= $initialDate ?>" min="<?= date('Y-m-d') ?>" class="w-full bg-white border border-blue-300 rounded-xl px-3 py-2 text-xs font-bold focus:ring-2 focus:ring-blue-500 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-600 mb-1">وقت النشر</label>
                            <input type="time" name="scheduled_time" id="scheduled_time_input" value="<?= $initialTime ?>" class="w-full bg-white border border-blue-300 rounded-xl px-3 py-2 text-xs font-bold focus:ring-2 focus:ring-blue-500 focus:outline-none">
                        </div>
                    </div>
                </div>

                <!-- Submit Action Buttons -->
                <div class="pt-2">
                    <button type="submit" id="main_submit_btn" class="w-full bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white font-bold py-3.5 px-6 rounded-2xl shadow-lg shadow-purple-600/30 transition text-sm flex items-center justify-center gap-2">
                        <i class="fa-solid fa-paper-plane" id="btn_submit_icon"></i>
                        <span id="btn_submit_label"><?= $editingPost ? 'حفظ التعديلات وتحديث المنشور' : 'نشر المحتوى الآن على القنوات المحددة' ?></span>
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Left Column: Interactive Live Social Media Preview (lg:col-span-5) -->
    <div class="lg:col-span-5 sticky top-6 space-y-4">
        <div class="bg-white rounded-3xl border border-slate-200/80 shadow-xs p-5 space-y-4">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <div class="flex items-center space-x-2 space-x-reverse">
                    <i class="fa-solid fa-eye text-purple-600 text-base"></i>
                    <h3 class="font-black text-slate-900 text-sm">معاينة حية للمنشور</h3>
                </div>
                <!-- Platform Tab Switcher -->
                <div class="inline-flex p-1 bg-slate-100 rounded-xl text-xs gap-1">
                    <button type="button" onclick="switchPreviewPlatform('facebook')" id="tab_preview_facebook" class="px-2.5 py-1 rounded-lg font-bold transition bg-white text-blue-600 shadow-xs">
                        <i class="fa-brands fa-facebook-f"></i>
                    </button>
                    <button type="button" onclick="switchPreviewPlatform('instagram')" id="tab_preview_instagram" class="px-2.5 py-1 rounded-lg font-bold transition text-slate-600 hover:text-pink-600">
                        <i class="fa-brands fa-instagram"></i>
                    </button>
                    <button type="button" onclick="switchPreviewPlatform('tiktok')" id="tab_preview_tiktok" class="px-2.5 py-1 rounded-lg font-bold transition text-slate-600 hover:text-slate-900">
                        <i class="fa-brands fa-tiktok"></i>
                    </button>
                </div>
            </div>

            <!-- PREVIEW 1: FACEBOOK FEED CARD -->
            <div id="preview_wrap_facebook" class="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden text-xs">
                <!-- Header -->
                <div class="p-3.5 flex items-center justify-between">
                    <div class="flex items-center space-x-2.5 space-x-reverse">
                        <div class="w-9 h-9 rounded-full bg-blue-600 text-white flex items-center justify-center font-bold text-xs shrink-0">
                            <?= mb_substr($currentUser['company_name'] ?? 'ف', 0, 1) ?>
                        </div>
                        <div>
                            <strong class="font-bold text-slate-900 block"><?= htmlspecialchars($currentUser['company_name'] ?? 'صفحة الشركة') ?></strong>
                            <span class="text-[10px] text-slate-400 font-medium">الآن · <i class="fa-solid fa-earth-americas text-[9px]"></i></span>
                        </div>
                    </div>
                    <i class="fa-solid fa-ellipsis text-slate-400"></i>
                </div>

                <!-- Caption -->
                <div class="px-3.5 pb-2 text-slate-800 leading-relaxed font-medium whitespace-pre-wrap" id="live_caption_facebook">
                    <?= !empty($initialCaption) ? htmlspecialchars($initialCaption) : 'محتوى المنشور سيظهر هنا مباشرة أثناء الكتابة...' ?>
                </div>

                <!-- Media Display -->
                <div id="live_media_facebook" class="bg-slate-100 aspect-video flex items-center justify-center text-slate-300 overflow-hidden">
                    <?php if (!empty($initialMedia)): ?>
                        <img src="<?= htmlspecialchars($initialMedia[0]['file_path']) ?>" class="w-full h-full object-cover">
                    <?php else: ?>
                        <i class="fa-solid fa-image text-3xl"></i>
                    <?php endif; ?>
                </div>

                <!-- Facebook Reaction Counts & Action Bar -->
                <div class="p-3 border-t border-slate-100 flex items-center justify-between text-slate-500 font-medium text-[11px]">
                    <span class="flex items-center gap-1"><i class="fa-solid fa-thumbs-up text-blue-600"></i> إعجاب</span>
                    <span class="flex items-center gap-1"><i class="fa-regular fa-comment"></i> تعليق</span>
                    <span class="flex items-center gap-1"><i class="fa-solid fa-share"></i> مشاركة</span>
                </div>
            </div>

            <!-- PREVIEW 2: INSTAGRAM POST CARD -->
            <div id="preview_wrap_instagram" class="hidden bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden text-xs">
                <!-- Header -->
                <div class="p-3 flex items-center justify-between">
                    <div class="flex items-center space-x-2 space-x-reverse">
                        <div class="w-8 h-8 rounded-full bg-gradient-to-tr from-amber-500 to-rose-600 p-0.5 shrink-0">
                            <div class="w-full h-full bg-white rounded-full flex items-center justify-center text-[10px] font-bold text-slate-800">
                                <?= mb_substr($currentUser['company_name'] ?? 'I', 0, 1) ?>
                            </div>
                        </div>
                        <div>
                            <strong class="font-bold text-slate-900 block"><?= strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', $currentUser['company_code'] ?? 'brand_official')) ?></strong>
                        </div>
                    </div>
                    <i class="fa-solid fa-ellipsis text-slate-400"></i>
                </div>

                <!-- Media (Square 1:1) -->
                <div id="live_media_instagram" class="bg-slate-100 aspect-square flex items-center justify-center text-slate-300 overflow-hidden">
                    <?php if (!empty($initialMedia)): ?>
                        <img src="<?= htmlspecialchars($initialMedia[0]['file_path']) ?>" class="w-full h-full object-cover">
                    <?php else: ?>
                        <i class="fa-brands fa-instagram text-4xl"></i>
                    <?php endif; ?>
                </div>

                <!-- Action Bar -->
                <div class="p-3 space-y-2">
                    <div class="flex items-center justify-between text-slate-800 text-base">
                        <div class="flex items-center gap-3">
                            <i class="fa-regular fa-heart hover:text-rose-500 cursor-pointer"></i>
                            <i class="fa-regular fa-comment"></i>
                            <i class="fa-regular fa-paper-plane"></i>
                        </div>
                        <i class="fa-regular fa-bookmark"></i>
                    </div>
                    <div class="text-[11px] font-bold text-slate-900">0 إعجاب</div>
                    <!-- Caption -->
                    <div class="text-[11px] text-slate-800 leading-relaxed">
                        <strong class="font-bold text-slate-900"><?= strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', $currentUser['company_code'] ?? 'brand')) ?></strong>
                        <span id="live_caption_instagram" class="whitespace-pre-wrap"><?= !empty($initialCaption) ? htmlspecialchars($initialCaption) : 'محتوى المنشور يظهر هنا...' ?></span>
                    </div>
                </div>
            </div>

            <!-- PREVIEW 3: TIKTOK MOBILE VIDEO CARD -->
            <div id="preview_wrap_tiktok" class="hidden bg-slate-950 text-white rounded-3xl overflow-hidden aspect-[9/16] relative max-w-xs mx-auto shadow-xl flex flex-col justify-between p-4">
                <div class="flex justify-between items-center text-xs opacity-80 pt-1">
                    <span>مباشر</span>
                    <span class="font-bold">لك (For You)</span>
                    <i class="fa-solid fa-magnifying-glass"></i>
                </div>

                <!-- Background mockup -->
                <div id="live_media_tiktok" class="absolute inset-0 bg-slate-900 z-0 flex items-center justify-center text-slate-700">
                    <?php if (!empty($initialMedia)): ?>
                        <img src="<?= htmlspecialchars($initialMedia[0]['file_path']) ?>" class="w-full h-full object-cover opacity-80">
                    <?php else: ?>
                        <i class="fa-brands fa-tiktok text-6xl opacity-30"></i>
                    <?php endif; ?>
                </div>

                <!-- Floating Right Engagement Icons -->
                <div class="relative z-10 self-end space-y-4 text-center text-xs">
                    <div>
                        <div class="w-9 h-9 rounded-full bg-white/20 backdrop-blur-md flex items-center justify-center text-rose-500 text-base mx-auto">
                            <i class="fa-solid fa-heart"></i>
                        </div>
                        <span class="text-[10px] block mt-0.5">0</span>
                    </div>
                    <div>
                        <div class="w-9 h-9 rounded-full bg-white/20 backdrop-blur-md flex items-center justify-center text-white text-base mx-auto">
                            <i class="fa-solid fa-comment-dots"></i>
                        </div>
                        <span class="text-[10px] block mt-0.5">0</span>
                    </div>
                    <div>
                        <div class="w-9 h-9 rounded-full bg-white/20 backdrop-blur-md flex items-center justify-center text-white text-base mx-auto">
                            <i class="fa-solid fa-share"></i>
                        </div>
                        <span class="text-[10px] block mt-0.5">مشاركة</span>
                    </div>
                </div>

                <!-- Bottom Caption & Audio -->
                <div class="relative z-10 space-y-1.5 text-xs text-right">
                    <strong class="font-bold block">@<?= strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', $currentUser['company_code'] ?? 'official')) ?></strong>
                    <p class="text-[11px] leading-relaxed line-clamp-3 text-slate-200" id="live_caption_tiktok">
                        <?= !empty($initialCaption) ? htmlspecialchars($initialCaption) : 'وصف فيديو تيك توك...' ?>
                    </p>
                    <div class="flex items-center gap-1.5 text-[10px] opacity-80">
                        <i class="fa-solid fa-music"></i>
                        <span>الصوت الأصلي - <?= htmlspecialchars($currentUser['company_name'] ?? 'الشركة') ?></span>
                    </div>
                </div>
            </div>

        </div>
    </div>

</div>

<!-- JavaScript for Live Interactivity -->
<script>
var csrfToken = "<?= generateCSRFToken() ?>";

function onCaptionChange(text) {
    document.getElementById('char_count').innerText = text.length;
    var defaultMsg = 'محتوى المنشور سيظهر هنا مباشرة أثناء الكتابة...';
    document.getElementById('live_caption_facebook').innerText = text || defaultMsg;
    document.getElementById('live_caption_instagram').innerText = text || defaultMsg;
    document.getElementById('live_caption_tiktok').innerText = text || 'وصف فيديو تيك توك...';
}

function appendHashtag(tag) {
    var input = document.getElementById('post_caption_input');
    input.value = (input.value ? input.value + ' ' : '') + tag;
    onCaptionChange(input.value);
    input.focus();
}

function toggleCustomPlatformCaptions() {
    var wrap = document.getElementById('custom_caps_container');
    var isHidden = wrap.classList.contains('hidden');
    var hiddenInput = document.getElementById('custom_caps_enabled');
    var chevron = document.getElementById('custom_caps_chevron');

    if (isHidden) {
        wrap.classList.remove('hidden');
        hiddenInput.value = '1';
        chevron.style.transform = 'rotate(180deg)';
    } else {
        wrap.classList.add('hidden');
        hiddenInput.value = '0';
        chevron.style.transform = 'rotate(0deg)';
    }
}

function onPublishTypeChange(type) {
    var picker = document.getElementById('schedule_picker_wrap');
    var submitBtn = document.getElementById('main_submit_btn');
    var submitIcon = document.getElementById('btn_submit_icon');
    var submitLabel = document.getElementById('btn_submit_label');

    // Update timing card borders
    document.getElementById('card_type_now').className = 'p-3.5 rounded-2xl border-2 cursor-pointer transition text-center select-none flex flex-col items-center justify-center gap-1.5 timing-card ' + (type === 'now' ? 'border-purple-600 bg-purple-50/60' : 'border-slate-200 bg-white hover:bg-slate-50');
    document.getElementById('card_type_schedule').className = 'p-3.5 rounded-2xl border-2 cursor-pointer transition text-center select-none flex flex-col items-center justify-center gap-1.5 timing-card ' + (type === 'schedule' ? 'border-purple-600 bg-purple-50/60' : 'border-slate-200 bg-white hover:bg-slate-50');
    document.getElementById('card_type_draft').className = 'p-3.5 rounded-2xl border-2 cursor-pointer transition text-center select-none flex flex-col items-center justify-center gap-1.5 timing-card ' + (type === 'draft' ? 'border-purple-600 bg-purple-50/60' : 'border-slate-200 bg-white hover:bg-slate-50');

    if (type === 'schedule') {
        picker.classList.remove('hidden');
        submitBtn.className = 'w-full bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white font-bold py-3.5 px-6 rounded-2xl shadow-lg shadow-blue-600/30 transition text-sm flex items-center justify-center gap-2';
        submitIcon.className = 'fa-solid fa-clock';
        submitLabel.innerText = 'جدولة المنشور في الموعد المحدد';
    } else if (type === 'draft') {
        picker.classList.add('hidden');
        submitBtn.className = 'w-full bg-slate-800 hover:bg-slate-900 text-white font-bold py-3.5 px-6 rounded-2xl shadow-md transition text-sm flex items-center justify-center gap-2';
        submitIcon.className = 'fa-solid fa-floppy-disk';
        submitLabel.innerText = 'حفظ المنشور كمسودة للرجوع إليها';
    } else {
        picker.classList.add('hidden');
        submitBtn.className = 'w-full bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white font-bold py-3.5 px-6 rounded-2xl shadow-lg shadow-purple-600/30 transition text-sm flex items-center justify-center gap-2';
        submitIcon.className = 'fa-solid fa-paper-plane';
        submitLabel.innerText = 'نشر المحتوى الآن على القنوات المحددة';
    }
}

function switchPreviewPlatform(platform) {
    document.getElementById('preview_wrap_facebook').classList.add('hidden');
    document.getElementById('preview_wrap_instagram').classList.add('hidden');
    document.getElementById('preview_wrap_tiktok').classList.add('hidden');

    document.getElementById('tab_preview_facebook').className = 'px-2.5 py-1 rounded-lg font-bold transition text-slate-600 hover:text-blue-600';
    document.getElementById('tab_preview_instagram').className = 'px-2.5 py-1 rounded-lg font-bold transition text-slate-600 hover:text-pink-600';
    document.getElementById('tab_preview_tiktok').className = 'px-2.5 py-1 rounded-lg font-bold transition text-slate-600 hover:text-slate-900';

    if (platform === 'facebook') {
        document.getElementById('preview_wrap_facebook').classList.remove('hidden');
        document.getElementById('tab_preview_facebook').className = 'px-2.5 py-1 rounded-lg font-bold transition bg-white text-blue-600 shadow-xs';
    } else if (platform === 'instagram') {
        document.getElementById('preview_wrap_instagram').classList.remove('hidden');
        document.getElementById('tab_preview_instagram').className = 'px-2.5 py-1 rounded-lg font-bold transition bg-white text-pink-600 shadow-xs';
    } else if (platform === 'tiktok') {
        document.getElementById('preview_wrap_tiktok').classList.remove('hidden');
        document.getElementById('tab_preview_tiktok').className = 'px-2.5 py-1 rounded-lg font-bold transition bg-white text-slate-900 shadow-xs';
    }
}

function handleMediaUpload(files) {
    if (!files || files.length === 0) return;

    var progress = document.getElementById('media_upload_progress');
    progress.classList.remove('hidden');

    var uploadPromises = [];
    for (var i = 0; i < files.length; i++) {
        uploadPromises.push(uploadSingleFile(files[i]));
    }

    Promise.all(uploadPromises).then(function() {
        progress.classList.add('hidden');
    }).catch(function() {
        progress.classList.add('hidden');
    });
}

function uploadSingleFile(file) {
    var formData = new FormData();
    formData.append('action', 'upload_media');
    formData.append('csrf_token', csrfToken);
    formData.append('file', file);

    return fetch('social_ajax.php', {
        method: 'POST',
        body: formData
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (data.success) {
            // Append hidden input
            var hiddenWrap = document.getElementById('hidden_media_inputs');
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'media_ids[]';
            input.value = data.media_id;
            input.id = 'media_input_' + data.media_id;
            hiddenWrap.appendChild(input);

            // Append preview card
            var previewList = document.getElementById('media_preview_list');
            var card = document.createElement('div');
            card.id = 'preview_card_' + data.media_id;
            card.className = 'relative group rounded-2xl overflow-hidden border border-slate-200 bg-slate-100 aspect-square';
            
            var mediaEl = '';
            if (data.file_type === 'video') {
                mediaEl = '<video src="' + data.file_path + '" class="w-full h-full object-cover"></video>' +
                          '<span class="absolute bottom-1 right-1 bg-black/70 text-white text-[9px] px-1.5 py-0.5 rounded font-bold">فيديو</span>';
            } else {
                mediaEl = '<img src="' + data.file_path + '" class="w-full h-full object-cover">';
            }

            card.innerHTML = mediaEl + 
                '<button type="button" onclick="removeMedia(' + data.media_id + ')" class="absolute top-1 left-1 bg-rose-600 hover:bg-rose-700 text-white w-6 h-6 rounded-full flex items-center justify-center text-xs shadow-md transition" title="حذف">' +
                '<i class="fa-solid fa-xmark"></i>' +
                '</button>';
            previewList.appendChild(card);

            // Update live previews
            updateLivePreviewMedia(data.file_path, data.file_type);
        } else {
            alert('فشل رفع الملف: ' + (data.error || 'خطأ غير معروف'));
        }
    })
    .catch(function(err) {
        console.error(err);
        alert('حدث خطأ في الاتصال أثناء رفع الملف.');
    });
}

function removeMedia(mediaId) {
    if (!confirm('هل تريد إزالة هذا الملف من المنشور؟')) return;

    var input = document.getElementById('media_input_' + mediaId);
    if (input) input.remove();

    var card = document.getElementById('preview_card_' + mediaId);
    if (card) card.remove();

    var formData = new FormData();
    formData.append('action', 'delete_media');
    formData.append('csrf_token', csrfToken);
    formData.append('media_id', mediaId);
    fetch('social_ajax.php', { method: 'POST', body: formData });
}

function updateLivePreviewMedia(url, type) {
    var fb = document.getElementById('live_media_facebook');
    var ig = document.getElementById('live_media_instagram');
    var tt = document.getElementById('live_media_tiktok');

    if (type === 'video') {
        var vidHtml = '<video src="' + url + '" autoplay loop muted class="w-full h-full object-cover"></video>';
        fb.innerHTML = vidHtml;
        ig.innerHTML = vidHtml;
        tt.innerHTML = vidHtml;
    } else {
        var imgHtml = '<img src="' + url + '" class="w-full h-full object-cover">';
        fb.innerHTML = imgHtml;
        ig.innerHTML = imgHtml;
        tt.innerHTML = '<img src="' + url + '" class="w-full h-full object-cover opacity-80">';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    var caption = document.getElementById('post_caption_input').value;
    if (caption) onCaptionChange(caption);
});
</script>

<?php include_once __DIR__ . '/includes/footer.php'; ?>
