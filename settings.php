<?php
require_once __DIR__ . '/config/app.php';
requireLogin();

$currentUser = getCurrentUser();
$activeCompanyId = (int)$currentUser['active_company_id'];

if (empty($activeCompanyId)) {
    header('Location: companies.php');
    exit;
}

// Only company admins or super admins can access settings
if (!isCompanyAdmin() && !isSuperAdmin()) {
    setFlash('error', 'ليس لديك صلاحية للوصول لإعدادات الشركة.');
    header('Location: dashboard.php');
    exit;
}

$db = getDBConnection();

// Fetch fresh company data
$stmt = $db->prepare("SELECT * FROM companies WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $activeCompanyId]);
$company = $stmt->fetch();

if (!$company) {
    die('الشركة غير موجودة.');
}

// Handle POST: Update Company Settings
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('خطأ في التحقق من صحة الطلب');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $currency = trim($_POST['currency'] ?? 'ج.م');
        $removeLogo = isset($_POST['remove_logo']) && $_POST['remove_logo'] === '1';

        if (empty($name)) {
            setFlash('error', 'يرجى إدخال اسم المقر / الشركة.');
        } else {
            $logoPath = $company['logo'];

            if ($removeLogo) {
                $logoPath = null;
            }

            // Upload new logo if provided
            if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                $fileTmp = $_FILES['logo']['tmp_name'];
                $fileName = $_FILES['logo']['name'];
                $fileSize = $_FILES['logo']['size'];
                $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

                $allowedExts = ['png', 'jpg', 'jpeg', 'webp', 'svg'];
                if (!in_array($ext, $allowedExts)) {
                    setFlash('error', 'صيغة الصورة غير مدعومة. يرجى رفع ملف صورة (PNG, JPG, WEBP, SVG).');
                } elseif ($fileSize > 5 * 1024 * 1024) {
                    setFlash('error', 'حجم الصورة كبير جداً، الحد الأقصى 5 ميجابايت.');
                } else {
                    $newFileName = 'logo_' . $activeCompanyId . '_' . time() . '.' . $ext;
                    $uploadDir = APP_PATH . '/assets/uploads/logos/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }
                    $targetPath = $uploadDir . $newFileName;

                    if (move_uploaded_file($fileTmp, $targetPath)) {
                        $logoPath = 'assets/uploads/logos/' . $newFileName;
                    } else {
                        setFlash('error', 'فشل حفظ ملف الشعار على الخادم.');
                    }
                }
            }

            $upd = $db->prepare("UPDATE companies SET name = :name, phone = :phone, currency = :curr, logo = :logo WHERE id = :id");
            $upd->execute([
                ':name' => $name,
                ':phone' => $phone,
                ':curr' => $currency,
                ':logo' => $logoPath,
                ':id' => $activeCompanyId
            ]);

            // Refresh user session cache
            getCurrentUser(true);

            setFlash('success', 'تم حفظ وتحديث إعدادات واسم المقر والشعار بنجاح!');
            header('Location: settings.php');
            exit;
        }
    }
}

$pageTitle = 'إعدادات الشركة والمقر';
require_once __DIR__ . '/includes/header.php';
?>

<div class="space-y-6 max-w-4xl mx-auto">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-slate-900">إعدادات الشركة والمقر</h1>
            <p class="text-xs text-slate-500 mt-1">
                تعديل اسم الشركة، رقم الهاتف، واللوجو الذي يظهر في أعلى إيصالات الاستلام والفواتير المطبوعة والإلكترونية
            </p>
        </div>
        <div class="flex items-center space-x-2 space-x-reverse">
            <a href="receipts.php" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold px-3.5 py-2 rounded-xl text-xs transition flex items-center">
                <i class="fa-solid fa-receipt ml-1.5 text-slate-500"></i> سجل الإيصالات
            </a>
        </div>
    </div>

    <!-- Live Receipt Header Preview Card -->
    <div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm space-y-3">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100">
            <div class="flex items-center space-x-2 space-x-reverse">
                <i class="fa-solid fa-eye text-brand-600"></i>
                <h3 class="text-xs font-black uppercase text-slate-700">معاينة مباشرة: رأس الفاتورة والإيصال المطبوع (A4)</h3>
            </div>
            <span class="text-[11px] font-bold text-slate-400">هكذا سيظهر في أعلى وصولات الاستلام</span>
        </div>

        <div class="bg-slate-50 rounded-2xl p-4 border border-slate-300 flex items-center justify-between">
            <!-- Company Info & Large Logo Preview -->
            <div class="flex items-center space-x-3.5 space-x-reverse">
                <div class="h-16 w-24 max-h-16 rounded-xl bg-white border border-slate-300 flex items-center justify-center p-0.5 overflow-hidden shrink-0 shadow-2xs">
                    <?php if (!empty($company['logo']) && file_exists(APP_PATH . '/' . $company['logo'])): ?>
                        <img src="<?= htmlspecialchars($company['logo']) ?>" alt="شعار المقر" class="max-w-full max-h-full w-auto h-auto object-contain">
                    <?php else: ?>
                        <i class="fa-solid fa-building text-3xl text-slate-300"></i>
                    <?php endif; ?>
                </div>
                <div>
                    <h2 class="font-black text-slate-900 text-lg sm:text-xl leading-tight"><?= htmlspecialchars($company['name']) ?></h2>
                    <p class="text-xs text-slate-600 font-bold mt-0.5">
                        <?= !empty($company['phone']) ? 'هاتف: ' . htmlspecialchars($company['phone']) : 'هاتف المقر: غير محدد' ?>
                    </p>
                </div>
            </div>

            <!-- Receipt Badge Preview -->
            <div class="text-left hidden sm:block">
                <div class="inline-block px-3 py-1 bg-slate-900 text-white font-black text-xs rounded-lg tracking-wider shadow-xs">
                    إيصال استلام نقدية
                </div>
                <p class="text-[9px] font-bold text-slate-400 mt-0.5 font-mono">CASH RECEIPT VOUCHER</p>
            </div>
        </div>
    </div>

    <!-- Main Settings Form -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-xs">
        <form action="settings.php" method="POST" enctype="multipart/form-data" class="space-y-6">
            <?= getCSRFInput() ?>
            <input type="hidden" name="action" value="update_profile">

            <!-- Company Name & Phone -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">
                        اسم الشركة / المقر الرسمي <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="name" value="<?= htmlspecialchars($company['name']) ?>" required placeholder="مثال: Vision Trading أو شركة الأمل للتجارة..." class="w-full text-sm font-bold bg-slate-50 border border-slate-300 rounded-xl px-4 py-2.5 text-slate-900 focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                    <p class="text-[11px] text-slate-400 mt-1">هذا الاسم يطبع في أعلى وصولات الاستلام وفواتير العملاء.</p>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">
                        رقم هاتف المقر والتواصل
                    </label>
                    <input type="text" name="phone" value="<?= htmlspecialchars($company['phone'] ?? '') ?>" placeholder="010xxxxxxxx" class="w-full text-sm font-mono bg-slate-50 border border-slate-300 rounded-xl px-4 py-2.5 text-slate-900 focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                    <p class="text-[11px] text-slate-400 mt-1">يظهر تحت اسم الشركة في الإيصالات لتمكين العملاء من التواصل.</p>
                </div>
            </div>

            <!-- Currency & Code -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">
                        العملة الافتراضية للنظام
                    </label>
                    <select name="currency" class="w-full text-sm font-bold bg-slate-50 border border-slate-300 rounded-xl px-3 py-2.5 text-slate-800 focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                        <option value="ج.م" <?= ($company['currency'] ?? 'ج.م') === 'ج.م' ? 'selected' : '' ?>>ج.م (جنيه مصري)</option>
                        <option value="$" <?= ($company['currency'] ?? 'ج.م') === '$' ? 'selected' : '' ?>>$ (دولار أمريكي)</option>
                        <option value="ر.س" <?= ($company['currency'] ?? 'ج.م') === 'ر.س' ? 'selected' : '' ?>>ر.س (ريال سعودي)</option>
                        <option value="€" <?= ($company['currency'] ?? 'ج.م') === '€' ? 'selected' : '' ?>>€ (يورو)</option>
                        <option value="د.إ" <?= ($company['currency'] ?? 'ج.م') === 'د.إ' ? 'selected' : '' ?>>د.إ (درهم إماراتي)</option>
                        <option value="د.ك" <?= ($company['currency'] ?? 'ج.م') === 'د.ك' ? 'selected' : '' ?>>د.ك (دينار كويتي)</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">
                        كود الشركة بالمنصة
                    </label>
                    <input type="text" value="<?= htmlspecialchars($company['code']) ?>" disabled class="w-full text-sm font-mono bg-slate-100 border border-slate-200 rounded-xl px-4 py-2.5 text-slate-500 cursor-not-allowed">
                    <p class="text-[11px] text-slate-400 mt-1">كود فريد مخصص لتعريف الشركة.</p>
                </div>
            </div>

            <!-- Logo Section -->
            <div class="pt-4 border-t border-slate-100">
                <label class="block text-xs font-bold text-slate-700 mb-2">
                    شعار المقر (Logo) بحجم كبير وواضح
                </label>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 items-center">
                    <!-- Current Logo Display -->
                    <div class="bg-slate-50 border-2 border-slate-200 rounded-2xl p-4 text-center">
                        <span class="text-[11px] font-bold text-slate-400 block mb-2">الشعار المحفوظ حالياً</span>
                        <div class="h-20 w-32 mx-auto bg-white border border-slate-200 rounded-xl flex items-center justify-center p-1 shadow-xs">
                            <?php if (!empty($company['logo']) && file_exists(APP_PATH . '/' . $company['logo'])): ?>
                                <img src="<?= htmlspecialchars($company['logo']) ?>" alt="شعار الشركة" class="max-w-full max-h-full object-contain">
                            <?php else: ?>
                                <i class="fa-solid fa-image text-3xl text-slate-300"></i>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($company['logo'])): ?>
                            <div class="mt-3 flex items-center justify-center space-x-1.5 space-x-reverse text-xs">
                                <input type="checkbox" id="remove_logo" name="remove_logo" value="1" class="rounded text-rose-600 focus:ring-rose-500 w-4 h-4">
                                <label for="remove_logo" class="text-rose-600 font-bold cursor-pointer">حذف هذا الشعار</label>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Upload New File -->
                    <div class="sm:col-span-2 border-2 border-dashed border-slate-300 rounded-2xl p-5 bg-slate-50 text-center">
                        <i class="fa-solid fa-cloud-arrow-up text-3xl text-slate-400 mb-2"></i>
                        <p class="text-xs font-bold text-slate-700 mb-1">رفع شعار جديد أو استبدال الشعار الحالي</p>
                        <p class="text-[11px] text-slate-400 mb-3">الصيغ المدعومة: PNG, JPG, JPEG, WEBP, SVG بحجم حتى 5 ميجابايت</p>
                        <input type="file" name="logo" accept="image/*" class="text-xs text-slate-500 mx-auto block">
                    </div>
                </div>
            </div>

            <!-- Submit Button -->
            <div class="pt-4 border-t border-slate-100 flex items-center justify-end space-x-3 space-x-reverse">
                <a href="receipts.php" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold py-2.5 px-5 rounded-xl transition text-xs">
                    إلغاء
                </a>
                <button type="submit" class="bg-brand-600 hover:bg-brand-700 text-white font-bold py-2.5 px-6 rounded-xl shadow-md transition text-xs flex items-center">
                    <i class="fa-solid fa-floppy-disk ml-2"></i>
                    حفظ وتحديث بيانات المقر والشعار
                </button>
            </div>
        </form>
    </div>
</div>

<?php include_once __DIR__ . '/includes/footer.php'; ?>