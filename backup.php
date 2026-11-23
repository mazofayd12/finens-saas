<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/backup_functions.php';
requireLogin();

$currentUser = getCurrentUser();
$isSuper = isSuperAdmin();
$activeCompanyId = (int)$currentUser['active_company_id'];
$db = getDBConnection();

$pageTitle = 'النسخ الاحتياطي واستعادة البيانات';

// Fetch all companies for Super Admin selector
$allCompanies = [];
if ($isSuper) {
    $cStmt = $db->query("SELECT id, name, code, currency FROM companies WHERE is_active = 1 ORDER BY id ASC");
    $allCompanies = $cStmt->fetchAll();
}

// Determine target company for action
$selectedCompanyId = $activeCompanyId;
if ($isSuper && isset($_REQUEST['target_company_id'])) {
    $reqCid = (int)$_REQUEST['target_company_id'];
    if ($reqCid > 0) {
        $selectedCompanyId = $reqCid;
    }
}

// Fetch target company details
$tStmt = $db->prepare("SELECT * FROM companies WHERE id = :id LIMIT 1");
$tStmt->execute([':id' => $selectedCompanyId]);
$targetCompany = $tStmt->fetch();

if (!$targetCompany && !$isSuper) {
    die('الشركة غير موجودة أو غير محددة.');
}

// Handle GET: Direct Download / Export
if (isset($_GET['action']) && $_GET['action'] === 'export') {
    $exportCid = (int)($_GET['company_id'] ?? $selectedCompanyId);

    // Permission check: only super admin can export other companies
    if (!$isSuper && $exportCid !== $activeCompanyId) {
        setFlash('error', 'ليس لديك صلاحية لتصدير بيانات شركة أخرى.');
        header('Location: backup.php');
        exit;
    }

    try {
        downloadCompanyBackup($exportCid);
        exit;
    } catch (Exception $e) {
        setFlash('error', 'حدث خطأ أثناء تصدير النسخة الاحتياطية: ' . $e->getMessage());
        header('Location: backup.php');
        exit;
    }
}

// Handle POST: Import / Restore
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'restore') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('خطأ في التحقق من صحة الطلب (CSRF)');
    }

    $restoreCid = (int)($_POST['target_company_id'] ?? $selectedCompanyId);
    $restoreProfile = isset($_POST['restore_profile']) && $_POST['restore_profile'] === '1';

    // Permission check
    if (!$isSuper && $restoreCid !== $activeCompanyId) {
        setFlash('error', 'ليس لديك صلاحية لاستعادة بيانات شركة أخرى.');
        header('Location: backup.php');
        exit;
    }

    if (empty($_FILES['backup_file']['tmp_name']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
        setFlash('error', 'يرجى اختيار ملف نسخة احتياطية (.json) صالح للرفع.');
    } else {
        $uploadedJson = file_get_contents($_FILES['backup_file']['tmp_name']);
        $backupData = json_decode($uploadedJson, true);

        if (!$backupData || !is_array($backupData)) {
            setFlash('error', 'الملف المرفوع تالف أو ليس بصيغة JSON صالحة.');
        } else {
            try {
                $res = restoreCompanyBackup($restoreCid, $backupData, $restoreProfile);
                setFlash('success', "تمت استعادة النسخة الاحتياطية بنجاح لشركة ({$res['restored_company']})! تم استعادة {$res['counts']['expenses']} سند صرف، {$res['counts']['receipts']} إيصال استلام، {$res['counts']['invoices']} فاتورة، و {$res['counts']['safe_transactions']} حركة خزنة.");
            } catch (Exception $e) {
                setFlash('error', 'فشلت الاستعادة: ' . $e->getMessage());
            }
        }
    }

    header("Location: backup.php" . ($isSuper ? "?target_company_id={$restoreCid}" : ""));
    exit;
}

// Fetch preview counts for the target company
$counts = [];
if ($targetCompany) {
    $cid = (int)$targetCompany['id'];
    $counts['safes'] = (int)$db->query("SELECT COUNT(*) FROM safes WHERE company_id = {$cid}")->fetchColumn();
    $counts['transactions'] = (int)$db->query("SELECT COUNT(*) FROM safe_transactions WHERE company_id = {$cid}")->fetchColumn();
    $counts['receipts'] = (int)$db->query("SELECT COUNT(*) FROM receipts WHERE company_id = {$cid}")->fetchColumn();
    $counts['expenses'] = (int)$db->query("SELECT COUNT(*) FROM expenses WHERE company_id = {$cid}")->fetchColumn();
    $counts['invoices'] = (int)$db->query("SELECT COUNT(*) FROM invoices WHERE company_id = {$cid}")->fetchColumn();
    $counts['projects'] = (int)$db->query("SELECT COUNT(*) FROM projects WHERE company_id = {$cid}")->fetchColumn();
    $counts['users'] = (int)$db->query("SELECT COUNT(*) FROM users WHERE company_id = {$cid}")->fetchColumn();
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<div class="max-w-5xl mx-auto space-y-6 pb-12">

    <!-- Hero Banner -->
    <div class="bg-gradient-to-l from-slate-900 via-indigo-950 to-slate-900 rounded-2xl p-5 sm:p-6 text-white shadow-xl relative overflow-hidden">
        <div class="absolute -top-10 -left-10 w-44 h-44 bg-indigo-500/10 rounded-full blur-2xl pointer-events-none"></div>
        <div class="absolute -bottom-10 -right-10 w-44 h-44 bg-emerald-500/10 rounded-full blur-2xl pointer-events-none"></div>

        <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <div class="flex items-center space-x-2 space-x-reverse mb-1.5">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-black bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">
                        <i class="fa-solid fa-cloud-arrow-down ml-1"></i> النسخ الاحتياطي الشامل
                    </span>
                    <span class="text-xs text-slate-400">Backup & Restore</span>
                </div>
                <h1 class="text-xl sm:text-2xl font-black tracking-tight text-white">إدارة النسخ الاحتياطي واستعادة بيانات الشركة</h1>
                <p class="text-xs text-slate-300 mt-1 max-w-2xl leading-relaxed">
                    تصدير نسخة احتياطية كاملة ومؤمّنة تشمل كافة السجلات المالية، سندات الصرف، إيصالات الاستلام، الفواتير، الحسابات، والشعار، مع إمكانية استعادتها في أي وقت بنقرة واحدة.
                </p>
            </div>

            <!-- Target Company Badge / Selector -->
            <?php if ($isSuper && !empty($allCompanies)): ?>
                <div class="bg-white/10 backdrop-blur-md p-3 rounded-xl border border-white/15 text-xs shrink-0">
                    <label class="block text-[11px] font-bold text-slate-300 mb-1">اختر الشركة المراد إدارتها (سوبر أدمن):</label>
                    <form method="GET" action="backup.php" class="flex items-center space-x-2 space-x-reverse">
                        <select name="target_company_id" onchange="this.form.submit()" class="bg-slate-900 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-white font-bold focus:outline-none">
                            <?php foreach ($allCompanies as $compItem): ?>
                                <option value="<?= $compItem['id'] ?>" <?= $compItem['id'] == $selectedCompanyId ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($compItem['name']) ?> (<?= htmlspecialchars($compItem['currency']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
            <?php else: ?>
                <div class="flex items-center space-x-2.5 space-x-reverse bg-white/10 backdrop-blur-md px-3.5 py-2.5 rounded-xl border border-white/15 shrink-0">
                    <div class="w-10 h-10 rounded-lg bg-white flex items-center justify-center p-1 overflow-hidden shrink-0">
                        <?php if (!empty($targetCompany['logo']) && file_exists(APP_PATH . '/' . $targetCompany['logo'])): ?>
                            <img src="<?= htmlspecialchars($targetCompany['logo']) ?>" alt="شعار" class="max-w-full max-h-full object-contain">
                        <?php else: ?>
                            <i class="fa-solid fa-building text-slate-500 text-lg"></i>
                        <?php endif; ?>
                    </div>
                    <div>
                        <strong class="text-xs font-black block text-white"><?= htmlspecialchars($targetCompany['name'] ?? 'الشركة الحالية') ?></strong>
                        <span class="text-[10px] text-slate-300">العملة: <?= htmlspecialchars($targetCompany['currency'] ?? 'ج.م') ?></span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Active Company Data Summary Cards -->
    <?php if ($targetCompany): ?>
        <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs space-y-3">
            <div class="flex items-center justify-between border-b border-slate-100 pb-2.5">
                <span class="text-xs font-black text-slate-900 flex items-center">
                    <i class="fa-solid fa-database ml-1.5 text-indigo-600"></i>
                    محتويات بيانات: <span class="text-brand-600 mr-1"><?= htmlspecialchars($targetCompany['name']) ?></span>
                </span>
                <span class="text-[11px] text-slate-400">ستشمل النسخة الاحتياطية كل ما يلي</span>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 text-center">
                <div class="bg-slate-50 rounded-xl p-3 border border-slate-200">
                    <span class="text-lg font-black text-slate-900 font-mono block"><?= $counts['receipts'] ?></span>
                    <span class="text-[11px] font-bold text-slate-500">إيصال استلام</span>
                </div>
                <div class="bg-slate-50 rounded-xl p-3 border border-slate-200">
                    <span class="text-lg font-black text-rose-700 font-mono block"><?= $counts['expenses'] ?></span>
                    <span class="text-[11px] font-bold text-slate-500">سند صرف ومصروف</span>
                </div>
                <div class="bg-slate-50 rounded-xl p-3 border border-slate-200">
                    <span class="text-lg font-black text-amber-700 font-mono block"><?= $counts['invoices'] ?></span>
                    <span class="text-[11px] font-bold text-slate-500">فاتورة ضريبية</span>
                </div>
                <div class="bg-slate-50 rounded-xl p-3 border border-slate-200">
                    <span class="text-lg font-black text-emerald-700 font-mono block"><?= $counts['transactions'] ?></span>
                    <span class="text-[11px] font-bold text-slate-500">حركة خزنة</span>
                </div>
                <div class="bg-slate-50 rounded-xl p-3 border border-slate-200">
                    <span class="text-lg font-black text-indigo-700 font-mono block"><?= $counts['safes'] ?></span>
                    <span class="text-[11px] font-bold text-slate-500">خزنة معتمدة</span>
                </div>
                <div class="bg-slate-50 rounded-xl p-3 border border-slate-200">
                    <span class="text-lg font-black text-slate-700 font-mono block"><?= $counts['users'] ?></span>
                    <span class="text-[11px] font-bold text-slate-500">مستخدمين وصلاحيات</span>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Action Cards Grid (Export & Import) -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

        <!-- 1. EXPORT BACKUP CARD -->
        <div class="bg-white rounded-2xl p-6 border border-slate-200/80 shadow-xs flex flex-col justify-between space-y-4">
            <div>
                <div class="flex items-center space-x-3 space-x-reverse mb-3">
                    <div class="w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-xl shadow-xs">
                        <i class="fa-solid fa-file-export"></i>
                    </div>
                    <div>
                        <h2 class="text-base font-black text-slate-900">تحميل نسخة احتياطية (Export)</h2>
                        <span class="text-xs text-slate-400">تنزيل ملف .json مؤمّن على جهازك</span>
                    </div>
                </div>

                <p class="text-xs text-slate-600 leading-relaxed">
                    يقوم هذا الإجراء باستخراج كافة البيانات المدخلة في حساب الشركة (الإيصالات، المصروفات، سندات الصرف، الفواتير الضريبية، حركات الخزينة، الشعار، وإعدادات العنوان الوطني) وحفظها في ملف واحد مشفّر ومنظم.
                </p>

                <div class="mt-4 p-3 bg-emerald-50/70 border border-emerald-200 rounded-xl text-xs text-emerald-900 space-y-1">
                    <div class="flex items-center font-bold">
                        <i class="fa-solid fa-check-circle ml-1.5 text-emerald-600"></i>
                        يشمل الشعار المعتمد بدقة عالية (Embedded Base64)
                    </div>
                    <div class="flex items-center font-bold">
                        <i class="fa-solid fa-check-circle ml-1.5 text-emerald-600"></i>
                        يحافظ على العلاقات المحاسبية وتسلسل السندات
                    </div>
                </div>
            </div>

            <div class="pt-3 border-t border-slate-100">
                <a href="backup.php?action=export&company_id=<?= $selectedCompanyId ?>" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-black py-3 px-4 rounded-xl shadow-md transition text-xs flex items-center justify-center">
                    <i class="fa-solid fa-download ml-2 text-sm"></i>
                    تحميل النسخة الاحتياطية للشركة الآن (.json)
                </a>
            </div>
        </div>

        <!-- 2. IMPORT / RESTORE BACKUP CARD -->
        <div class="bg-white rounded-2xl p-6 border border-slate-200/80 shadow-xs flex flex-col justify-between space-y-4">
            <div>
                <div class="flex items-center space-x-3 space-x-reverse mb-3">
                    <div class="w-12 h-12 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-xl shadow-xs">
                        <i class="fa-solid fa-file-import"></i>
                    </div>
                    <div>
                        <h2 class="text-base font-black text-slate-900">استعادة نسخة احتياطية (Import / Restore)</h2>
                        <span class="text-xs text-slate-400">رفع وتطبيق ملف النسخة الاحتياطية</span>
                    </div>
                </div>

                <p class="text-xs text-slate-600 leading-relaxed">
                    يمكنك رفع ملف النسخة الاحتياطية الذي قمت بتحميله سابقاً لاستعادة كافة البيانات، الحسابات، والشعار.
                </p>

                <div class="mt-3 p-3 bg-amber-50 border border-amber-200 rounded-xl text-xs text-amber-900">
                    <span class="font-bold flex items-center mb-1">
                        <i class="fa-solid fa-triangle-exclamation ml-1 text-amber-600"></i>
                        تنبيه هام قبل الاستعادة:
                    </span>
                    <span class="text-[11px] leading-tight block">
                        الاستعادة ستطبق البيانات الموجودة في الملف على الشركة المحددة (<strong class="text-slate-900"><?= htmlspecialchars($targetCompany['name'] ?? '') ?></strong>) داخل معاملة آمنة تماماً تضمن عدم تعارض الحسابات أو التأثير على أي شركة أخرى.
                    </span>
                </div>
            </div>

            <form method="POST" action="backup.php" enctype="multipart/form-data" onsubmit="return confirm('تأكيد: هل أنت متأكد من رغبتك في استعادة وتطبيق هذه النسخة الاحتياطية الآن؟');" class="pt-3 border-t border-slate-100 space-y-3">
                <?= getCSRFInput() ?>
                <input type="hidden" name="action" value="restore">
                <input type="hidden" name="target_company_id" value="<?= $selectedCompanyId ?>">

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">حدد ملف النسخة الاحتياطية (.json):</label>
                    <input type="file" name="backup_file" accept=".json,application/json" required class="w-full text-xs text-slate-600 file:mr-0 file:ml-3 file:py-2 file:px-3 file:rounded-xl file:border-0 file:text-xs file:font-black file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 cursor-pointer">
                </div>

                <div class="flex items-center space-x-2 space-x-reverse pt-1">
                    <input type="checkbox" id="restore_profile" name="restore_profile" value="1" checked class="w-4 h-4 rounded text-indigo-600 focus:ring-indigo-500 border-slate-300">
                    <label for="restore_profile" class="text-xs font-bold text-slate-700 cursor-pointer">
                        استعادة بيانات المقر والشعار والعنوان الوطني أيضاً
                    </label>
                </div>

                <button type="submit" class="w-full bg-slate-900 hover:bg-slate-800 text-white font-black py-3 px-4 rounded-xl shadow-md transition text-xs flex items-center justify-center">
                    <i class="fa-solid fa-cloud-arrow-up ml-2 text-sm text-indigo-400"></i>
                    تطبيق واستعادة النسخة الاحتياطية الآن
                </button>
            </form>
        </div>

    </div>

    <!-- Instructions / FAQ -->
    <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs space-y-3 text-xs text-slate-600">
        <h3 class="font-black text-slate-900 flex items-center text-sm">
            <i class="fa-solid fa-circle-question ml-2 text-indigo-500"></i>
            تعليمات الأمان والحفاظ على البيانات
        </h3>
        <ul class="list-disc list-inside space-y-1.5 leading-relaxed text-slate-600 pr-2">
            <li><strong>عزل الشركات (Tenant Isolation):</strong> لا يمكن لأي شركة تصدير أو استعادة بيانات شركة أخرى، وكل نسخة احتياطية مخصصة للشركة المصدرة منها فقط.</li>
            <li><strong>أمان تام للمعاملات (Atomic Transaction):</strong> تتم عملية الاستعادة بالكامل داخل معاملة برمجية موحدة، ففي حال وجود أي خطأ أو انقطاع يتم التراجع تلقائياً دون أي فقد للبيانات.</li>
            <li><strong>تخزين الشعار الذكي:</strong> يتم تضمين ملف الشعار بالكامل داخل ملف النسخة الاحتياطية، مما يتيح نقله واستعادته بنجاح حتى لو تم الانتقال لسيرفر آخر.</li>
        </ul>
    </div>

</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
