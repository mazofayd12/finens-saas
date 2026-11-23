<?php
require_once __DIR__ . '/config/app.php';
requireLogin();

$currentUser = getCurrentUser();
$activeCompanyId = (int)($currentUser['active_company_id'] ?? 0);
if (isSuperAdmin() && !empty($_GET['company_id'])) {
    $activeCompanyId = (int)$_GET['company_id'];
}

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

requirePermission('settings');

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
    requireNotViewOnly();
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('خطأ في التحقق من صحة الطلب');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $locationUrl = trim($_POST['location_url'] ?? '');
        $currency = trim($_POST['currency'] ?? 'ج.م');
        $primaryColor = trim($_POST['primary_color'] ?? '#b91c1c');
        $dashboardColor = trim($_POST['dashboard_color'] ?? '#4f46e5');
        $taxNumber = trim($_POST['tax_number'] ?? '');
        $crNumber = trim($_POST['cr_number'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $buildingNo = trim($_POST['building_no'] ?? '');
        $streetName = trim($_POST['street_name'] ?? '');
        $district = trim($_POST['district'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $postalCode = trim($_POST['postal_code'] ?? '');
        $additionalNo = trim($_POST['additional_no'] ?? '');
        $country = trim($_POST['country'] ?? 'SA');
        $removeLogo = isset($_POST['remove_logo']) && $_POST['remove_logo'] === '1';

        // Validate hex colors
        if (!preg_match('/^#[a-fA-F0-9]{6}$/', $primaryColor)) {
            $primaryColor = '#b91c1c';
        }
        if (!preg_match('/^#[a-fA-F0-9]{6}$/', $dashboardColor)) {
            $dashboardColor = '#4f46e5';
        }

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
                    $uploadDir = APP_PATH . '/uploads/logos/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }
                    $targetPath = $uploadDir . $newFileName;

                    if (move_uploaded_file($fileTmp, $targetPath)) {
                        $logoPath = 'uploads/logos/' . $newFileName;
                    } else {
                        setFlash('error', 'فشل حفظ ملف الشعار على الخادم.');
                    }
                }
            }

            $upd = $db->prepare("
                UPDATE companies 
                SET name = :name, phone = :phone, email = :email, location_url = :loc_url,
                    currency = :curr, primary_color = :pcolor, dashboard_color = :dcolor,
                    logo = :logo, tax_number = :tax, cr_number = :cr, address = :addr,
                    building_no = :bno, street_name = :sname, district = :dist,
                    city = :city, postal_code = :pcode, additional_no = :ano, country = :country
                WHERE id = :id
            ");
            $upd->execute([
                ':name' => $name,
                ':phone' => $phone,
                ':email' => $email,
                ':loc_url' => $locationUrl,
                ':curr' => $currency,
                ':pcolor' => $primaryColor,
                ':dcolor' => $dashboardColor,
                ':logo' => $logoPath,
                ':tax' => $taxNumber,
                ':cr' => $crNumber,
                ':addr' => $address,
                ':bno' => $buildingNo,
                ':sname' => $streetName,
                ':dist' => $district,
                ':city' => $city,
                ':pcode' => $postalCode,
                ':ano' => $additionalNo,
                ':country' => $country,
                ':id' => $activeCompanyId
            ]);

            // Refresh user session cache
            getCurrentUser(true);

            setFlash('success', 'تم حفظ وتحديث إعدادات المقر والهوية والألوان بنجاح!');
            header('Location: settings.php');
            exit;
        }
    }
    elseif ($action === 'save_signature') {
        $sigData = trim($_POST['signature_data'] ?? '');
        $clearSig = isset($_POST['clear_signature']) && $_POST['clear_signature'] === '1';
        $applyToCompany = isset($_POST['apply_to_company']) && $_POST['apply_to_company'] === '1';

        $uploadDir = APP_PATH . '/uploads/signatures/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $savedSigPath = null;

        if ($clearSig) {
            $savedSigPath = null;
        } elseif (isset($_FILES['signature_file']) && $_FILES['signature_file']['error'] === UPLOAD_ERR_OK) {
            $fileTmp = $_FILES['signature_file']['tmp_name'];
            $fileName = $_FILES['signature_file']['name'];
            $fileSize = $_FILES['signature_file']['size'];
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'svg']) && $fileSize <= 5 * 1024 * 1024) {
                $newFileName = 'sig_user_' . $currentUser['id'] . '_' . time() . '.' . $ext;
                $targetPath = $uploadDir . $newFileName;
                if (move_uploaded_file($fileTmp, $targetPath)) {
                    $savedSigPath = 'uploads/signatures/' . $newFileName;
                }
            }
        } elseif (!empty($sigData) && strpos($sigData, 'data:image/png;base64,') === 0) {
            $base64 = str_replace('data:image/png;base64,', '', $sigData);
            $base64 = str_replace(' ', '+', $base64);
            $imgBinary = base64_decode($base64);
            if ($imgBinary !== false && strlen($imgBinary) > 100) {
                $newFileName = 'sig_user_' . $currentUser['id'] . '_' . time() . '.png';
                $targetPath = $uploadDir . $newFileName;
                if (file_put_contents($targetPath, $imgBinary)) {
                    $savedSigPath = 'uploads/signatures/' . $newFileName;
                }
            }
        }

        if ($clearSig || $savedSigPath !== null) {
            $updUser = $db->prepare("UPDATE users SET signature = :sig WHERE id = :uid");
            $updUser->execute([':sig' => $savedSigPath, ':uid' => $currentUser['id']]);

            if ($applyToCompany) {
                $updComp = $db->prepare("UPDATE companies SET signature = :sig WHERE id = :cid");
                $updComp->execute([':sig' => $savedSigPath, ':cid' => $activeCompanyId]);
            }

            getCurrentUser(true);
            setFlash('success', $clearSig ? 'تم حذف التوقيع بنجاح.' : 'تم حفظ واعتماد توقيعك الإلكتروني بنجاح!');
        } else {
            setFlash('error', 'يرجى رسم التوقيع أولاً أو اختيار ملف صورة صحيح.');
        }

        header('Location: settings.php');
        exit;
    }
}

$pageTitle = 'إعدادات المقر والهوية والألوان';
$currentPrimaryColor = !empty($company['primary_color']) ? $company['primary_color'] : '#b91c1c';
$currentDashboardColor = !empty($company['dashboard_color']) ? $company['dashboard_color'] : '#4f46e5';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-6 max-w-5xl mx-auto pb-12">

    <!-- Top Hero Banner -->
    <div class="bg-gradient-to-l from-slate-900 via-indigo-950 to-slate-900 rounded-2xl p-5 sm:p-6 text-white shadow-xl relative overflow-hidden">
        <div class="absolute -top-10 -left-10 w-44 h-44 bg-indigo-500/10 rounded-full blur-2xl pointer-events-none"></div>
        <div class="absolute -bottom-10 -right-10 w-44 h-44 rounded-full blur-2xl pointer-events-none" style="background-color: <?= htmlspecialchars($currentPrimaryColor) ?>22;"></div>

        <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <div class="flex items-center space-x-2 space-x-reverse mb-1.5">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-black bg-white/10 text-amber-300 border border-white/15">
                        <i class="fa-solid fa-palette ml-1"></i> إعدادات الهوية البصرية والمقر
                    </span>
                    <span class="text-xs text-slate-400">Identity & Branding Settings</span>
                </div>
                <h1 class="text-xl sm:text-2xl font-black tracking-tight text-white"><?= htmlspecialchars($company['name']) ?></h1>
                <p class="text-xs text-slate-300 mt-1 max-w-2xl leading-relaxed">
                    تحكم باسم المقر، بيانات التواصل واللوكيشن، الشعار الرسمي، وألوان الهوية لسندات الصرف والقبض والفواتير ولوحة التحكم.
                </p>
            </div>

            <div class="flex items-center space-x-2 space-x-reverse shrink-0">
                <a href="backup.php" class="bg-white/10 hover:bg-white/20 text-white font-bold px-3.5 py-2.5 rounded-xl text-xs transition border border-white/15 flex items-center shadow-xs">
                    <i class="fa-solid fa-cloud-arrow-down ml-1.5 text-emerald-400"></i> النسخ الاحتياطي
                </a>
            </div>
        </div>
    </div>

    <!-- Main Settings Form -->
    <form action="settings.php" method="POST" enctype="multipart/form-data" class="space-y-6">
        <?= getCSRFInput() ?>
        <input type="hidden" name="action" value="update_profile">

        <!-- 1. Basic Company & Contact Details (Name, Phone, Email, Location) -->
        <div class="bg-white rounded-2xl p-5 sm:p-6 border border-slate-200/80 shadow-xs space-y-4">
            <div class="flex items-center space-x-2 space-x-reverse border-b border-slate-100 pb-3">
                <span class="w-8 h-8 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-sm font-bold">
                    <i class="fa-solid fa-building"></i>
                </span>
                <div>
                    <h2 class="text-sm font-black text-slate-900">بيانات المقر والشركة الأساسية والتواصل</h2>
                    <p class="text-[11px] text-slate-400">تظهر هذه البيانات مباشرة في ترويسة سندات القبض والصرف والفواتير الضريبية المطبوعة</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">
                        اسم المقر / المنشأة <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="name" value="<?= htmlspecialchars($company['name']) ?>" required placeholder="مثال: شركة كوكب الصين للتجارة" class="w-full text-xs font-black bg-slate-50 border border-slate-300 rounded-xl px-3.5 py-2.5 text-slate-900 focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">
                        رقم هاتف المقر والتواصل
                    </label>
                    <input type="text" name="phone" value="<?= htmlspecialchars($company['phone'] ?? '') ?>" placeholder="010xxxxxxxx أو 05xxxxxxxx" class="w-full text-xs font-mono bg-slate-50 border border-slate-300 rounded-xl px-3.5 py-2.5 text-slate-900 focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">
                        البريد الإلكتروني الرسمي للمنشأة (Email)
                    </label>
                    <input type="email" name="email" value="<?= htmlspecialchars($company['email'] ?? '') ?>" placeholder="info@company.com" class="w-full text-xs font-mono bg-slate-50 border border-slate-300 rounded-xl px-3.5 py-2.5 text-slate-900 focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                    <p class="text-[11px] text-slate-400 mt-1">يطبع في ترويسة الفاتورة الضريبية وسندات الصرف والقبض.</p>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">
                        رابط اللوكيشن / خرائط جوجل أو الموقع (Location / Map URL)
                    </label>
                    <input type="text" name="location_url" value="<?= htmlspecialchars($company['location_url'] ?? '') ?>" placeholder="https://maps.google.com/... أو https://company.com" class="w-full text-xs font-mono bg-slate-50 border border-slate-300 rounded-xl px-3.5 py-2.5 text-slate-900 focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                    <p class="text-[11px] text-slate-400 mt-1">يظهر في بيانات الفاتورة الموجهة للعملاء لسهولة الوصول للمقر.</p>
                </div>
            </div>

            <!-- Currency & Code -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">
                        العملة الافتراضية للنظام
                    </label>
                    <select name="currency" class="w-full text-xs font-bold bg-slate-50 border border-slate-300 rounded-xl px-3 py-2.5 text-slate-800 focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                        <option value="ج.م" <?= ($company['currency'] ?? 'ج.م') === 'ج.م' ? 'selected' : '' ?>>ج.م (جنيه مصري)</option>
                        <option value="ر.س" <?= ($company['currency'] ?? 'ج.م') === 'ر.س' ? 'selected' : '' ?>>ر.س (ريال سعودي)</option>
                        <option value="$" <?= ($company['currency'] ?? 'ج.م') === '$' ? 'selected' : '' ?>>$ (دولار أمريكي)</option>
                        <option value="€" <?= ($company['currency'] ?? 'ج.م') === '€' ? 'selected' : '' ?>>€ (يورو)</option>
                        <option value="د.إ" <?= ($company['currency'] ?? 'ج.م') === 'د.إ' ? 'selected' : '' ?>>د.إ (درهم إماراتي)</option>
                        <option value="د.ك" <?= ($company['currency'] ?? 'ج.م') === 'د.ك' ? 'selected' : '' ?>>د.ك (دينار كويتي)</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">
                        كود الشركة بالمنصة
                    </label>
                    <input type="text" value="<?= htmlspecialchars($company['code']) ?>" disabled class="w-full text-xs font-mono bg-slate-100 border border-slate-200 rounded-xl px-3.5 py-2.5 text-slate-500 cursor-not-allowed">
                </div>
            </div>
        </div>

        <!-- 2. Visual Branding & Custom Colors (Vouchers, Invoices & Dashboard Theme) -->
        <div class="bg-white rounded-2xl p-5 sm:p-6 border border-slate-200/80 shadow-xs space-y-4">
            <div class="flex items-center space-x-2 space-x-reverse border-b border-slate-100 pb-3">
                <span class="w-8 h-8 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center text-sm font-bold">
                    <i class="fa-solid fa-paintbrush"></i>
                </span>
                <div>
                    <h2 class="text-sm font-black text-slate-900">ألوان وهوية السندات والفواتير ولوحة التحكم</h2>
                    <p class="text-[11px] text-slate-400">اختر لون هوية السندات والفواتير المطبوعة لتتوافق مع الشعار وعلامتكم التجارية</p>
                </div>
            </div>

            <!-- VOUCHER & INVOICE BRAND COLOR -->
            <div class="space-y-3 bg-slate-50/80 border border-slate-200 rounded-2xl p-4 sm:p-5">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                    <div>
                        <label class="block text-xs font-black text-slate-900">
                            لون هوية السندات والفواتير المطبوعة (سند صرف، سند قبض، والفاتورة)
                        </label>
                        <p class="text-[11px] text-slate-500">
                            يتحكم في لون الإطار العلوي والسفلي، صندوق المبالغ، والترويسة المطبوعة
                        </p>
                    </div>

                    <!-- Custom Color Input with Live Hex -->
                    <div class="flex items-center space-x-2 space-x-reverse shrink-0">
                        <input type="color" id="primary_color" name="primary_color" value="<?= htmlspecialchars($currentPrimaryColor) ?>" oninput="onColorChange(this.value)" class="w-10 h-10 rounded-xl cursor-pointer border-2 border-white shadow-xs p-0.5">
                        <input type="text" id="primary_color_hex" value="<?= htmlspecialchars($currentPrimaryColor) ?>" oninput="onHexChange(this.value)" class="w-24 text-xs font-mono font-bold bg-white border border-slate-300 rounded-xl px-2.5 py-2 text-center uppercase focus:outline-none">
                    </div>
                </div>

                <!-- Color Presets -->
                <div class="pt-1">
                    <span class="block text-[11px] font-bold text-slate-500 mb-2">أو اختر من باقات الألوان الرسمية المعتمدة:</span>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" onclick="setColor('#b91c1c', 'أحمر رسمي')" class="color-preset-btn flex items-center space-x-1.5 space-x-reverse bg-white border border-slate-200 hover:border-slate-400 px-2.5 py-1.5 rounded-xl text-xs font-bold transition">
                            <span class="w-3.5 h-3.5 rounded-full bg-[#b91c1c] shrink-0"></span>
                            <span>أحمر رسمي</span>
                        </button>
                        <button type="button" onclick="setColor('#1d4ed8', 'أزرق ملكي')" class="color-preset-btn flex items-center space-x-1.5 space-x-reverse bg-white border border-slate-200 hover:border-slate-400 px-2.5 py-1.5 rounded-xl text-xs font-bold transition">
                            <span class="w-3.5 h-3.5 rounded-full bg-[#1d4ed8] shrink-0"></span>
                            <span>أزرق ملكي</span>
                        </button>
                        <button type="button" onclick="setColor('#047857', 'أخضر زمردي')" class="color-preset-btn flex items-center space-x-1.5 space-x-reverse bg-white border border-slate-200 hover:border-slate-400 px-2.5 py-1.5 rounded-xl text-xs font-bold transition">
                            <span class="w-3.5 h-3.5 rounded-full bg-[#047857] shrink-0"></span>
                            <span>أخضر زمردي</span>
                        </button>
                        <button type="button" onclick="setColor('#0e7490', 'كحلي بترولي')" class="color-preset-btn flex items-center space-x-1.5 space-x-reverse bg-white border border-slate-200 hover:border-slate-400 px-2.5 py-1.5 rounded-xl text-xs font-bold transition">
                            <span class="w-3.5 h-3.5 rounded-full bg-[#0e7490] shrink-0"></span>
                            <span>كحلي بترولي</span>
                        </button>
                        <button type="button" onclick="setColor('#b45309', 'ذهبي كهرماني')" class="color-preset-btn flex items-center space-x-1.5 space-x-reverse bg-white border border-slate-200 hover:border-slate-400 px-2.5 py-1.5 rounded-xl text-xs font-bold transition">
                            <span class="w-3.5 h-3.5 rounded-full bg-[#b45309] shrink-0"></span>
                            <span>ذهبي كهرماني</span>
                        </button>
                        <button type="button" onclick="setColor('#0f172a', 'أسود فخم')" class="color-preset-btn flex items-center space-x-1.5 space-x-reverse bg-white border border-slate-200 hover:border-slate-400 px-2.5 py-1.5 rounded-xl text-xs font-bold transition">
                            <span class="w-3.5 h-3.5 rounded-full bg-[#0f172a] shrink-0"></span>
                            <span>أسود فخم</span>
                        </button>
                        <button type="button" onclick="setColor('#6d28d9', 'بنفسجي راقي')" class="color-preset-btn flex items-center space-x-1.5 space-x-reverse bg-white border border-slate-200 hover:border-slate-400 px-2.5 py-1.5 rounded-xl text-xs font-bold transition">
                            <span class="w-3.5 h-3.5 rounded-full bg-[#6d28d9] shrink-0"></span>
                            <span>بنفسجي راقي</span>
                        </button>
                        <button type="button" onclick="setColor('#881337', 'عنابي فاخر')" class="color-preset-btn flex items-center space-x-1.5 space-x-reverse bg-white border border-slate-200 hover:border-slate-400 px-2.5 py-1.5 rounded-xl text-xs font-bold transition">
                            <span class="w-3.5 h-3.5 rounded-full bg-[#881337] shrink-0"></span>
                            <span>عنابي فاخر</span>
                        </button>
                    </div>
                </div>

                <!-- LIVE MINI PREVIEW OF VOUCHER SLIP -->
                <div class="mt-3 pt-3 border-t border-slate-200">
                    <span class="block text-[11px] font-bold text-slate-500 mb-2 flex items-center">
                        <i class="fa-solid fa-eye ml-1 text-slate-400"></i> معاينة فورية لشكل السند باللون المحدد:
                    </span>
                    <div id="voucher_preview_card" class="bg-white rounded-xl p-3 border-2 shadow-xs transition-colors duration-200 max-w-md mx-auto" style="border-color: <?= htmlspecialchars($currentPrimaryColor) ?>;">
                        <div class="h-1.5 w-full rounded-full mb-2 transition-colors duration-200" id="preview_top_bar" style="background-color: <?= htmlspecialchars($currentPrimaryColor) ?>;"></div>
                        <div class="flex items-center justify-between text-xs pb-1.5 border-b border-slate-100">
                            <div class="border rounded px-2 py-0.5 text-[10px] font-mono font-bold transition-colors duration-200" id="preview_amt_box" style="border-color: <?= htmlspecialchars($currentPrimaryColor) ?>; color: <?= htmlspecialchars($currentPrimaryColor) ?>;">
                                <?= htmlspecialchars($company['currency'] ?? 'ج.م') ?> 5,000.00
                            </div>
                            <div class="text-center">
                                <span class="text-xs font-black block transition-colors duration-200" id="preview_title" style="color: <?= htmlspecialchars($currentPrimaryColor) ?>;">سند صرف / سند قبض</span>
                                <span class="text-[9px] text-slate-400 font-sans tracking-wider uppercase">OFFICIAL VOUCHER</span>
                            </div>
                            <span class="text-[10px] text-slate-400 font-mono">1448/05/25هـ</span>
                        </div>
                        <div class="py-1.5 space-y-1 text-[11px] text-slate-700">
                            <div class="flex items-center"><span class="font-bold text-slate-500 w-24 shrink-0">المستلم:</span><span class="flex-1 border-b border-dotted border-slate-300 font-bold"><?= htmlspecialchars($company['name']) ?></span></div>
                            <div class="flex items-center"><span class="font-bold text-slate-500 w-24 shrink-0">المبلغ كتابة:</span><span class="flex-1 border-b border-dotted border-slate-300 font-bold text-slate-900">فقط خمسة آلاف لا غير</span></div>
                        </div>
                        <div class="h-1 w-full rounded-full mt-2 transition-colors duration-200" id="preview_bottom_bar" style="background-color: <?= htmlspecialchars($currentPrimaryColor) ?>;"></div>
                    </div>
                </div>
            </div>

            <!-- DASHBOARD THEME COLOR -->
            <div class="space-y-3 bg-slate-50/80 border border-slate-200 rounded-2xl p-4 sm:p-5">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                    <div>
                        <label class="block text-xs font-black text-slate-900">
                            لون واجهة لوحة التحكم (Dashboard Theme)
                        </label>
                        <p class="text-[11px] text-slate-500">
                            اللون المميز للأزرار والبطاقات التفاعلية داخل النظام
                        </p>
                    </div>

                    <div class="flex items-center space-x-2 space-x-reverse shrink-0">
                        <input type="color" id="dashboard_color" name="dashboard_color" value="<?= htmlspecialchars($currentDashboardColor) ?>" oninput="document.getElementById('dashboard_color_hex').value = this.value" class="w-10 h-10 rounded-xl cursor-pointer border-2 border-white shadow-xs p-0.5">
                        <input type="text" id="dashboard_color_hex" value="<?= htmlspecialchars($currentDashboardColor) ?>" oninput="document.getElementById('dashboard_color').value = this.value" class="w-24 text-xs font-mono font-bold bg-white border border-slate-300 rounded-xl px-2.5 py-2 text-center uppercase focus:outline-none">
                    </div>
                </div>

                <div class="pt-1">
                    <span class="block text-[11px] font-bold text-slate-500 mb-2">ألوان مقترحة للوحة التحكم:</span>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" onclick="setDashColor('#4f46e5')" class="flex items-center space-x-1.5 space-x-reverse bg-white border border-slate-200 px-2.5 py-1.5 rounded-xl text-xs font-bold transition hover:border-slate-400">
                            <span class="w-3.5 h-3.5 rounded-full bg-[#4f46e5] shrink-0"></span><span>نيلي (Indigo)</span>
                        </button>
                        <button type="button" onclick="setDashColor('#059669')" class="flex items-center space-x-1.5 space-x-reverse bg-white border border-slate-200 px-2.5 py-1.5 rounded-xl text-xs font-bold transition hover:border-slate-400">
                            <span class="w-3.5 h-3.5 rounded-full bg-[#059669] shrink-0"></span><span>أخضر زمردي</span>
                        </button>
                        <button type="button" onclick="setDashColor('#2563eb')" class="flex items-center space-x-1.5 space-x-reverse bg-white border border-slate-200 px-2.5 py-1.5 rounded-xl text-xs font-bold transition hover:border-slate-400">
                            <span class="w-3.5 h-3.5 rounded-full bg-[#2563eb] shrink-0"></span><span>أزرق ناصع</span>
                        </button>
                        <button type="button" onclick="setDashColor('#e11d48')" class="flex items-center space-x-1.5 space-x-reverse bg-white border border-slate-200 px-2.5 py-1.5 rounded-xl text-xs font-bold transition hover:border-slate-400">
                            <span class="w-3.5 h-3.5 rounded-full bg-[#e11d48] shrink-0"></span><span>وردي أحمر</span>
                        </button>
                        <button type="button" onclick="setDashColor('#d97706')" class="flex items-center space-x-1.5 space-x-reverse bg-white border border-slate-200 px-2.5 py-1.5 rounded-xl text-xs font-bold transition hover:border-slate-400">
                            <span class="w-3.5 h-3.5 rounded-full bg-[#d97706] shrink-0"></span><span>كهرماني</span>
                        </button>
                        <button type="button" onclick="setDashColor('#334155')" class="flex items-center space-x-1.5 space-x-reverse bg-white border border-slate-200 px-2.5 py-1.5 rounded-xl text-xs font-bold transition hover:border-slate-400">
                            <span class="w-3.5 h-3.5 rounded-full bg-[#334155] shrink-0"></span><span>رمادي فحمي</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- 3. Company Logo Upload & Preview -->
        <div class="bg-white rounded-2xl p-5 sm:p-6 border border-slate-200/80 shadow-xs space-y-4">
            <div class="flex items-center space-x-2 space-x-reverse border-b border-slate-100 pb-3">
                <span class="w-8 h-8 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center text-sm font-bold">
                    <i class="fa-solid fa-image"></i>
                </span>
                <div>
                    <h2 class="text-sm font-black text-slate-900">شعار المقر والمنشأة (Logo)</h2>
                    <p class="text-[11px] text-slate-400">يظهر الشعار في ترويسة سندات الصرف والقبض والفاتورة الضريبية المبسطة</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 items-center">
                <!-- Current Logo -->
                <div class="bg-slate-50 border-2 border-slate-200 rounded-2xl p-4 text-center">
                    <span class="text-[11px] font-bold text-slate-400 block mb-2">الشعار الحالي المحفوظ</span>
                    <div class="h-24 w-36 mx-auto bg-white border border-slate-200 rounded-xl flex items-center justify-center p-1.5 shadow-xs">
                        <?php if (!empty($company['logo']) && file_exists(APP_PATH . '/' . $company['logo'])): ?>
                            <img src="<?= htmlspecialchars($company['logo']) ?>" alt="شعار الشركة" class="max-w-full max-h-full object-contain">
                        <?php else: ?>
                            <i class="fa-solid fa-building text-3xl text-slate-300"></i>
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
                    <input type="file" name="logo" accept="image/*" class="text-xs text-slate-500 mx-auto block file:mr-0 file:ml-3 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-black file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 cursor-pointer">
                </div>
            </div>
        </div>

        <!-- 4. Tax, CR & National Address (For Simplified Tax Invoices) -->
        <div class="bg-white rounded-2xl p-5 sm:p-6 border border-slate-200/80 shadow-xs space-y-4">
            <div class="flex items-center space-x-2 space-x-reverse border-b border-slate-100 pb-3">
                <span class="w-8 h-8 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-sm font-bold">
                    <i class="fa-solid fa-map-location-dot"></i>
                </span>
                <div>
                    <h2 class="text-sm font-black text-slate-900">العنوان الوطني والبيانات الضريبية للفواتير</h2>
                    <p class="text-[11px] text-slate-400">تُطبع هذه البيانات تفصيلياً في صندوق العنوان الوطني بهيئة الزكاة والضريبة بالفاتورة</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">الرقم الضريبي للمنشأة (Tax Number)</label>
                    <input type="text" name="tax_number" value="<?= htmlspecialchars($company['tax_number'] ?? '') ?>" placeholder="300xxxxxxxxxxxx" class="w-full text-xs font-mono bg-slate-50 border border-slate-300 rounded-xl px-3.5 py-2.5 text-slate-900 focus:bg-white focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">رقم السجل التجاري (CR Number)</label>
                    <input type="text" name="cr_number" value="<?= htmlspecialchars($company['cr_number'] ?? '') ?>" placeholder="1010xxxxxx" class="w-full text-xs font-mono bg-slate-50 border border-slate-300 rounded-xl px-3.5 py-2.5 text-slate-900 focus:bg-white focus:outline-none">
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">العنوان العام للمقر / المدينة</label>
                <input type="text" name="address" value="<?= htmlspecialchars($company['address'] ?? '') ?>" placeholder="مثال: الرياض - طريق الملك فهد" class="w-full text-xs bg-slate-50 border border-slate-300 rounded-xl px-3.5 py-2.5 text-slate-900 focus:bg-white focus:outline-none">
            </div>

            <!-- Detailed National Address Fields -->
            <div class="bg-slate-50 border border-slate-200 rounded-2xl p-4 space-y-2">
                <span class="text-xs font-bold text-slate-800 block">حقول العنوان الوطني الدقيقة:</span>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
                    <div>
                        <label class="block text-[11px] font-bold text-slate-600 mb-1">رقم المبنى</label>
                        <input type="text" name="building_no" value="<?= htmlspecialchars($company['building_no'] ?? '') ?>" placeholder="3488" class="w-full bg-white border border-slate-300 rounded-xl px-3 py-2 text-xs font-mono focus:outline-none">
                    </div>
                    <div class="col-span-2">
                        <label class="block text-[11px] font-bold text-slate-600 mb-1">اسم الشارع</label>
                        <input type="text" name="street_name" value="<?= htmlspecialchars($company['street_name'] ?? '') ?>" placeholder="طريق الملك خالد" class="w-full bg-white border border-slate-300 rounded-xl px-3 py-2 text-xs focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-600 mb-1">الحي</label>
                        <input type="text" name="district" value="<?= htmlspecialchars($company['district'] ?? '') ?>" placeholder="حي الهدى" class="w-full bg-white border border-slate-300 rounded-xl px-3 py-2 text-xs focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-600 mb-1">المدينة</label>
                        <input type="text" name="city" value="<?= htmlspecialchars($company['city'] ?? '') ?>" placeholder="Buraidah / الرياض" class="w-full bg-white border border-slate-300 rounded-xl px-3 py-2 text-xs focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-600 mb-1">الرمز البريدي</label>
                        <input type="text" name="postal_code" value="<?= htmlspecialchars($company['postal_code'] ?? '') ?>" placeholder="52261" class="w-full bg-white border border-slate-300 rounded-xl px-3 py-2 text-xs font-mono focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-600 mb-1">الرقم الإضافي</label>
                        <input type="text" name="additional_no" value="<?= htmlspecialchars($company['additional_no'] ?? '') ?>" placeholder="6194" class="w-full bg-white border border-slate-300 rounded-xl px-3 py-2 text-xs font-mono focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-600 mb-1">الدولة</label>
                        <input type="text" name="country" value="<?= htmlspecialchars($company['country'] ?? 'المملكة العربية السعودية') ?>" placeholder="المملكة العربية السعودية" class="w-full bg-white border border-slate-300 rounded-xl px-3 py-2 text-xs font-bold focus:outline-none">
                    </div>
                </div>
            </div>
        </div>

        <!-- 5. Submit Button -->
        <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-xs flex items-center justify-between">
            <a href="receipts.php" class="text-xs font-bold text-slate-500 hover:text-slate-800 transition">
                إلغاء
            </a>
            <button type="submit" class="bg-brand-600 hover:bg-brand-700 text-white font-black py-2.5 px-6 rounded-xl shadow-md transition text-xs flex items-center">
                <i class="fa-solid fa-floppy-disk ml-2 text-sm"></i>
                حفظ وتطبيق إعدادات المقر والألوان والشعار
            </button>
        </div>
    </form>

    <!-- 5. Electronic Signature for Voucher Issuer (توقيع مصدر السند) -->
    <?php
    $currentSig = !empty($currentUser['signature']) ? $currentUser['signature'] : (!empty($company['signature']) ? $company['signature'] : null);
    ?>
    <div class="bg-white rounded-2xl p-5 sm:p-6 border border-slate-200/80 shadow-xs space-y-5" id="issuer-signature-section">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-100 pb-3">
            <div class="flex items-center space-x-2.5 space-x-reverse">
                <span class="w-9 h-9 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-base font-bold shadow-xs">
                    <i class="fa-solid fa-signature"></i>
                </span>
                <div>
                    <h2 class="text-sm font-black text-slate-900">التوقيع الإلكتروني المعتمد لمصدر السندات (Issuer Signature)</h2>
                    <p class="text-[11px] text-slate-400">يظهر هذا التوقيع تلقائياً في خانة (توقيع المحاسب / مصدر السند) في سندات القبض والاستلام والصرف المطبوعة والمشتركة</p>
                </div>
            </div>
            <?php if (!empty($currentSig)): ?>
                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                    <i class="fa-solid fa-circle-check ml-1 text-emerald-500"></i>
                    توقيعك الإلكتروني مفعل ومعتمد
                </span>
            <?php endif; ?>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
            <!-- Active Signature Preview -->
            <div class="lg:col-span-5 bg-slate-50 border border-slate-200 rounded-2xl p-4 text-center space-y-3">
                <span class="text-xs font-bold text-slate-500 block">التوقيع الحالي المعتمد في السندات:</span>
                <div class="h-36 w-full bg-white border border-slate-200 rounded-xl flex items-center justify-center p-3 shadow-inner relative overflow-hidden">
                    <?php if (!empty($currentSig) && file_exists(APP_PATH . '/' . $currentSig)): ?>
                        <img src="<?= htmlspecialchars($currentSig) ?>?v=<?= time() ?>" alt="التوقيع المعتمد" class="max-h-full max-w-full object-contain filter contrast-125">
                    <?php else: ?>
                        <div class="text-slate-400 text-center">
                            <i class="fa-solid fa-pen-fancy text-3xl mb-1 text-slate-300 block"></i>
                            <span class="text-xs font-bold">لا يوجد توقيع مسجل حتى الآن</span>
                            <span class="text-[10px] text-slate-400 block mt-0.5">قم برسم توقيعك في اللوحة المجاورة ليتم اعتماده</span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="text-xs text-slate-600 flex items-center justify-between pt-1">
                    <span>مصدر السند: <strong><?= htmlspecialchars($currentUser['name']) ?></strong></span>
                    <?php if (!empty($currentSig)): ?>
                        <form action="settings.php" method="POST" onsubmit="return confirm('هل أنت متأكد من رغبتك في حذف التوقيع الإلكتروني؟');" class="inline">
                            <?= getCSRFInput() ?>
                            <input type="hidden" name="action" value="save_signature">
                            <input type="hidden" name="clear_signature" value="1">
                            <button type="submit" class="text-rose-600 hover:text-rose-700 font-bold text-xs underline">
                                حذف التوقيع
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Signature Pad & Upload Option -->
            <div class="lg:col-span-7 space-y-4">
                <form id="issuer-sig-form" action="settings.php" method="POST" enctype="multipart/form-data">
                    <?= getCSRFInput() ?>
                    <input type="hidden" name="action" value="save_signature">
                    <input type="hidden" name="signature_data" id="issuer_signature_data" value="">

                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <label class="text-xs font-bold text-slate-700 flex items-center">
                                <i class="fa-solid fa-pen-nib ml-1.5 text-indigo-500"></i>
                                ارسم توقيعك هنا باللمس أو القلم أو الماوس:
                            </label>
                            <button type="button" onclick="clearIssuerPad()" class="text-slate-500 hover:text-rose-600 font-bold text-xs flex items-center transition">
                                <i class="fa-solid fa-rotate-right ml-1"></i> مسح وبدء من جديد
                            </button>
                        </div>
                        <div class="relative bg-slate-50 border-2 border-dashed border-slate-300 hover:border-indigo-400 rounded-2xl overflow-hidden transition">
                            <canvas id="issuer-sig-pad" class="w-full h-36 cursor-crosshair touch-none block"></canvas>
                            <span class="absolute bottom-2 left-3 text-[10px] font-mono text-slate-300 pointer-events-none select-none">
                                لوحة التوقيع الإلكتروني المعتمد
                            </span>
                        </div>
                    </div>

                    <div class="pt-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 border-t border-slate-100">
                        <!-- Or upload image -->
                        <div>
                            <label class="text-xs font-bold text-slate-600 block mb-1">أو ارفع صورة توقيع جاهزة (PNG شفاف):</label>
                            <input type="file" name="signature_file" id="sig_file_input" accept="image/png,image/jpeg,image/webp,image/svg+xml" class="text-xs text-slate-500 file:mr-0 file:ml-2 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-bold file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200 cursor-pointer">
                        </div>

                        <!-- Apply to company checkbox -->
                        <div class="text-right">
                            <label class="inline-flex items-center space-x-1.5 space-x-reverse cursor-pointer">
                                <input type="checkbox" name="apply_to_company" value="1" checked class="rounded text-indigo-600 focus:ring-indigo-500 w-4 h-4">
                                <span class="text-xs font-bold text-slate-700">اعتماده أيضاً كتوقيع رسمي للشركة</span>
                            </label>
                        </div>
                    </div>

                    <div class="pt-3 flex justify-end">
                        <button type="button" onclick="submitIssuerSignature()" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2.5 px-6 rounded-xl shadow-md transition text-xs flex items-center">
                            <i class="fa-solid fa-check-circle ml-1.5 text-sm"></i>
                            حفظ واعتماد التوقيع الآن
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 6. Backup & Restore Card -->
    <div class="bg-white rounded-2xl p-6 border border-slate-200/80 shadow-xs space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-100 pb-3">
            <div class="flex items-center space-x-3 space-x-reverse">
                <span class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-lg shadow-xs">
                    <i class="fa-solid fa-cloud-arrow-down"></i>
                </span>
                <div>
                    <h3 class="text-sm font-black text-slate-900">النسخ الاحتياطي واستعادة بيانات الشركة (Backup & Restore)</h3>
                    <p class="text-xs text-slate-400">تصدير كامل بيانات الشركة والشعار في ملف .json أو استعادتها في أي وقت</p>
                </div>
            </div>

            <div class="flex items-center space-x-2 space-x-reverse">
                <a href="backup.php" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold px-3.5 py-2 rounded-xl text-xs transition flex items-center">
                    <i class="fa-solid fa-sliders ml-1.5"></i> إدارة متقدمة
                </a>
                <a href="backup.php?action=export&company_id=<?= $activeCompanyId ?>" class="bg-emerald-600 hover:bg-emerald-700 text-white font-black px-4 py-2 rounded-xl shadow-xs transition text-xs flex items-center">
                    <i class="fa-solid fa-download ml-1.5"></i> تحميل النسخة الاحتياطية (.json)
                </a>
            </div>
        </div>

        <p class="text-xs text-slate-600 leading-relaxed">
            يمكنك حفظ نسخة احتياطية من كافة العمليات المالية وسندات الصرف والقبض والفواتير والشعار بضغطة زر، وإعادة رفعها واستعادتها في أي وقت.
        </p>
    </div>

</div>

<script>
function onColorChange(hex) {
    document.getElementById('primary_color_hex').value = hex;
    updateVoucherColorPreview(hex);
}

function onHexChange(hex) {
    if (/^#[a-fA-F0-9]{6}$/.test(hex)) {
        document.getElementById('primary_color').value = hex;
        updateVoucherColorPreview(hex);
    }
}

function setColor(hex, label) {
    document.getElementById('primary_color').value = hex;
    document.getElementById('primary_color_hex').value = hex;
    updateVoucherColorPreview(hex);
}

function setDashColor(hex) {
    document.getElementById('dashboard_color').value = hex;
    document.getElementById('dashboard_color_hex').value = hex;
}

function updateVoucherColorPreview(color) {
    const card = document.getElementById('voucher_preview_card');
    const topBar = document.getElementById('preview_top_bar');
    const bottomBar = document.getElementById('preview_bottom_bar');
    const amtBox = document.getElementById('preview_amt_box');
    const title = document.getElementById('preview_title');

    if (card) card.style.borderColor = color;
    if (topBar) topBar.style.backgroundColor = color;
    if (bottomBar) bottomBar.style.backgroundColor = color;
    if (amtBox) {
        amtBox.style.borderColor = color;
        amtBox.style.color = color;
    }
    if (title) title.style.color = color;
}

// Issuer Signature Pad Logic
let issuerCanvas = document.getElementById('issuer-sig-pad');
let issuerCtx = null;
let isDrawingIssuer = false;
let hasDrawnIssuer = false;

function initIssuerPad() {
    if (!issuerCanvas) return;
    issuerCtx = issuerCanvas.getContext('2d');
    resizeIssuerCanvas();
    window.addEventListener('resize', resizeIssuerCanvas);

    const getPos = (e) => {
        const rect = issuerCanvas.getBoundingClientRect();
        const clientX = e.clientX || (e.touches && e.touches[0] ? e.touches[0].clientX : 0);
        const clientY = e.clientY || (e.touches && e.touches[0] ? e.touches[0].clientY : 0);
        return {
            x: clientX - rect.left,
            y: clientY - rect.top
        };
    };

    const startDraw = (e) => {
        e.preventDefault();
        isDrawingIssuer = true;
        const pos = getPos(e);
        issuerCtx.beginPath();
        issuerCtx.moveTo(pos.x, pos.y);
    };

    const draw = (e) => {
        if (!isDrawingIssuer) return;
        e.preventDefault();
        const pos = getPos(e);
        issuerCtx.lineTo(pos.x, pos.y);
        issuerCtx.stroke();
        hasDrawnIssuer = true;
    };

    const stopDraw = (e) => {
        if (!isDrawingIssuer) return;
        isDrawingIssuer = false;
        issuerCtx.closePath();
    };

    issuerCanvas.addEventListener('mousedown', startDraw);
    issuerCanvas.addEventListener('mousemove', draw);
    issuerCanvas.addEventListener('mouseup', stopDraw);
    issuerCanvas.addEventListener('mouseleave', stopDraw);

    issuerCanvas.addEventListener('touchstart', startDraw, { passive: false });
    issuerCanvas.addEventListener('touchmove', draw, { passive: false });
    issuerCanvas.addEventListener('touchend', stopDraw, { passive: false });
}

function resizeIssuerCanvas() {
    if (!issuerCanvas || !issuerCtx) return;
    const ratio = Math.max(window.devicePixelRatio || 1, 1);
    const w = issuerCanvas.offsetWidth;
    const h = issuerCanvas.offsetHeight;
    if (w === 0 || h === 0) return;

    // Save previous drawing if any
    let prevData = null;
    if (hasDrawnIssuer) {
        prevData = issuerCanvas.toDataURL();
    }

    issuerCanvas.width = w * ratio;
    issuerCanvas.height = h * ratio;
    issuerCtx.scale(ratio, ratio);
    issuerCtx.strokeStyle = '#0f172a';
    issuerCtx.lineWidth = 2.5;
    issuerCtx.lineCap = 'round';
    issuerCtx.lineJoin = 'round';

    if (prevData) {
        let img = new Image();
        img.onload = () => {
            issuerCtx.drawImage(img, 0, 0, w, h);
        };
        img.src = prevData;
    }
}

function clearIssuerPad() {
    if (!issuerCanvas || !issuerCtx) return;
    const w = issuerCanvas.offsetWidth;
    const h = issuerCanvas.offsetHeight;
    issuerCtx.clearRect(0, 0, w, h);
    hasDrawnIssuer = false;
    const fileInput = document.getElementById('sig_file_input');
    if (fileInput) fileInput.value = '';
}

function submitIssuerSignature() {
    const fileInput = document.getElementById('sig_file_input');
    const hasFile = fileInput && fileInput.files && fileInput.files.length > 0;

    if (!hasDrawnIssuer && !hasFile) {
        alert('يرجى رسم توقيعك في المربع المخصص أولاً، أو اختيار ملف صورة لتوقيعك.');
        return;
    }

    if (hasDrawnIssuer) {
        // Export high quality transparent PNG
        const dataUrl = issuerCanvas.toDataURL('image/png');
        document.getElementById('issuer_signature_data').value = dataUrl;
    }

    document.getElementById('issuer-sig-form').submit();
}

window.addEventListener('DOMContentLoaded', () => {
    initIssuerPad();
});
</script>

<?php include_once __DIR__ . '/includes/footer.php'; ?>
