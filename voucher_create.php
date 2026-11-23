<?php
require_once __DIR__ . '/config/app.php';
requireLogin();

if (!canUser('expenses') && !canUser('projects')) {
    setFlash('error', 'ليس لديك صلاحية لإصدار سندات الصرف.');
    header('Location: dashboard.php');
    exit;
}
requireNotViewOnly();

$currentUser = getCurrentUser();
$activeCompanyId = (int)($currentUser['active_company_id'] ?? 0);
$db = getDBConnection();

if (empty($activeCompanyId)) {
    $firstCo = $db->query("SELECT id FROM companies WHERE is_active = 1 ORDER BY id ASC LIMIT 1")->fetch();
    if ($firstCo) {
        $activeCompanyId = (int)$firstCo['id'];
        $_SESSION['impersonate_company_id'] = $activeCompanyId;
        $currentUser = getCurrentUser(true);
    } else {
        setFlash('error', 'يرجى إضافة أو تفعيل شركة أولاً.');
        header('Location: dashboard.php');
        exit;
    }
}

$currency = !empty($currentUser['currency']) ? $currentUser['currency'] : 'ج.م';

// Fetch company details
$compStmt = $db->prepare("SELECT * FROM companies WHERE id = :id LIMIT 1");
$compStmt->execute([':id' => $activeCompanyId]);
$company = $compStmt->fetch();

// Fetch safes
$safes = getCompanySafes($activeCompanyId);
if (empty($safes)) {
    $mainSafe = getCompanyMainSafe($activeCompanyId);
    $safes = [$mainSafe];
}

// Fetch categories
$catStmt = $db->prepare("SELECT id, name FROM expense_categories WHERE company_id = :cid AND is_active = 1 ORDER BY name ASC");
$catStmt->execute([':cid' => $activeCompanyId]);
$categories = $catStmt->fetchAll();

// Fetch projects
$projects = [];
if (hasModule('projects')) {
    $pStmt = $db->prepare("SELECT id, name, code FROM projects WHERE company_id = :cid ORDER BY name ASC");
    $pStmt->execute([':cid' => $activeCompanyId]);
    $projects = $pStmt->fetchAll();
}

$presetProjectId = !empty($_GET['project_id']) ? (int)$_GET['project_id'] : null;
$canApprove = canApproveExpenses();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'خطأ في التحقق من صحة الطلب (انتهت الجلسة). يرجى المحاولة ثانية.';
    } else {
        $beneficiaryName = trim($_POST['beneficiary_name'] ?? '');
        $beneficiaryPhone = trim($_POST['beneficiary_phone'] ?? '');
        $beneficiaryIdNumber = trim($_POST['beneficiary_id_number'] ?? '');
        $baseAmount = (float)($_POST['amount'] ?? 0);
        $voucherCurrency = trim($_POST['currency'] ?? $currency);
        $applyVat = isset($_POST['apply_vat']) && $_POST['apply_vat'] === '1';
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $newCategoryName = trim($_POST['new_category_name'] ?? '');
        $paymentMethod = trim($_POST['payment_method'] ?? 'نقدي');
        if (!in_array($paymentMethod, ['نقدي', 'كاش', 'تحويل بنكي'])) {
            $paymentMethod = 'نقدي';
        }
        $chequeNumber = trim($_POST['cheque_number'] ?? '');
        $bankName = trim($_POST['bank_name'] ?? '');
        $chequeDate = !empty($_POST['cheque_date']) ? trim($_POST['cheque_date']) : null;
        $expenseDate = !empty($_POST['expense_date']) ? trim($_POST['expense_date']) : date('Y-m-d');
        $safeIdVal = $_POST['safe_id'] ?? '';
        $projectId = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;

        // Auto-create category if new name given
        if (empty($categoryId) && !empty($newCategoryName)) {
            $createdCatId = getOrCreateExpenseCategory($activeCompanyId, $newCategoryName);
            if ($createdCatId) {
                $categoryId = $createdCatId;
            }
        }

        $requestedStatus = trim($_POST['expense_status'] ?? 'deducted');
        if (!$canApprove) {
            $expenseStatus = 'pending_approval';
        } else {
            $expenseStatus = in_array($requestedStatus, ['deducted', 'pending_approval', 'pending_receipt']) ? $requestedStatus : 'deducted';
        }

        if (empty($beneficiaryName) || $baseAmount <= 0 || empty($title)) {
            $error = 'يرجى إدخال اسم المستفيد، المبلغ، وسبب وبيان الصرف.';
        } else {
            $vatPercent = ($voucherCurrency === 'ج.م' || $currency === 'ج.م') ? 14.00 : 15.00;
            $taxRate = $applyVat ? $vatPercent : 0.00;
            $taxAmount = $applyVat ? round($baseAmount * ($vatPercent / 100), 2) : 0.00;
            $totalAmount = $baseAmount + $taxAmount;

            $db->beginTransaction();
            try {
                // Target safe
                $targetSafe = null;
                if ($safeIdVal !== 'none') {
                    $safeId = (int)$safeIdVal;
                    if ($safeId > 0) {
                        $sStmt = $db->prepare("SELECT * FROM safes WHERE id = :id AND company_id = :cid");
                        $sStmt->execute([':id' => $safeId, ':cid' => $activeCompanyId]);
                        $targetSafe = $sStmt->fetch();
                    }
                    if (!$targetSafe) {
                        $targetSafe = getCompanyMainSafe($activeCompanyId);
                    }
                }

                // Project verification
                $projectName = null;
                if ($projectId) {
                    $prStmt = $db->prepare("SELECT name FROM projects WHERE id = :pid AND company_id = :cid");
                    $prStmt->execute([':pid' => $projectId, ':cid' => $activeCompanyId]);
                    $prRow = $prStmt->fetch();
                    if ($prRow) {
                        $projectName = $prRow['name'];
                    } else {
                        $projectId = null;
                    }
                }

                // File upload (Supports multiple invoices/receipts)
                $attachmentPath = null;
                $uploadedFilesPayload = !empty($_FILES['invoice_files']['name']) ? $_FILES['invoice_files'] : (!empty($_FILES['invoice_file']['name']) ? $_FILES['invoice_file'] : null);

                $voucherNo = generateVoucherNumber($activeCompanyId);
                $signToken = bin2hex(random_bytes(16));
                $issuerSig = !empty($currentUser['signature']) ? $currentUser['signature'] : ($company['signature'] ?? null);

                $safeTxId = null;
                $approvedBy = null;
                $approvedAt = null;
                $safeIdToSave = $targetSafe ? (int)$targetSafe['id'] : (int)(getCompanyMainSafe($activeCompanyId)['id']);

                if ($targetSafe && $expenseStatus === 'deducted') {
                    $txDesc = "سند صرف رقم {$voucherNo} إلى: {$beneficiaryName} - {$title}";
                    if ($projectName) {
                        $txDesc .= " | مشروع: {$projectName}";
                    }
                    if ($applyVat) {
                        $txDesc .= " (شامل ضريبة {$vatPercent}%: " . formatMoney($taxAmount, $voucherCurrency) . ")";
                    }

                    $txRes = recordSafeTransaction(
                        $activeCompanyId,
                        $targetSafe['id'],
                        $currentUser['id'],
                        'expense',
                        $title,
                        $totalAmount,
                        $txDesc,
                        $expenseDate,
                        $voucherCurrency,
                        $projectId
                    );
                    $safeTxId = $txRes['transaction_id'];
                    $approvedBy = $currentUser['id'];
                    $approvedAt = date('Y-m-d H:i:s');
                }

                $insStmt = $db->prepare("
                    INSERT INTO expenses (
                        company_id, category_id, safe_id, safe_transaction_id, user_id, project_id,
                        amount, currency, title, description, expense_date,
                        invoice_attachment, tax_rate, tax_amount,
                        beneficiary_name, beneficiary_id_number, beneficiary_phone,
                        voucher_number, status, payment_method, cheque_number, bank_name, cheque_date,
                        approved_by, approved_at,
                        sign_token, issuer_signature, signature_status,
                        created_at
                    ) VALUES (
                        :cid, :cat_id, :safe_id, :safe_tx_id, :uid, :pid,
                        :amt, :curr, :title, :desc, :edate,
                        :attach, :trate, :tamt,
                        :bname, :bid, :bphone,
                        :vno, :status, :pmethod, :cheque_no, :bank_name, :cheque_date,
                        :app_by, :app_at,
                        :stok, :isig, 'pending_signature',
                        :now
                    )
                ");
                $insStmt->execute([
                    ':cid' => $activeCompanyId,
                    ':cat_id' => $categoryId,
                    ':safe_id' => $safeIdToSave,
                    ':safe_tx_id' => $safeTxId,
                    ':uid' => $currentUser['id'],
                    ':pid' => $projectId,
                    ':amt' => $totalAmount,
                    ':curr' => $voucherCurrency,
                    ':title' => $title,
                    ':desc' => $description,
                    ':edate' => $expenseDate,
                    ':attach' => $attachmentPath,
                    ':trate' => $taxRate,
                    ':tamt' => $taxAmount,
                    ':bname' => $beneficiaryName,
                    ':bid' => $beneficiaryIdNumber,
                    ':bphone' => $beneficiaryPhone,
                    ':vno' => $voucherNo,
                    ':status' => $expenseStatus,
                    ':pmethod' => $paymentMethod,
                    ':cheque_no' => $chequeNumber ?: null,
                    ':bank_name' => $bankName ?: null,
                    ':cheque_date' => $chequeDate,
                    ':app_by' => $approvedBy,
                    ':app_at' => $approvedAt,
                    ':stok' => $signToken,
                    ':isig' => $issuerSig,
                    ':now' => date('Y-m-d H:i:s')
                ]);
                $newExpenseId = (int)$db->lastInsertId();

                // Save multiple invoice attachments
                if (!empty($uploadedFilesPayload)) {
                    saveExpenseUploadedFiles($newExpenseId, $activeCompanyId, $uploadedFilesPayload, $projectId);
                }

                $db->commit();

                setFlash('success', "تم إصدار سند الصرف رقم ({$voucherNo}) بنجاح! رابط التوقيع الإلكتروني جاهز للإرسال للمستلم.");
                header('Location: voucher_share.php?id=' . $newExpenseId . ($projectId ? '&from_project=' . $projectId : ''));
                exit;
            } catch (Exception $e) {
                $db->rollBack();
                $error = 'حدث خطأ أثناء حفظ سند الصرف: ' . $e->getMessage();
            }
        }
    }
}

$pageTitle = 'إصدار سند صرف مالي جديد (A4)';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<div class="max-w-4xl mx-auto space-y-6 pb-16">

    <!-- Top Navigation & Header -->
    <div class="flex items-center justify-between">
        <div class="flex items-center space-x-3 space-x-reverse">
            <a href="<?= $presetProjectId ? 'project_view.php?id=' . $presetProjectId : 'project_vouchers.php' ?>" class="w-9 h-9 rounded-xl bg-white border border-slate-200 text-slate-600 hover:text-slate-900 hover:bg-slate-50 flex items-center justify-center transition shadow-2xs">
                <i class="fa-solid fa-arrow-right text-xs"></i>
            </a>
            <div>
                <h1 class="text-xl sm:text-2xl font-black text-slate-900 flex items-center">
                    <span class="w-9 h-9 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center text-sm ml-2.5 shadow-2xs">
                        <i class="fa-solid fa-file-invoice-dollar"></i>
                    </span>
                    إصدار سند صرف مالي جديد
                </h1>
                <p class="text-xs text-slate-400 mt-0.5">صفحة متكاملة لإصدار سند الصرف الرسمي واستخراج رابط التوقيع الإلكتروني للمستلم</p>
            </div>
        </div>

        <a href="project_vouchers.php" class="hidden sm:inline-flex items-center bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold px-3.5 py-2 rounded-xl text-xs transition">
            <i class="fa-solid fa-list-check ml-1.5 text-slate-500"></i>
            سجل سندات الصرف
        </a>
    </div>

    <!-- Error Alert if any -->
    <?php if (!empty($error)): ?>
        <div class="bg-rose-50 border-2 border-rose-200 text-rose-800 p-4 rounded-2xl text-xs font-bold flex items-center space-x-2 space-x-reverse shadow-xs">
            <i class="fa-solid fa-triangle-exclamation text-base text-rose-600 shrink-0"></i>
            <span><?= htmlspecialchars($error) ?></span>
        </div>
    <?php endif; ?>

    <!-- Unified Cash Voucher Type Switcher (استلام نقدية / تسليم نقدية) -->
    <div class="flex items-center justify-center">
        <div class="inline-flex p-1.5 bg-slate-200/70 rounded-2xl border border-slate-300/80 shadow-2xs">
            <a href="receipt_create.php<?= $presetProjectId ? '?project_id=' . $presetProjectId : '' ?>" class="px-5 py-2.5 rounded-xl text-xs sm:text-sm font-bold text-slate-600 hover:text-slate-900 transition flex items-center">
                <i class="fa-solid fa-arrow-down-left ml-2 text-indigo-500"></i>
                استلام نقدية (سند قبض وارد)
            </a>
            <span class="px-5 py-2.5 rounded-xl text-xs sm:text-sm font-black bg-rose-600 text-white shadow-sm flex items-center">
                <i class="fa-solid fa-arrow-up-right ml-2 text-rose-200"></i>
                تسليم نقدية (سند صرف صادر)
            </span>
        </div>
    </div>

    <form action="voucher_create.php<?= $presetProjectId ? '?project_id=' . $presetProjectId : '' ?>" method="POST" enctype="multipart/form-data" class="space-y-6">
        <?= getCSRFInput() ?>

        <!-- 1. Beneficiary Information (المستلم / الجهة المصروف لها) -->
        <div class="bg-white rounded-3xl p-6 border border-slate-200/90 shadow-sm space-y-4">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                <span class="text-sm font-black text-slate-900 flex items-center">
                    <span class="w-7 h-7 rounded-lg bg-rose-50 text-rose-600 flex items-center justify-center text-xs ml-2">
                        <i class="fa-solid fa-user-tag"></i>
                    </span>
                    1. بيانات الجهة أو الشركة المستفيدة (المستلم)
                </span>
                <span class="text-[11px] font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 px-2.5 py-0.5 rounded-full">
                    إدخال حر مباشر
                </span>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1.5">
                    اسم الجهة / الشركة المستفيدة أو الشخص المستلم <span class="text-red-500">*</span>
                </label>
                <input type="text" name="beneficiary_name" placeholder="مثال: شركة النور للمقاولات / مؤسسة الأمل / م. أحمد خالد" required class="w-full bg-slate-50 border border-slate-300 rounded-xl px-4 py-2.5 text-xs font-bold focus:bg-white focus:ring-2 focus:ring-rose-500 focus:outline-none placeholder-slate-400">
                <p class="text-[11px] text-slate-400 mt-1 flex items-center">
                    <i class="fa-solid fa-circle-info ml-1 text-slate-400"></i>
                    اكتب اسم أي مورد أو مقاول أو موظف مباشرة، وسيظهر اسمه في سند الصرف الرسمي وفي شاشة التوقيع الإلكتروني.
                </p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">
                        رقم الهاتف / الجوال (لإرسال رابط التوقيع عبر الواتساب)
                    </label>
                    <div class="relative">
                        <input type="text" name="beneficiary_phone" placeholder="مثال: 05xxxxxxx أو 01xxxxxxx" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-4 py-2.5 text-xs font-mono font-bold focus:bg-white focus:ring-2 focus:ring-rose-500 focus:outline-none">
                        <span class="absolute left-3 top-2.5 text-emerald-600 text-xs">
                            <i class="fa-brands fa-whatsapp"></i>
                        </span>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">السجل التجاري أو رقم الهوية (اختياري)</label>
                    <input type="text" name="beneficiary_id_number" placeholder="مثال: 1010xxxxxx أو 700xxxxxxx" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-4 py-2.5 text-xs font-mono focus:bg-white focus:ring-2 focus:ring-rose-500 focus:outline-none">
                </div>
            </div>
        </div>

        <!-- 2. Financial Amount & VAT Calculation -->
        <div class="bg-white rounded-3xl p-6 border border-slate-200/90 shadow-sm space-y-4">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                <span class="text-sm font-black text-slate-900 flex items-center">
                    <span class="w-7 h-7 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center text-xs ml-2">
                        <i class="fa-solid fa-calculator"></i>
                    </span>
                    2. المبلغ المالي واحتساب الضريبة
                </span>
                <span class="text-[11px] font-bold text-indigo-700 bg-indigo-50 border border-indigo-200 px-2.5 py-0.5 rounded-full">
                    احتساب وتفقيط فوري
                </span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">
                        المبلغ الأساسي (قبل الضريبة) <span class="text-red-500">*</span>
                    </label>
                    <div class="relative">
                        <input type="number" step="0.01" min="0.01" id="vch_amount" name="amount" placeholder="0.00" required oninput="calcVoucher()" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-4 py-3 text-lg font-black text-slate-900 focus:bg-white focus:ring-2 focus:ring-amber-500 focus:outline-none">
                        <span class="absolute left-4 top-3 text-xs font-bold text-slate-400 currency-lbl"><?= htmlspecialchars($currency) ?></span>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">العملة</label>
                    <select name="currency" id="vch_curr" onchange="calcVoucher()" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3.5 py-3 text-xs font-bold focus:bg-white focus:ring-2 focus:ring-amber-500 focus:outline-none">
                        <option value="ج.م" <?= $currency === 'ج.م' ? 'selected' : '' ?>>جنيه مصري (ج.م)</option>
                        <option value="ر.س" <?= $currency === 'ر.س' ? 'selected' : '' ?>>ريال سعودي (ر.س)</option>
                        <option value="USD" <?= $currency === 'USD' ? 'selected' : '' ?>>دولار أمريكي (USD)</option>
                    </select>
                </div>
            </div>

            <!-- VAT Checkbox -->
            <div class="p-3.5 bg-amber-50/60 rounded-2xl border border-amber-200 flex items-center justify-between">
                <label class="flex items-center space-x-2.5 space-x-reverse cursor-pointer select-none">
                    <input type="checkbox" id="vch_vat" name="apply_vat" value="1" checked onchange="calcVoucher()" class="w-4 h-4 text-amber-600 rounded focus:ring-amber-500 border-slate-300">
                    <span class="text-xs font-bold text-slate-800" id="vch_vat_label">تطبيق ضريبة القيمة المضافة (<?= ($currency === 'ج.م') ? '14%' : '15%' ?> VAT)</span>
                </label>
                <span class="text-[11px] font-bold text-amber-700 bg-amber-100/70 px-2.5 py-0.5 rounded-lg border border-amber-300" id="vch_vat_tag">
                    <?= ($currency === 'ج.م') ? '+14% تلقائياً' : '+15% تلقائياً' ?>
                </span>
            </div>

            <!-- Live Breakdown Card -->
            <div class="p-4 bg-slate-900 text-white rounded-2xl text-xs space-y-2 shadow-inner">
                <div class="flex justify-between text-slate-300">
                    <span>المبلغ الأساسي الصافي:</span>
                    <strong class="font-mono text-white text-sm" id="vch_lbl_base">0.00</strong>
                </div>
                <div class="flex justify-between text-amber-400">
                    <span>قيمة ضريبة القيمة المضافة:</span>
                    <strong class="font-mono text-sm" id="vch_lbl_tax">0.00</strong>
                </div>
                <div class="flex justify-between text-sm font-black pt-2 border-t border-slate-800 text-emerald-400">
                    <span>الإجمالي الكلي النهائي لسند الصرف:</span>
                    <strong class="font-mono text-lg" id="vch_lbl_total">0.00</strong>
                </div>
                <div class="pt-2 border-t border-slate-800 text-xs text-amber-200 flex items-center">
                    <i class="fa-solid fa-pen-fancy ml-2 text-amber-400"></i>
                    <span id="vch_lbl_words">فقط صفر لا غير</span>
                </div>
            </div>
        </div>

        <!-- 3. Purpose & Category (بند وتصنيف المصروفات مع ميزة الإضافة اللحظية) -->
        <div class="bg-white rounded-3xl p-6 border border-slate-200/90 shadow-sm space-y-4">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                <span class="text-sm font-black text-slate-900 flex items-center">
                    <span class="w-7 h-7 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center text-xs ml-2">
                        <i class="fa-solid fa-tags"></i>
                    </span>
                    3. بند وتصنيف وسبب الصرف (البيان)
                </span>
                <span class="text-[11px] font-bold text-purple-700 bg-purple-50 border border-purple-200 px-2.5 py-0.5 rounded-full">
                    بحث وإضافة بند بضغطة Enter
                </span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <!-- Title / Purpose -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">
                        سبب الصرف / البيان <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="title" required placeholder="مثال: سداد دفعة مقاول، شراء مواد بناء، صيانة..." class="w-full bg-slate-50 border border-slate-300 rounded-xl px-4 py-2.5 text-xs font-bold focus:bg-white focus:ring-2 focus:ring-rose-500 focus:outline-none">
                </div>

                <!-- Category Inline Search / Add Combobox (Exact User Requirement) -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5 flex items-center justify-between">
                        <span>بند وتصنيف المصروف:</span>
                        <span id="category-feedback" class="text-[10px] font-bold text-emerald-600 hidden">
                            <i class="fa-solid fa-check ml-0.5"></i> تم اعتماد البند
                        </span>
                    </label>

                    <div class="relative" id="cat-combobox-wrapper">
                        <div class="relative">
                            <input type="text" id="cat_search_input" placeholder="ابحث عن بند أو اكتب بنداً جديداً واضغط Enter..." autocomplete="off" class="w-full bg-slate-50 border border-slate-300 rounded-xl pr-9 pl-8 py-2.5 text-xs font-bold focus:bg-white focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                            <span class="absolute right-3 top-2.5 text-slate-400 text-xs">
                                <i class="fa-solid fa-magnifying-glass"></i>
                            </span>
                            <button type="button" id="cat_clear_btn" onclick="clearCategorySelection()" class="absolute left-2.5 top-2.5 text-slate-400 hover:text-slate-600 text-xs hidden">
                                <i class="fa-solid fa-xmark"></i>
                            </button>
                        </div>

                        <!-- Hidden fields for form submission -->
                        <input type="hidden" name="category_id" id="selected_cat_id" value="">
                        <input type="hidden" name="new_category_name" id="selected_cat_new_name" value="">

                        <!-- Dropdown list -->
                        <div id="cat_dropdown_menu" class="absolute z-30 left-0 right-0 mt-1 bg-white border border-slate-200 rounded-2xl shadow-xl max-h-56 overflow-y-auto hidden divide-y divide-slate-100">
                            <!-- Items injected dynamically -->
                        </div>
                    </div>
                    <p class="text-[11px] text-slate-400 mt-1 flex items-center">
                        <i class="fa-solid fa-keyboard ml-1 text-slate-400"></i>
                        إذا لم تجد البند في القائمة، اكتب اسمه واضغط <strong>Enter</strong> ليتم حفظه واعتماده فوراً في النظام.
                    </p>
                </div>
            </div>

            <!-- Notes -->
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1.5">ملاحظات أو تفاصيل إضافية (اختياري)</label>
                <textarea name="description" rows="2" placeholder="أي تفاصيل أو شروط إضافية تود ظهورها في سند الصرف..." class="w-full bg-slate-50 border border-slate-300 rounded-xl px-4 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-rose-500 focus:outline-none"></textarea>
            </div>
        </div>

        <!-- 4. Payment Method & Cheque/Bank Details -->
        <div class="bg-white rounded-3xl p-6 border border-slate-200/90 shadow-sm space-y-4">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                <span class="text-sm font-black text-slate-900 flex items-center">
                    <span class="w-7 h-7 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center text-xs ml-2">
                        <i class="fa-solid fa-credit-card"></i>
                    </span>
                    4. طريقة الصرف والبيانات المصرفية
                </span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">طريقة الخصم والصرف</label>
                    <select name="payment_method" id="pay_method_select" onchange="toggleChequeFields()" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3.5 py-2.5 text-xs font-bold focus:bg-white focus:ring-2 focus:ring-rose-500 focus:outline-none">
                        <option value="نقدي">نقدي</option>
                        <option value="كاش">كاش</option>
                        <option value="تحويل بنكي">تحويل بنكي</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">تاريخ الصرف</label>
                    <input type="date" name="expense_date" value="<?= date('Y-m-d') ?>" required class="w-full bg-slate-50 border border-slate-300 rounded-xl px-4 py-2 text-xs font-bold focus:bg-white focus:outline-none">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">الخزنة المراد الخصم منها</label>
                    <select name="safe_id" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3.5 py-2 text-xs font-bold focus:bg-white focus:outline-none">
                        <?php foreach ($safes as $s): ?>
                            <option value="<?= $s['id'] ?>">
                                <?= htmlspecialchars($s['name']) ?> (<?= formatMoney($s['balance'] ?? 0, $currency) ?>)
                            </option>
                        <?php endforeach; ?>
                        <option value="none">بدون خصم من الخزنة (سند ورقي فقط)</option>
                    </select>
                </div>
            </div>

            <!-- Cheque / Bank Details Box -->
            <div id="cheque_details_box" class="p-4 bg-slate-50 rounded-2xl border border-slate-200 space-y-3 hidden">
                <span class="text-xs font-black text-slate-800 block flex items-center">
                    <i class="fa-solid fa-money-check-dollar ml-1.5 text-indigo-600"></i>
                    بيانات الشيك أو الحوالة البنكية:
                </span>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-[11px] font-bold text-slate-600 mb-1">رقم الشيك / الحوالة</label>
                        <input type="text" name="cheque_number" placeholder="مثال: 78912" class="w-full bg-white border border-slate-300 rounded-xl px-3 py-2 text-xs font-mono focus:ring-2 focus:ring-rose-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-600 mb-1">على بنك (اسم البنك)</label>
                        <input type="text" name="bank_name" placeholder="مثال: مصرف الراجحي / البنك الأهلي" class="w-full bg-white border border-slate-300 rounded-xl px-3 py-2 text-xs focus:ring-2 focus:ring-rose-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-600 mb-1">بتاريخ (تاريخ الشيك)</label>
                        <input type="date" name="cheque_date" class="w-full bg-white border border-slate-300 rounded-xl px-3 py-2 text-xs font-mono focus:ring-2 focus:ring-rose-500 focus:outline-none">
                    </div>
                </div>
            </div>
        </div>

        <!-- 5. Project & Attachment & Approval Options -->
        <div class="bg-white rounded-3xl p-6 border border-slate-200/90 shadow-sm space-y-4">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                <span class="text-sm font-black text-slate-900 flex items-center">
                    <span class="w-7 h-7 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center text-xs ml-2">
                        <i class="fa-solid fa-paperclip"></i>
                    </span>
                    5. المشروع والمرفقات وحالة الخصم
                </span>
            </div>

            <div class="grid grid-cols-1 <?= (hasModule('projects') && !empty($projects)) ? 'sm:grid-cols-2' : '' ?> gap-4">
                <?php if (hasModule('projects') && !empty($projects)): ?>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1.5">المشروع العقاري المرتبط (اختياري)</label>
                        <select name="project_id" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3.5 py-2.5 text-xs font-bold focus:bg-white focus:outline-none">
                            <option value="">-- عام / بدون مشروع محدد --</option>
                            <?php foreach ($projects as $pr): ?>
                                <option value="<?= $pr['id'] ?>" <?= ($presetProjectId == $pr['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($pr['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">إرفاق فواتير أو مستندات الصرف (يمكنك تحديد أكثر من فاتورة معاً - اختياري)</label>
                    <input type="file" name="invoice_files[]" multiple accept=".jpg,.jpeg,.png,.webp,.pdf" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:outline-none file:mr-0 file:ml-2 file:py-1 file:px-2.5 file:rounded-lg file:border-0 file:text-xs file:font-bold file:bg-slate-200 file:text-slate-700 hover:file:bg-slate-300">
                    <span class="text-[10px] text-slate-400 mt-1 block">صيغ مدعومة: JPG, PNG, WEBP, PDF (يمكن تحديد عدة ملفات)</span>
                </div>
            </div>

            <!-- Approval Options -->
            <?php if (!$canApprove): ?>
                <div class="p-4 bg-amber-50 border border-amber-300 rounded-2xl space-y-1.5">
                    <div class="flex items-center space-x-1.5 space-x-reverse text-amber-900 font-bold text-xs">
                        <i class="fa-solid fa-hourglass-half text-amber-600"></i>
                        <span>اعتماد وموافقة الإدارة مطلوب</span>
                    </div>
                    <p class="text-[11px] text-amber-800 leading-relaxed">
                        سيتم حفظ سند الصرف وتقديمه للاعتماد والموافقة لدى الإدارة. لن يخصم من رصيد الخزنة أو يُضاف لتكاليف المشروع حتى تتم الموافقة عليه.
                    </p>
                    <input type="hidden" name="expense_status" value="pending_approval">
                </div>
            <?php else: ?>
                <div class="p-4 bg-slate-50 border border-slate-200/90 rounded-2xl space-y-2">
                    <span class="block text-xs font-black text-slate-800 flex items-center">
                        <i class="fa-solid fa-shield-halved ml-1.5 text-rose-600"></i> خيار الخصم والاعتماد المالي:
                    </span>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <label class="flex items-center p-3 rounded-xl border border-emerald-300 bg-emerald-50/70 cursor-pointer text-xs select-none hover:bg-emerald-100/50 transition">
                            <input type="radio" name="expense_status" value="deducted" checked class="w-4 h-4 text-emerald-600 focus:ring-emerald-500">
                            <div class="mr-2.5">
                                <strong class="text-emerald-950 font-bold block">خصم فوري الآن</strong>
                                <span class="text-[10px] text-emerald-700 block">يخصم من الخزنة مباشرة ويعتمد السند</span>
                            </div>
                        </label>

                        <label class="flex items-center p-3 rounded-xl border border-amber-300 bg-amber-50/70 cursor-pointer text-xs select-none hover:bg-amber-100/50 transition">
                            <input type="radio" name="expense_status" value="pending_receipt" class="w-4 h-4 text-amber-600 focus:ring-amber-500">
                            <div class="mr-2.5">
                                <strong class="text-amber-950 font-bold block">معلق بانتظار الإيصال</strong>
                                <span class="text-[10px] text-amber-700 block">لا يخصم من الخزنة حتى استلام الفاتورة النهائية</span>
                            </div>
                        </label>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Submit Actions -->
        <div class="flex items-center justify-between pt-2">
            <a href="project_vouchers.php" class="px-5 py-3 rounded-2xl border border-slate-300 text-slate-600 font-bold hover:bg-slate-50 transition text-xs">
                إلغاء والعودة
            </a>

            <button type="submit" class="px-7 py-3.5 rounded-2xl bg-rose-600 text-white font-black hover:bg-rose-700 shadow-lg shadow-rose-600/25 transition text-sm flex items-center">
                <i class="fa-solid fa-file-signature ml-2 text-base"></i>
                حفظ وإصدار سند الصرف واستخراج رابط التوقيع
            </button>
        </div>

    </form>
</div>

<!-- CSRF Token for AJAX -->
<input type="hidden" id="global_csrf_token" value="<?= generateCSRFToken() ?>">

<script>
// Available categories from server
let existingCategories = <?= json_encode($categories, JSON_UNESCAPED_UNICODE) ?> || [];

// Calculate amount breakdown
function calcVoucher() {
    const amtInput = document.getElementById('vch_amount');
    const currSelect = document.getElementById('vch_curr');
    const vatCheck = document.getElementById('vch_vat');

    const base = parseFloat(amtInput.value) || 0;
    const curr = currSelect.value;
    const isVat = vatCheck ? vatCheck.checked : false;

    const vatRate = (curr === 'ج.م') ? 14 : 15;
    const vatTag = document.getElementById('vch_vat_tag');
    const vatLabel = document.getElementById('vch_vat_label');
    if (vatTag) vatTag.innerText = `+${vatRate}% تلقائياً`;
    if (vatLabel) vatLabel.innerText = `تطبيق ضريبة القيمة المضافة (${vatRate}% VAT)`;

    document.querySelectorAll('.currency-lbl').forEach(el => el.innerText = curr);

    let tax = 0;
    if (isVat) {
        tax = Math.round((base * (vatRate / 100)) * 100) / 100;
    }
    const total = base + tax;

    document.getElementById('vch_lbl_base').innerText = base.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ' + curr;
    document.getElementById('vch_lbl_tax').innerText = tax.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ' + curr;
    document.getElementById('vch_lbl_total').innerText = total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ' + curr;

    if (total > 0) {
        document.getElementById('vch_lbl_words').innerText = tafqeetSimple(total, curr);
    } else {
        document.getElementById('vch_lbl_words').innerText = 'فقط صفر لا غير';
    }
}

function tafqeetSimple(num, curr) {
    let intPart = Math.floor(num);
    let decPart = Math.round((num - intPart) * 100);
    let currName = curr === 'ج.م' ? 'جنيه مصري' : (curr === 'ر.س' ? 'ريال سعودي' : 'دولار أمريكي');
    let subCurr = curr === 'ج.م' ? 'قرش' : (curr === 'ر.س' ? 'هللة' : 'سنت');
    let text = `فقط ${intPart.toLocaleString('ar-EG')} ${currName}`;
    if (decPart > 0) {
        text += ` و ${decPart} ${subCurr}`;
    }
    text += ' لا غير';
    return text;
}

function toggleChequeFields() {
    const method = document.getElementById('pay_method_select').value;
    const box = document.getElementById('cheque_details_box');
    if (method === 'شيك مصرفي' || method === 'تحويل بنكي') {
        box.classList.remove('hidden');
    } else {
        box.classList.add('hidden');
    }
}

// -------------------------------------------------------------
// Interactive Category Combobox with Instant Add on Enter
// -------------------------------------------------------------
const catSearchInput = document.getElementById('cat_search_input');
const catDropdown = document.getElementById('cat_dropdown_menu');
const selectedCatIdInput = document.getElementById('selected_cat_id');
const selectedCatNewNameInput = document.getElementById('selected_cat_new_name');
const catClearBtn = document.getElementById('cat_clear_btn');
const catFeedback = document.getElementById('category-feedback');

function renderCategoryDropdown(query = '') {
    const q = query.trim().toLowerCase();
    let matches = existingCategories.filter(c => c.name.toLowerCase().includes(q));

    let html = '';

    // If query typed and doesn't exactly match an existing category, offer instant ADD
    const exactMatch = existingCategories.find(c => c.name.toLowerCase() === q);
    if (q.length > 0 && !exactMatch) {
        html += `
            <div onclick="createNewCategoryFromInput('${escapeHtml(query.trim())}')" class="p-3 bg-indigo-50 hover:bg-indigo-100 text-indigo-900 cursor-pointer flex items-center justify-between transition group font-bold text-xs">
                <div class="flex items-center space-x-2 space-x-reverse">
                    <span class="w-6 h-6 rounded-lg bg-indigo-600 text-white flex items-center justify-center text-[10px]">
                        <i class="fa-solid fa-plus"></i>
                    </span>
                    <span>إضافة <strong>"${escapeHtml(query.trim())}"</strong> كبند جديد</span>
                </div>
                <span class="text-[10px] text-indigo-600 bg-white px-2 py-0.5 rounded-md border border-indigo-200">
                    اضغط Enter ↵
                </span>
            </div>
        `;
    }

    if (matches.length > 0) {
        matches.forEach(c => {
            html += `
                <div onclick="selectCategory(${c.id}, '${escapeHtml(c.name)}')" class="p-2.5 hover:bg-slate-50 cursor-pointer flex items-center justify-between text-xs text-slate-800 transition">
                    <div class="flex items-center space-x-2 space-x-reverse">
                        <i class="fa-solid fa-tag text-slate-400 text-[10px]"></i>
                        <span>${escapeHtml(c.name)}</span>
                    </div>
                </div>
            `;
        });
    } else if (q.length === 0) {
        html += `<div class="p-3 text-xs text-slate-400 text-center">اكتب للبحث أو لإضافة بند جديد...</div>`;
    }

    catDropdown.innerHTML = html;
    catDropdown.classList.remove('hidden');
}

function selectCategory(id, name) {
    selectedCatIdInput.value = id;
    selectedCatNewNameInput.value = '';
    catSearchInput.value = name;
    catDropdown.classList.add('hidden');
    catClearBtn.classList.remove('hidden');
    showCategoryFeedback('تم اختيار البند بنجاح');
}

function clearCategorySelection() {
    selectedCatIdInput.value = '';
    selectedCatNewNameInput.value = '';
    catSearchInput.value = '';
    catClearBtn.classList.add('hidden');
    catFeedback.classList.add('hidden');
    catSearchInput.focus();
    renderCategoryDropdown('');
}

function showCategoryFeedback(msg) {
    catFeedback.innerHTML = `<i class="fa-solid fa-circle-check ml-1 text-emerald-500"></i> ${msg}`;
    catFeedback.classList.remove('hidden');
    setTimeout(() => {
        catFeedback.classList.add('hidden');
    }, 3000);
}

function createNewCategoryFromInput(catName) {
    if (!catName || !catName.trim()) return;
    const name = catName.trim();
    const csrf = document.getElementById('global_csrf_token').value;

    catFeedback.innerHTML = `<i class="fa-solid fa-spinner fa-spin ml-1 text-indigo-500"></i> جاري إضافة البند...`;
    catFeedback.classList.remove('hidden');

    fetch('ajax_category_create.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            'csrf_token': csrf,
            'name': name
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            // Add to client array
            if (!existingCategories.some(c => c.id === data.id)) {
                existingCategories.push({ id: data.id, name: data.name });
            }
            selectCategory(data.id, data.name);
            showCategoryFeedback('تمت إضافة واعتماد البند بنجاح ✓');
        } else {
            alert(data.error || 'فشل إضافة البند');
            catFeedback.classList.add('hidden');
        }
    })
    .catch(err => {
        // Fallback: set as new_category_name for server to create on submit
        selectedCatIdInput.value = '';
        selectedCatNewNameInput.value = name;
        catSearchInput.value = name;
        catDropdown.classList.add('hidden');
        catClearBtn.classList.remove('hidden');
        showCategoryFeedback('سيتم حفظ البند عند إرسال السند');
    });
}

function escapeHtml(str) {
    return (str + '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

catSearchInput.addEventListener('focus', () => {
    renderCategoryDropdown(catSearchInput.value);
});

catSearchInput.addEventListener('input', () => {
    selectedCatIdInput.value = '';
    selectedCatNewNameInput.value = '';
    if (catSearchInput.value.trim().length > 0) {
        catClearBtn.classList.remove('hidden');
    } else {
        catClearBtn.classList.add('hidden');
    }
    renderCategoryDropdown(catSearchInput.value);
});

catSearchInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
        e.preventDefault();
        const val = catSearchInput.value.trim();
        if (!val) return;

        // If exact match exists, pick it
        const match = existingCategories.find(c => c.name.toLowerCase() === val.toLowerCase());
        if (match) {
            selectCategory(match.id, match.name);
        } else {
            // Create new category inline!
            createNewCategoryFromInput(val);
        }
    }
});

// Close dropdown on click outside
document.addEventListener('click', (e) => {
    const wrapper = document.getElementById('cat-combobox-wrapper');
    if (wrapper && !wrapper.contains(e.target)) {
        catDropdown.classList.add('hidden');
    }
});

window.addEventListener('DOMContentLoaded', () => {
    calcVoucher();
    toggleChequeFields();
});
</script>

<?php include_once __DIR__ . '/includes/footer.php'; ?>
