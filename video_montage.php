<?php
/**
 * Video Montage Studio (استوديو المونتاج التلقائي)
 * Multi-Tenant SaaS Feature:
 * - Drag & Drop Photos + Videos
 * - Custom PNG Frame Overlay per Tenant
 * - Branded Company Logo Outro
 * - Direct Shortcut from Real Estate Project Reports (Last 7 Days / 30 Days / All)
 * - 100% Client-Side Canvas + MediaRecorder Rendering (Shared Hosting Compatible)
 */

require_once __DIR__ . '/config/app.php';
requireLogin();

require_once __DIR__ . '/includes/social/SocialSecurity.php';

$currentUser = getCurrentUser();
$activeCompanyId = (int)($currentUser['active_company_id'] ?? $currentUser['company_id']);

if (empty($activeCompanyId)) {
    die('الحساب غير مرتبط بأي شركة نشطة.');
}

$db = getDBConnection();

// Handle POST Actions (Upload Frame, Delete Frame, Set Default Frame, Save Outro Settings)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireNotViewOnly();
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('خطأ في التحقق من صحة الطلب (CSRF)');
    }

    $action = $_POST['action'] ?? '';
    $redirectQuery = !empty($_POST['redirect_query']) ? ('?' . ltrim($_POST['redirect_query'], '?')) : '';

    if ($action === 'upload_frame') {
        $frameName = trim($_POST['frame_name'] ?? 'فريم مخصص');
        $aspectRatio = in_array($_POST['aspect_ratio'] ?? '', ['9:16', '1:1', '16:9']) ? $_POST['aspect_ratio'] : '9:16';
        $setDefault = !empty($_POST['is_default']) ? 1 : 0;

        if (!isset($_FILES['frame_file']) || $_FILES['frame_file']['error'] !== UPLOAD_ERR_OK) {
            setFlash('error', 'يرجى اختيار ملف صورة الفريم (يفضل PNG بخلفية شفافة).');
            header('Location: video_montage.php' . $redirectQuery);
            exit;
        }

        $origName = $_FILES['frame_file']['name'];
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, ['png', 'webp'])) {
            setFlash('error', 'صيغة الفريم يجب أن تكون PNG أو WebP للحفاظ على شفافية المنتصف.');
            header('Location: video_montage.php' . $redirectQuery);
            exit;
        }

        $relDir = "uploads/montage_frames/{$activeCompanyId}";
        $absDir = APP_PATH . '/' . $relDir;
        if (!is_dir($absDir)) {
            mkdir($absDir, 0755, true);
        }

        $safeName = 'frame_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (move_uploaded_file($_FILES['frame_file']['tmp_name'], $absDir . '/' . $safeName)) {
            $relPath = $relDir . '/' . $safeName;
            if ($setDefault) {
                $db->prepare("UPDATE montage_frames SET is_default = 0 WHERE tenant_id = :tid")->execute([':tid' => $activeCompanyId]);
            }
            $db->prepare("
                INSERT INTO montage_frames (tenant_id, name, file_path, aspect_ratio, is_default, created_at)
                VALUES (:tid, :name, :fpath, :ar, :def, :now)
            ")->execute([
                ':tid' => $activeCompanyId,
                ':name' => $frameName ?: 'فريم مخصص',
                ':fpath' => $relPath,
                ':ar' => $aspectRatio,
                ':def' => $setDefault,
                ':now' => date('Y-m-d H:i:s')
            ]);
            setFlash('success', 'تم رفع وإضافة الفريم بنجاح!');
        } else {
            setFlash('error', 'فشل حفظ ملف الفريم على السيرفر.');
        }
        header('Location: video_montage.php' . $redirectQuery);
        exit;
    }

    if ($action === 'set_default_frame') {
        $frameId = (int)($_POST['frame_id'] ?? 0);
        $db->prepare("UPDATE montage_frames SET is_default = 0 WHERE tenant_id = :tid")->execute([':tid' => $activeCompanyId]);
        $db->prepare("UPDATE montage_frames SET is_default = 1 WHERE id = :id AND tenant_id = :tid")->execute([':id' => $frameId, ':tid' => $activeCompanyId]);
        setFlash('success', 'تم تعيين الفريم كافتراضي للمونتاج.');
        header('Location: video_montage.php' . $redirectQuery);
        exit;
    }

    if ($action === 'delete_frame') {
        $frameId = (int)($_POST['frame_id'] ?? 0);
        $stmt = $db->prepare("SELECT file_path FROM montage_frames WHERE id = :id AND tenant_id = :tid LIMIT 1");
        $stmt->execute([':id' => $frameId, ':tid' => $activeCompanyId]);
        $row = $stmt->fetch();
        if ($row) {
            $full = APP_PATH . '/' . $row['file_path'];
            if (file_exists($full)) @unlink($full);
            $db->prepare("DELETE FROM montage_frames WHERE id = :id AND tenant_id = :tid")->execute([':id' => $frameId, ':tid' => $activeCompanyId]);
            setFlash('success', 'تم حذف الفريم بنجاح.');
        }
        header('Location: video_montage.php' . $redirectQuery);
        exit;
    }
}

// Fetch tenant's saved frames
$framesStmt = $db->prepare("SELECT * FROM montage_frames WHERE tenant_id = :tid ORDER BY is_default DESC, id DESC");
$framesStmt->execute([':tid' => $activeCompanyId]);
$savedFrames = $framesStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch tenant's projects (for the Project Reports Shortcut importer)
$projectsList = [];
if (hasModule('projects')) {
    $pStmt = $db->prepare("SELECT id, name FROM projects WHERE company_id = :cid ORDER BY id DESC");
    $pStmt->execute([':cid' => $activeCompanyId]);
    $projectsList = $pStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Check if launched via Project Shortcut (?project_id=X&days=7)
$preloadedProject = null;
$preloadedMedia = [];
$selectedProjectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$selectedDays = $_GET['days'] ?? '7';

if ($selectedProjectId > 0) {
    $projChk = $db->prepare("SELECT id, name, location FROM projects WHERE id = :pid AND company_id = :cid LIMIT 1");
    $projChk->execute([':pid' => $selectedProjectId, ':cid' => $activeCompanyId]);
    $preloadedProject = $projChk->fetch(PDO::FETCH_ASSOC);

    if ($preloadedProject) {
        $dateClause = "";
        $params = [':pid' => $selectedProjectId, ':cid' => $activeCompanyId];
        if (is_numeric($selectedDays) && (int)$selectedDays > 0) {
            $cutoffDate = date('Y-m-d', strtotime('-' . (int)$selectedDays . ' days'));
            $dateClause = " AND r.report_date >= :cutoff";
            $params[':cutoff'] = $cutoffDate;
        }

        // Fetch reports in range
        $repStmt = $db->prepare("
            SELECT r.id, r.report_date, r.title, r.video_file
            FROM project_daily_reports r
            WHERE r.project_id = :pid AND r.company_id = :cid {$dateClause}
            ORDER BY r.report_date ASC, r.id ASC
        ");
        $repStmt->execute($params);
        $reports = $repStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($reports)) {
            $repIds = array_column($reports, 'id');
            $placeholders = implode(',', array_fill(0, count($repIds), '?'));
            $mStmt = $db->prepare("
                SELECT m.id, m.file_path, m.file_type, m.caption, r.report_date
                FROM project_report_media m
                JOIN project_daily_reports r ON m.report_id = r.id
                WHERE m.report_id IN ($placeholders) AND m.company_id = ?
                ORDER BY r.report_date ASC, m.id ASC
            ");
            $mStmt->execute(array_merge($repIds, [$activeCompanyId]));
            $mediaRows = $mStmt->fetchAll(PDO::FETCH_ASSOC);

            $seenPaths = [];
            foreach ($mediaRows as $mr) {
                if (!empty($mr['file_path']) && file_exists(APP_PATH . '/' . $mr['file_path']) && !isset($seenPaths[$mr['file_path']])) {
                    $seenPaths[$mr['file_path']] = true;
                    $preloadedMedia[] = [
                        'url' => $mr['file_path'],
                        'type' => $mr['file_type'],
                        'name' => basename($mr['file_path']),
                        'label' => $mr['caption'] ?: $mr['report_date']
                    ];
                }
            }

            // Include legacy video_file if not already included
            foreach ($reports as $rep) {
                if (!empty($rep['video_file']) && file_exists(APP_PATH . '/' . $rep['video_file']) && !isset($seenPaths[$rep['video_file']])) {
                    $seenPaths[$rep['video_file']] = true;
                    $preloadedMedia[] = [
                        'url' => $rep['video_file'],
                        'type' => 'video',
                        'name' => basename($rep['video_file']),
                        'label' => $rep['title']
                    ];
                }
            }
        }
    }
}

$companyLogo = (!empty($currentUser['company_logo']) && file_exists(APP_PATH . '/' . $currentUser['company_logo'])) ? $currentUser['company_logo'] : '';
$companyName = $currentUser['company_name'] ?? 'شركتي';
$companyPhone = $currentUser['company_phone'] ?? '';
$companyColor = $currentUser['company_dashboard_color'] ?? '#0f2942';

$pageTitle = 'استوديو المونتاج التلقائي للفيديو';
include_once __DIR__ . '/includes/header.php';
?>

<!-- Studio Top Header -->
<div class="mb-6 bg-white p-4 sm:p-5 rounded-3xl border border-slate-200/80 shadow-xs flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
    <div class="flex items-center space-x-3.5 space-x-reverse">
        <div class="w-12 h-12 rounded-2xl bg-gradient-to-tr from-indigo-600 via-purple-600 to-rose-500 text-white flex items-center justify-center text-xl shadow-md shadow-indigo-600/20 shrink-0">
            <i class="fa-solid fa-clapperboard"></i>
        </div>
        <div>
            <div class="flex items-center gap-2">
                <h1 class="text-lg sm:text-xl font-black text-slate-900">استوديو المونتاج التلقائي (Video Montage)</h1>
                <span class="bg-indigo-100 text-indigo-700 text-[10px] font-black px-2.5 py-0.5 rounded-full border border-indigo-200">تلقائي بالفريم والشعار</span>
            </div>
            <p class="text-xs text-slate-500 font-medium">
                ادمج صور وفيديوهات الموقع تلقائياً مع الفريم الرسمي لشركتك وخاتمة Outro بشعار الشركة في ثوانٍ
            </p>
        </div>
    </div>

    <?php if (hasModule('social_media')): ?>
        <a href="social_post_create.php" class="inline-flex items-center gap-1.5 bg-purple-50 hover:bg-purple-100 text-purple-700 font-bold px-3.5 py-2.5 rounded-xl text-xs transition shrink-0">
            <i class="fa-solid fa-share-nodes"></i>
            <span>مركز السوشيال ميديا</span>
        </a>
    <?php endif; ?>
</div>

<?php if ($preloadedProject): ?>
    <!-- Preloaded Project Reports Banner -->
    <div class="mb-6 p-4 rounded-2xl border <?= !empty($preloadedMedia) ? 'bg-emerald-50 border-emerald-200 text-emerald-900' : 'bg-amber-50 border-amber-200 text-amber-900' ?> flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs">
        <div class="flex items-center gap-3">
            <i class="fa-solid <?= !empty($preloadedMedia) ? 'fa-circle-check text-emerald-600' : 'fa-triangle-exclamation text-amber-600' ?> text-xl shrink-0"></i>
            <div>
                <strong class="font-black block">
                    مشروع: <?= htmlspecialchars($preloadedProject['name']) ?> (<?= $selectedDays === 'all' ? 'كل التقارير' : "تقارير آخر {$selectedDays} أيام" ?>)
                </strong>
                <span>
                    <?php if (!empty($preloadedMedia)): ?>
                        تم استيراد <strong><?= count($preloadedMedia) ?></strong> ملف (صور وفيديوهات) تلقائياً إلى شريط المونتاج! اضغط "بدء المونتاج" لإخراج الفيديو.
                    <?php else: ?>
                        لم يتم العثور على صور أو فيديوهات في تقارير هذه الفترة. يمكنك تغيير المدة أو رفع ملفات يدوياً بالأسفل.
                    <?php endif; ?>
                </span>
            </div>
        </div>
        <a href="project_view.php?id=<?= $selectedProjectId ?>&tab=tab-reports" class="bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 font-bold px-3 py-1.5 rounded-xl shrink-0">
            العودة للمشروع
        </a>
    </div>
<?php endif; ?>

<!-- Main 2-Column Studio Grid -->
<div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">

    <!-- RIGHT COLUMN: Controls, Media Timeline, Frame Overlay, Outro (lg:col-span-7) -->
    <div class="lg:col-span-7 space-y-5">

        <!-- 1. Quick Import from Real Estate Project Reports -->
        <?php if (!empty($projectsList)): ?>
            <div class="bg-white rounded-3xl border border-slate-200/80 shadow-xs p-5 space-y-3">
                <div class="flex items-center justify-between">
                    <h3 class="text-xs font-black text-slate-900 flex items-center gap-1.5">
                        <i class="fa-solid fa-helmet-safety text-amber-600"></i>
                        <span>استيراد سريع من تقارير المشاريع العقارية:</span>
                    </h3>
                </div>
                <form method="GET" action="video_montage.php" class="grid grid-cols-1 sm:grid-cols-12 gap-2.5">
                    <div class="sm:col-span-6">
                        <select name="project_id" required class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs font-bold focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                            <option value="">-- اختر المشروع العقاري --</option>
                            <?php foreach ($projectsList as $proj): ?>
                                <option value="<?= $proj['id'] ?>" <?= $selectedProjectId === (int)$proj['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($proj['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="sm:col-span-3">
                        <select name="days" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs font-bold focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                            <option value="7" <?= $selectedDays == '7' ? 'selected' : '' ?>>آخر 7 أيام</option>
                            <option value="14" <?= $selectedDays == '14' ? 'selected' : '' ?>>آخر 14 يوم</option>
                            <option value="30" <?= $selectedDays == '30' ? 'selected' : '' ?>>آخر 30 يوم</option>
                            <option value="all" <?= $selectedDays == 'all' ? 'selected' : '' ?>>كل التقارير</option>
                        </select>
                    </div>
                    <div class="sm:col-span-3">
                        <button type="submit" class="w-full bg-amber-600 hover:bg-amber-700 text-white font-bold py-2 px-3 rounded-xl text-xs transition flex items-center justify-center gap-1">
                            <i class="fa-solid fa-download"></i>
                            <span>جلب الوسائط</span>
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <!-- 2. Media Dropzone & Timeline -->
        <div class="bg-white rounded-3xl border border-slate-200/80 shadow-xs p-5 space-y-4">
            <div class="flex items-center justify-between">
                <h3 class="text-xs sm:text-sm font-black text-slate-900 flex items-center gap-2">
                    <span class="w-6 h-6 rounded-lg bg-indigo-100 text-indigo-700 inline-flex items-center justify-center text-xs font-black">1</span>
                    <span>الصور والفيديوهات (ترتيب العرض في الفيديو)</span>
                </h3>
                <span id="montage_clips_count" class="text-[11px] font-bold bg-indigo-50 text-indigo-700 px-2.5 py-0.5 rounded-full border border-indigo-200">
                    0 لقطات
                </span>
            </div>

            <!-- Drag & Drop Upload Box -->
            <div id="montage_dropzone"
                 onclick="document.getElementById('montage_file_input').click()"
                 ondragover="event.preventDefault(); this.classList.add('border-indigo-600','bg-indigo-50/70')"
                 ondragleave="this.classList.remove('border-indigo-600','bg-indigo-50/70')"
                 ondrop="handleMontageDrop(event)"
                 class="border-2 border-dashed border-indigo-300 hover:border-indigo-500 rounded-2xl p-5 text-center cursor-pointer transition bg-indigo-50/20">
                <input type="file" id="montage_file_input" multiple accept="image/*,video/*" onchange="handleMontageFiles(this.files)" class="hidden">
                <div class="pointer-events-none space-y-1">
                    <i class="fa-solid fa-photo-film text-indigo-500 text-2xl"></i>
                    <p class="text-xs font-black text-slate-800">اسحب وأفلت الصور والفيديوهات معاً هنا أو اضغط للاختيار</p>
                    <p class="text-[10px] text-slate-400">يمكنك إعادة ترتيب اللقطات أو حذف أي منها قبل إخراج الفيديو</p>
                </div>
            </div>

            <!-- Timeline Clips List -->
            <div id="montage_timeline_list" class="space-y-2 max-h-64 overflow-y-auto pr-0.5">
                <!-- Populated by JS -->
            </div>
        </div>

        <!-- 3. Frame Overlay Manager -->
        <div class="bg-white rounded-3xl border border-slate-200/80 shadow-xs p-5 space-y-4">
            <div class="flex items-center justify-between">
                <h3 class="text-xs sm:text-sm font-black text-slate-900 flex items-center gap-2">
                    <span class="w-6 h-6 rounded-lg bg-purple-100 text-purple-700 inline-flex items-center justify-center text-xs font-black">2</span>
                    <span>الفريم الرسمي (Frame Overlay PNG)</span>
                </h3>
                <button type="button" onclick="openModal('upload-frame-modal')" class="text-xs font-bold bg-purple-600 hover:bg-purple-700 text-white px-3 py-1.5 rounded-xl transition flex items-center gap-1">
                    <i class="fa-solid fa-plus"></i>
                    <span>رفع فريم جديد</span>
                </button>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3" id="frames_selector_grid">
                <!-- Option: No Frame -->
                <label class="cursor-pointer rounded-2xl border-2 p-2.5 flex flex-col items-center justify-center text-center gap-1.5 transition hover:bg-slate-50 border-slate-200" id="frame_card_none">
                    <input type="radio" name="selected_frame" value="" onchange="selectMontageFrame('')" class="hidden">
                    <div class="w-12 h-20 rounded-xl bg-slate-100 border border-dashed border-slate-300 flex items-center justify-center text-slate-400">
                        <i class="fa-solid fa-ban"></i>
                    </div>
                    <span class="text-[11px] font-bold text-slate-600">بدون فريم</span>
                </label>

                <?php foreach ($savedFrames as $idx => $fr): ?>
                    <div class="relative group rounded-2xl border-2 p-2 flex flex-col items-center justify-between text-center gap-1 transition <?= ($fr['is_default'] || $idx === 0) ? 'border-purple-600 bg-purple-50/50' : 'border-slate-200 bg-white' ?>" id="frame_card_<?= $fr['id'] ?>">
                        <label class="cursor-pointer w-full flex flex-col items-center gap-1.5">
                            <input type="radio" name="selected_frame" value="<?= htmlspecialchars($fr['file_path']) ?>" <?= ($fr['is_default'] || $idx === 0) ? 'checked' : '' ?> onchange="selectMontageFrame('<?= htmlspecialchars($fr['file_path']) ?>', '<?= $fr['id'] ?>')" class="hidden">
                            <div class="w-14 h-24 rounded-xl bg-slate-900/5 border border-slate-200 overflow-hidden flex items-center justify-center">
                                <img src="<?= htmlspecialchars($fr['file_path']) ?>" alt="Frame" class="w-full h-full object-contain">
                            </div>
                            <span class="text-[11px] font-bold text-slate-800 truncate w-full"><?= htmlspecialchars($fr['name']) ?></span>
                        </label>
                        <!-- Delete Frame button -->
                        <form method="POST" onsubmit="return confirm('حذف هذا الفريم نهائياً؟')" class="absolute top-1.5 left-1.5 opacity-0 group-hover:opacity-100 transition">
                            <?= getCSRFInput() ?>
                            <input type="hidden" name="action" value="delete_frame">
                            <input type="hidden" name="frame_id" value="<?= $fr['id'] ?>">
                            <input type="hidden" name="redirect_query" value="<?= htmlspecialchars($_SERVER['QUERY_STRING'] ?? '') ?>">
                            <button type="submit" class="w-5 h-5 rounded-full bg-rose-600 text-white text-[10px] flex items-center justify-center shadow-xs" title="حذف الفريم">
                                <i class="fa-solid fa-xmark"></i>
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if (empty($savedFrames)): ?>
                <p class="text-[11px] text-amber-700 bg-amber-50 p-2.5 rounded-xl border border-amber-200">
                    <i class="fa-solid fa-lightbulb ml-1"></i>
                    لم تقم برفع أي فريم PNG بعد. اضغط على <strong>"رفع فريم جديد"</strong> لرفع الفريم المفرغ الخاص بشركتك وسيتم حفظه تلقائياً للاستخدام الدائم.
                </p>
            <?php endif; ?>
        </div>

        <!-- 4. Outro & Rendering Settings -->
        <div class="bg-white rounded-3xl border border-slate-200/80 shadow-xs p-5 space-y-4">
            <h3 class="text-xs sm:text-sm font-black text-slate-900 flex items-center gap-2">
                <span class="w-6 h-6 rounded-lg bg-emerald-100 text-emerald-700 inline-flex items-center justify-center text-xs font-black">3</span>
                <span>إعدادات الخاتمة (Outro بشعار الشركة) والمونتاج</span>
            </h3>

            <div class="flex items-center justify-between p-3 bg-slate-50 rounded-2xl border border-slate-200">
                <label for="enable_outro_chk" class="text-xs font-bold text-slate-800 cursor-pointer flex items-center gap-2">
                    <input type="checkbox" id="enable_outro_chk" checked onchange="drawPreviewFrame()" class="rounded text-indigo-600 w-4 h-4">
                    <span>إضافة مشهد Outro في نهاية الفيديو بشعار الشركة</span>
                </label>
                <span class="text-[10px] font-bold text-emerald-700 bg-emerald-100 px-2 py-0.5 rounded-full">موصى به</span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                <div>
                    <label class="block text-[11px] font-bold text-slate-600 mb-1">عنوان الخاتمة (اسم الشركة)</label>
                    <input type="text" id="outro_title_input" value="<?= htmlspecialchars($companyName) ?>" oninput="drawPreviewFrame()" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 font-bold">
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-slate-600 mb-1">النص الفرعي (رقم الهاتف أو المشروع)</label>
                    <input type="text" id="outro_subtitle_input" value="<?= htmlspecialchars($preloadedProject['name'] ?? $companyPhone) ?>" placeholder="مثال: للتواصل 05xxxxxxxx" oninput="drawPreviewFrame()" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 font-bold">
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-slate-600 mb-1">مقاس الفيديو (Aspect Ratio)</label>
                    <select id="montage_aspect_select" onchange="updateCanvasDimensions()" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 font-bold">
                        <option value="9:16" selected>عمودي 9:16 (Reels / TikTok / Stories)</option>
                        <option value="1:1">مربع 1:1 (Instagram / Facebook)</option>
                        <option value="16:9">أفقي 16:9 (YouTube / عرضي)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-slate-600 mb-1">مدة عرض كل صورة</label>
                    <select id="photo_duration_select" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 font-bold">
                        <option value="2">2 ثانية لكل صورة</option>
                        <option value="3" selected>3 ثوانٍ لكل صورة</option>
                        <option value="4">4 ثوانٍ لكل صورة</option>
                        <option value="5">5 ثوانٍ لكل صورة</option>
                    </select>
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-slate-600 mb-1">حركة الصور (Transition)</label>
                    <select id="transition_select" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 font-bold">
                        <option value="zoom" selected>تكبير سينمائي بطيء (Ken Burns)</option>
                        <option value="fade">تلاشي ناعم (Fade)</option>
                        <option value="none">ثابت (بدون حركة)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-slate-600 mb-1">لون خلفية الـ Outro</label>
                    <div class="flex items-center gap-2">
                        <input type="color" id="outro_bg_color" value="<?= htmlspecialchars($companyColor) ?>" onchange="drawPreviewFrame()" class="w-10 h-9 rounded-lg border border-slate-300 cursor-pointer p-0.5 bg-white">
                        <span class="text-[11px] text-slate-500">تدرج لوني احترافي</span>
                    </div>
                </div>
            </div>

            <!-- Optional Background Audio -->
            <div class="pt-2 border-t border-slate-100">
                <label class="block text-[11px] font-bold text-slate-600 mb-1">
                    <i class="fa-solid fa-music text-indigo-500 ml-1"></i> موسيقى أو صوت خلفي للفيديو (MP3 / WAV - اختياري):
                </label>
                <input type="file" id="bg_audio_input" accept="audio/*" class="w-full text-xs text-slate-600 file:ml-2 file:py-1.5 file:px-3 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 border border-slate-200 rounded-xl p-1.5 bg-slate-50">
            </div>
        </div>

    </div>

    <!-- LEFT COLUMN: Live Canvas Studio & Output Video (lg:col-span-5) -->
    <div class="lg:col-span-5 sticky top-6 space-y-4">
        <div class="bg-white rounded-3xl border border-slate-200/80 shadow-xs p-5 space-y-4">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <h3 class="font-black text-slate-900 text-sm flex items-center gap-2">
                    <i class="fa-solid fa-display text-indigo-600"></i>
                    <span>معاينة وإخراج الفيديو النهائي</span>
                </h3>
                <span id="render_status_badge" class="text-[10px] font-bold px-2.5 py-0.5 rounded-full bg-slate-100 text-slate-600">
                    جاهز للمونتاج
                </span>
            </div>

            <!-- Canvas Live Preview Area -->
            <div class="bg-slate-950 rounded-2xl p-3 flex items-center justify-center overflow-hidden relative">
                <canvas id="montage_canvas" width="720" height="1280" class="max-h-[440px] w-auto rounded-xl shadow-2xl bg-black"></canvas>
            </div>

            <!-- Render Progress Bar -->
            <div id="montage_render_progress_wrap" class="hidden space-y-1.5 p-3 bg-indigo-50 rounded-2xl border border-indigo-200">
                <div class="flex items-center justify-between text-xs font-bold text-indigo-900">
                    <span id="montage_render_label"><i class="fa-solid fa-spinner fa-spin ml-1"></i> جاري المونتاج...</span>
                    <span id="montage_render_pct" class="font-mono">0%</span>
                </div>
                <div class="w-full bg-indigo-200 h-2.5 rounded-full overflow-hidden">
                    <div id="montage_render_bar" class="h-full bg-indigo-600 rounded-full transition-all duration-150" style="width: 0%"></div>
                </div>
            </div>

            <!-- Start Render CTA -->
            <button type="button" id="btn_start_montage" onclick="startMontageRender()" class="w-full bg-gradient-to-r from-indigo-600 via-purple-600 to-rose-500 hover:opacity-95 text-white font-black py-3.5 px-6 rounded-2xl shadow-lg shadow-indigo-600/25 transition text-sm flex items-center justify-center gap-2">
                <i class="fa-solid fa-wand-magic-sparkles"></i>
                <span>بدء المونتاج وإخراج الفيديو الآن</span>
            </button>

            <!-- Output Video Result Box (Shown after render) -->
            <div id="montage_output_box" class="hidden space-y-3 pt-3 border-t border-slate-100">
                <div class="flex items-center justify-between text-xs font-black text-emerald-700">
                    <span><i class="fa-solid fa-circle-check ml-1"></i> تم إخراج الفيديو بنجاح!</span>
                    <span id="output_video_size" class="font-mono text-[11px] bg-emerald-50 px-2 py-0.5 rounded-lg"></span>
                </div>

                <video id="montage_output_video" controls playsinline class="w-full max-h-[380px] rounded-2xl bg-black border border-slate-200"></video>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                    <a id="btn_download_montage" href="#" download="montage_video.webm" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2.5 px-4 rounded-xl text-xs text-center transition flex items-center justify-center gap-1.5 shadow-sm">
                        <i class="fa-solid fa-download"></i>
                        <span>تحميل الفيديو</span>
                    </a>
                    <?php if (hasModule('social_media')): ?>
                        <button type="button" onclick="sendMontageToSocial()" id="btn_send_to_social" class="bg-purple-600 hover:bg-purple-700 text-white font-bold py-2.5 px-4 rounded-xl text-xs transition flex items-center justify-center gap-1.5 shadow-sm">
                            <i class="fa-solid fa-share-nodes"></i>
                            <span>نشر في السوشيال ميديا</span>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- Modal: Upload New PNG Frame -->
<div id="upload-frame-modal" class="modal-container fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4 overflow-y-auto">
    <div class="modal-backdrop fixed inset-0" onclick="closeModal('upload-frame-modal')"></div>
    <div class="relative bg-white rounded-3xl shadow-2xl max-w-md w-full p-6 modal-animate-enter z-10 border border-slate-100">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
            <h3 class="font-black text-slate-900 text-base">رفع فريم جديد للمونتاج (PNG)</h3>
            <button type="button" onclick="closeModal('upload-frame-modal')" class="text-slate-400 hover:text-slate-600 text-lg">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <form method="POST" action="video_montage.php" enctype="multipart/form-data" class="space-y-4">
            <?= getCSRFInput() ?>
            <input type="hidden" name="action" value="upload_frame">
            <input type="hidden" name="redirect_query" value="<?= htmlspecialchars($_SERVER['QUERY_STRING'] ?? '') ?>">

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">اسم الفريم</label>
                <input type="text" name="frame_name" required placeholder="مثال: فريم التقارير العمودي" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3.5 py-2.5 text-xs font-bold">
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">ملف الفريم (PNG بخلفية شفافة في المنتصف)</label>
                <input type="file" name="frame_file" accept=".png,.webp" required class="w-full text-xs text-slate-600 file:ml-2 file:py-2 file:px-3 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-purple-50 file:text-purple-700 border border-slate-300 rounded-xl p-2 bg-slate-50">
            </div>

            <div>
                <label class="flex items-center gap-2 text-xs font-bold text-slate-700 cursor-pointer">
                    <input type="checkbox" name="is_default" value="1" checked class="rounded text-purple-600 w-4 h-4">
                    <span>تعيين كفريم افتراضي تلقائي</span>
                </label>
            </div>

            <button type="submit" class="w-full bg-purple-600 hover:bg-purple-700 text-white font-bold py-2.5 px-4 rounded-xl text-xs shadow-md transition">
                حفظ ورفع الفريم
            </button>
        </form>
    </div>
</div>

<script>
const csrfToken = "<?= generateCSRFToken() ?>";
const companyLogoUrl = "<?= htmlspecialchars($companyLogo) ?>";
const preloadedProjectMedia = <?= json_encode($preloadedMedia, JSON_UNESCAPED_UNICODE) ?>;

let montageClips = []; // { id, type: 'image'|'video', name, url, file: File|null }
let selectedFrameUrl = "<?= !empty($savedFrames) ? htmlspecialchars($savedFrames[0]['file_path']) : '' ?>";
let selectedFrameImg = null;
let logoImgObj = null;
let renderedVideoBlob = null;

// Preload logo if exists
if (companyLogoUrl) {
    logoImgObj = new Image();
    logoImgObj.crossOrigin = 'anonymous';
    logoImgObj.src = companyLogoUrl;
    logoImgObj.onload = () => drawPreviewFrame();
}

// Preload initial default frame
if (selectedFrameUrl) {
    loadFrameImage(selectedFrameUrl);
}

// Preload project reports media if launched from project shortcut
if (Array.isArray(preloadedProjectMedia) && preloadedProjectMedia.length > 0) {
    preloadedProjectMedia.forEach((m, idx) => {
        montageClips.push({
            id: 'proj_' + idx + '_' + Date.now(),
            type: m.type === 'video' ? 'video' : 'image',
            name: m.name || ('ملف ' + (idx + 1)),
            url: m.url,
            file: null
        });
    });
}

function loadFrameImage(url) {
    if (!url) {
        selectedFrameImg = null;
        drawPreviewFrame();
        return;
    }
    const img = new Image();
    img.crossOrigin = 'anonymous';
    img.onload = () => {
        selectedFrameImg = img;
        drawPreviewFrame();
    };
    img.src = url;
}

function selectMontageFrame(url, frameId) {
    selectedFrameUrl = url;
    document.querySelectorAll('#frames_selector_grid > div, #frame_card_none').forEach(el => {
        el.classList.remove('border-purple-600', 'bg-purple-50/50');
        el.classList.add('border-slate-200');
    });
    const activeEl = frameId ? document.getElementById('frame_card_' + frameId) : document.getElementById('frame_card_none');
    if (activeEl) {
        activeEl.classList.remove('border-slate-200');
        activeEl.classList.add('border-purple-600', 'bg-purple-50/50');
    }
    loadFrameImage(url);
}

function handleMontageDrop(e) {
    e.preventDefault();
    document.getElementById('montage_dropzone').classList.remove('border-indigo-600', 'bg-indigo-50/70');
    if (e.dataTransfer && e.dataTransfer.files) {
        handleMontageFiles(e.dataTransfer.files);
    }
}

function handleMontageFiles(fileList) {
    Array.from(fileList).forEach((file, idx) => {
        const isVid = file.type.startsWith('video/') || /\.(mp4|mov|webm|mkv|avi)$/i.test(file.name);
        const isImg = file.type.startsWith('image/') || /\.(jpg|jpeg|png|webp|gif)$/i.test(file.name);
        if (!isVid && !isImg) return;

        montageClips.push({
            id: 'local_' + Date.now() + '_' + idx + '_' + Math.random().toString(36).slice(2, 6),
            type: isVid ? 'video' : 'image',
            name: file.name,
            url: URL.createObjectURL(file),
            file: file
        });
    });
    renderTimeline();
    drawPreviewFrame();
}

function moveClip(index, dir) {
    const newIdx = index + dir;
    if (newIdx < 0 || newIdx >= montageClips.length) return;
    const temp = montageClips[index];
    montageClips[index] = montageClips[newIdx];
    montageClips[newIdx] = temp;
    renderTimeline();
}

function removeClip(index) {
    montageClips.splice(index, 1);
    renderTimeline();
    drawPreviewFrame();
}

function renderTimeline() {
    const list = document.getElementById('montage_timeline_list');
    const badge = document.getElementById('montage_clips_count');
    if (!list) return;

    badge.innerText = montageClips.length + ' لقطات';
    list.innerHTML = '';

    if (montageClips.length === 0) {
        list.innerHTML = '<div class="text-center py-4 text-xs text-slate-400 italic">لم يتم إضافة أي صور أو فيديوهات بعد</div>';
        return;
    }

    montageClips.forEach((clip, idx) => {
        const row = document.createElement('div');
        row.className = 'flex items-center justify-between p-2 rounded-2xl bg-slate-50 border border-slate-200 gap-2';

        const thumb = clip.type === 'video'
            ? `<div class="w-11 h-11 rounded-xl bg-rose-100 text-rose-600 flex items-center justify-center shrink-0"><i class="fa-solid fa-film"></i></div>`
            : `<img src="${clip.url}" class="w-11 h-11 rounded-xl object-cover border border-slate-200 shrink-0">`;

        row.innerHTML = `
            <div class="flex items-center gap-2.5 min-w-0 flex-1">
                <span class="text-[10px] font-black text-slate-400 w-4 text-center">${idx + 1}</span>
                ${thumb}
                <div class="min-w-0">
                    <p class="text-xs font-bold text-slate-800 truncate max-w-[200px]">${clip.name}</p>
                    <span class="text-[10px] font-bold ${clip.type === 'video' ? 'text-rose-600' : 'text-blue-600'}">${clip.type === 'video' ? 'فيديو' : 'صورة'}</span>
                </div>
            </div>
            <div class="flex items-center gap-1 shrink-0">
                <button type="button" onclick="moveClip(${idx}, -1)" class="w-7 h-7 rounded-lg bg-white border border-slate-200 hover:bg-slate-100 text-slate-600 text-[10px]" title="تحريك لأعلى"><i class="fa-solid fa-arrow-up"></i></button>
                <button type="button" onclick="moveClip(${idx}, 1)" class="w-7 h-7 rounded-lg bg-white border border-slate-200 hover:bg-slate-100 text-slate-600 text-[10px]" title="تحريك لأسفل"><i class="fa-solid fa-arrow-down"></i></button>
                <button type="button" onclick="removeClip(${idx})" class="w-7 h-7 rounded-lg bg-rose-50 hover:bg-rose-600 hover:text-white text-rose-600 text-[10px]" title="حذف"><i class="fa-solid fa-xmark"></i></button>
            </div>
        `;
        list.appendChild(row);
    });
}

function updateCanvasDimensions() {
    const ar = document.getElementById('montage_aspect_select').value;
    const canvas = document.getElementById('montage_canvas');
    if (ar === '9:16') { canvas.width = 720; canvas.height = 1280; }
    else if (ar === '1:1') { canvas.width = 1080; canvas.height = 1080; }
    else { canvas.width = 1280; canvas.height = 720; }
    drawPreviewFrame();
}

function drawCoverMedia(ctx, media, w, h, zoom = 1.0, alpha = 1.0) {
    const mw = media.videoWidth || media.naturalWidth || media.width;
    const mh = media.videoHeight || media.naturalHeight || media.height;
    if (!mw || !mh) return;

    ctx.save();
    ctx.globalAlpha = alpha;
    const scale = Math.max(w / mw, h / mh) * zoom;
    const dw = mw * scale;
    const dh = mh * scale;
    const dx = (w - dw) / 2;
    const dy = (h - dh) / 2;
    ctx.drawImage(media, dx, dy, dw, dh);
    ctx.restore();
}

function drawOutroScene(ctx, w, h, progress) {
    const bgHex = document.getElementById('outro_bg_color').value || '#0f2942';
    const title = document.getElementById('outro_title_input').value || '';
    const subtitle = document.getElementById('outro_subtitle_input').value || '';

    const grad = ctx.createRadialGradient(w / 2, h / 2, 20, w / 2, h / 2, Math.max(w, h) * 0.8);
    grad.addColorStop(0, bgHex);
    grad.addColorStop(1, '#040b14');
    ctx.fillStyle = grad;
    ctx.fillRect(0, 0, w, h);

    const alpha = Math.min(1, progress * 2.5);
    const scale = 0.9 + Math.min(0.1, progress * 0.2);

    ctx.save();
    ctx.globalAlpha = alpha;
    ctx.translate(w / 2, h / 2);
    ctx.scale(scale, scale);

    // Draw Company Logo in centered white rounded badge
    if (logoImgObj && logoImgObj.complete && logoImgObj.naturalWidth > 0) {
        const boxSize = Math.min(w, h) * 0.28;
        ctx.fillStyle = '#ffffff';
        ctx.beginPath();
        ctx.roundRect(-boxSize / 2, -boxSize - 20, boxSize, boxSize, 28);
        ctx.fill();

        const pad = boxSize * 0.14;
        const lw = logoImgObj.naturalWidth;
        const lh = logoImgObj.naturalHeight;
        const lScale = Math.min((boxSize - pad * 2) / lw, (boxSize - pad * 2) / lh);
        const dlw = lw * lScale;
        const dlh = lh * lScale;
        ctx.drawImage(logoImgObj, -dlw / 2, -boxSize - 20 + (boxSize - dlh) / 2, dlw, dlh);
    }

    // Company Title
    ctx.fillStyle = '#ffffff';
    ctx.textAlign = 'center';
    ctx.font = `900 ${Math.round(w * 0.065)}px sans-serif`;
    ctx.fillText(title, 0, 50);

    // Subtitle / Phone
    if (subtitle) {
        ctx.fillStyle = '#cbd5e1';
        ctx.font = `600 ${Math.round(w * 0.04)}px sans-serif`;
        ctx.fillText(subtitle, 0, 105);
    }

    ctx.restore();
}

function drawPreviewFrame() {
    const canvas = document.getElementById('montage_canvas');
    const ctx = canvas.getContext('2d');
    const w = canvas.width;
    const h = canvas.height;

    ctx.fillStyle = '#0f172a';
    ctx.fillRect(0, 0, w, h);

    if (montageClips.length > 0 && montageClips[0].type === 'image') {
        const img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = () => {
            drawCoverMedia(ctx, img, w, h);
            if (selectedFrameImg) ctx.drawImage(selectedFrameImg, 0, 0, w, h);
        };
        img.src = montageClips[0].url;
    } else {
        if (selectedFrameImg) {
            ctx.drawImage(selectedFrameImg, 0, 0, w, h);
        } else if (document.getElementById('enable_outro_chk').checked) {
            drawOutroScene(ctx, w, h, 1);
        }
    }
}

async function startMontageRender() {
    if (montageClips.length === 0) {
        alert('يرجى إضافة صورة أو فيديو واحد على الأقل للبدء بالمونتاج.');
        return;
    }
    if (typeof MediaRecorder === 'undefined') {
        alert('متصفحك لا يدعم تصدير الفيديو المباشر (MediaRecorder). يرجى استخدام Chrome أو Edge أو Safari الحديث.');
        return;
    }

    const btn = document.getElementById('btn_start_montage');
    const progWrap = document.getElementById('montage_render_progress_wrap');
    const progLabel = document.getElementById('montage_render_label');
    const progPct = document.getElementById('montage_render_pct');
    const progBar = document.getElementById('montage_render_bar');
    const statusBadge = document.getElementById('render_status_badge');

    btn.disabled = true;
    btn.classList.add('opacity-60', 'cursor-not-allowed');
    progWrap.classList.remove('hidden');
    document.getElementById('montage_output_box').classList.add('hidden');
    statusBadge.innerText = 'جاري الرندر...';
    statusBadge.className = 'text-[10px] font-bold px-2.5 py-0.5 rounded-full bg-indigo-100 text-indigo-700';

    const canvas = document.getElementById('montage_canvas');
    const ctx = canvas.getContext('2d');
    const w = canvas.width;
    const h = canvas.height;
    const fps = 30;

    const stream = canvas.captureStream(fps);
    // Optional background audio
    const audioInput = document.getElementById('bg_audio_input');
    let audioCtx = null;
    let audioEl = null;
    if (audioInput && audioInput.files && audioInput.files[0]) {
        try {
            audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            audioEl = new Audio(URL.createObjectURL(audioInput.files[0]));
            audioEl.loop = true;
            const srcNode = audioCtx.createMediaElementSource(audioEl);
            const dest = audioCtx.createMediaStreamDestination();
            srcNode.connect(dest);
            srcNode.connect(audioCtx.destination);
            dest.stream.getAudioTracks().forEach(t => stream.addTrack(t));
            await audioEl.play();
        } catch (e) { console.warn('Audio mix skipped:', e); }
    }

    const mimeTypes = ['video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm', 'video/mp4'];
    const chosenMime = mimeTypes.find(t => MediaRecorder.isTypeSupported(t)) || 'video/webm';
    const recorder = new MediaRecorder(stream, { mimeType: chosenMime, videoBitsPerSecond: 3500000 });
    const chunks = [];
    recorder.ondataavailable = e => { if (e.data && e.data.size > 0) chunks.push(e.data); };

    const enableOutro = document.getElementById('enable_outro_chk').checked;
    const photoSec = parseFloat(document.getElementById('photo_duration_select').value) || 3;
    const transition = document.getElementById('transition_select').value;
    const totalSteps = montageClips.length + (enableOutro ? 1 : 0);

    recorder.start(250);

    for (let i = 0; i < montageClips.length; i++) {
        const clip = montageClips[i];
        const basePct = Math.round((i / totalSteps) * 100);
        progLabel.innerHTML = `<i class="fa-solid fa-spinner fa-spin ml-1"></i> معالجة اللقطة ${i + 1} من ${totalSteps}...`;
        progPct.innerText = basePct + '%';
        progBar.style.width = basePct + '%';

        if (clip.type === 'image') {
            await renderImageClip(ctx, w, h, clip.url, photoSec, transition, fps);
        } else {
            await renderVideoClip(ctx, w, h, clip.url);
        }
    }

    if (enableOutro) {
        const basePct = Math.round((montageClips.length / totalSteps) * 100);
        progLabel.innerHTML = `<i class="fa-solid fa-spinner fa-spin ml-1"></i> إضافة خاتمة شعار الشركة (Outro)...`;
        progPct.innerText = basePct + '%';
        progBar.style.width = basePct + '%';
        await renderOutroClip(ctx, w, h, 3, fps);
    }

    if (audioEl) { audioEl.pause(); }
    if (audioCtx) { audioCtx.close().catch(() => {}); }

    recorder.onstop = () => {
        renderedVideoBlob = new Blob(chunks, { type: chosenMime.split(';')[0] });
        const outUrl = URL.createObjectURL(renderedVideoBlob);
        const vidEl = document.getElementById('montage_output_video');
        vidEl.src = outUrl;

        const dlBtn = document.getElementById('btn_download_montage');
        dlBtn.href = outUrl;
        dlBtn.download = 'montage_' + Date.now() + (chosenMime.includes('mp4') ? '.mp4' : '.webm');

        document.getElementById('output_video_size').innerText = (renderedVideoBlob.size / (1024 * 1024)).toFixed(2) + ' MB';
        document.getElementById('montage_output_box').classList.remove('hidden');

        progPct.innerText = '100%';
        progBar.style.width = '100%';
        progWrap.classList.add('hidden');
        statusBadge.innerText = 'اكتمل الإخراج';
        statusBadge.className = 'text-[10px] font-bold px-2.5 py-0.5 rounded-full bg-emerald-100 text-emerald-700';

        btn.disabled = false;
        btn.classList.remove('opacity-60', 'cursor-not-allowed');
    };

    recorder.stop();
}

function renderImageClip(ctx, w, h, url, durationSec, transition, fps) {
    return new Promise((resolve) => {
        const img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = () => {
            const totalFrames = Math.round(durationSec * fps);
            let frame = 0;
            const interval = setInterval(() => {
                const progress = frame / totalFrames;
                ctx.fillStyle = '#000000';
                ctx.fillRect(0, 0, w, h);

                const zoom = transition === 'zoom' ? (1.0 + progress * 0.08) : 1.0;
                const alpha = transition === 'fade' ? Math.min(1, progress * 4) : 1.0;
                drawCoverMedia(ctx, img, w, h, zoom, alpha);

                if (selectedFrameImg) {
                    ctx.drawImage(selectedFrameImg, 0, 0, w, h);
                }

                frame++;
                if (frame >= totalFrames) {
                    clearInterval(interval);
                    resolve();
                }
            }, 1000 / fps);
        };
        img.onerror = () => resolve();
        img.src = url;
    });
}

function renderVideoClip(ctx, w, h, url) {
    return new Promise((resolve) => {
        const vid = document.createElement('video');
        vid.crossOrigin = 'anonymous';
        vid.muted = true;
        vid.playsInline = true;
        vid.src = url;

        vid.onloadeddata = () => {
            vid.play().catch(() => resolve());
            function step() {
                if (vid.ended || vid.paused) {
                    resolve();
                    return;
                }
                ctx.fillStyle = '#000000';
                ctx.fillRect(0, 0, w, h);
                drawCoverMedia(ctx, vid, w, h);
                if (selectedFrameImg) {
                    ctx.drawImage(selectedFrameImg, 0, 0, w, h);
                }
                requestAnimationFrame(step);
            }
            requestAnimationFrame(step);
        };
        vid.onended = () => resolve();
        vid.onerror = () => resolve();
    });
}

function renderOutroClip(ctx, w, h, durationSec, fps) {
    return new Promise((resolve) => {
        const totalFrames = Math.round(durationSec * fps);
        let frame = 0;
        const interval = setInterval(() => {
            drawOutroScene(ctx, w, h, frame / totalFrames);
            frame++;
            if (frame >= totalFrames) {
                clearInterval(interval);
                resolve();
            }
        }, 1000 / fps);
    });
}

function sendMontageToSocial() {
    if (!renderedVideoBlob) return;
    const btn = document.getElementById('btn_send_to_social');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin ml-1"></i> جاري التجهيز...';

    const ext = renderedVideoBlob.type.includes('mp4') ? 'mp4' : 'webm';
    const fd = new FormData();
    fd.append('action', 'upload_media');
    fd.append('csrf_token', csrfToken);
    fd.append('file', renderedVideoBlob, 'montage_' + Date.now() + '.' + ext);

    fetch('social_ajax.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success && data.media_id) {
                window.location.href = 'social_post_create.php?media_id=' + data.media_id;
            } else {
                alert(data.error || 'فشل رفع الفيديو للسوشيال ميديا');
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-share-nodes ml-1"></i> نشر في السوشيال ميديا';
            }
        });
}

document.addEventListener('DOMContentLoaded', () => {
    renderTimeline();
    drawPreviewFrame();
});
</script>

<?php include_once __DIR__ . '/includes/footer.php'; ?>
