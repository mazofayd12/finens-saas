<?php
require_once __DIR__ . '/config/app.php';
requireLogin();
requirePermission('expenses');
requireNotViewOnly();

$currentUser = getCurrentUser();
$activeCompanyId = (int)($currentUser['active_company_id'] ?? 0);
$db = getDBConnection();

if (empty($activeCompanyId)) {
    // If super admin has not selected a company, default to first active company
    $firstCo = $db->query("SELECT id FROM companies WHERE is_active = 1 ORDER BY id ASC LIMIT 1")->fetch();
    if ($firstCo) {
        $activeCompanyId = (int)$firstCo['id'];
        $_SESSION['impersonate_company_id'] = $activeCompanyId;
        $currentUser = getCurrentUser(true);
    } else {
        setFlash('error', 'يرجى إضافة أو تفعيل شركة أولاً للقيام بعمليات الصرف.');
        header('Location: dashboard.php');
        exit;
    }
}
$currency = !empty($currentUser['currency']) ? $currentUser['currency'] : 'ج.م';
$mainSafe = getCompanyMainSafe($activeCompanyId);

// Strict Tenant Check: Query projects ONLY if module enabled for this tenant
$isProjectsEnabled = hasModule('projects');
$projects = [];
if ($isProjectsEnabled) {
    $pStmt = $db->prepare("SELECT id, name, code, currency FROM projects WHERE company_id = :cid ORDER BY name ASC");
    $pStmt->execute([':cid' => $activeCompanyId]);
    $projects = $pStmt->fetchAll();
    foreach ($projects as &$p) {
        $pSafe = getProjectSafe($activeCompanyId, (int)$p['id'], $p['name']);
        $p['safe_id'] = $pSafe['id'];
        $p['safe_balance'] = (float)$pSafe['balance'];
        $p['safe_name'] = $pSafe['name'];
    }
    unset($p);
    if (empty($projects)) {
        $isProjectsEnabled = false;
    }
}

// Query categories strictly for this tenant
$catStmt = $db->prepare("SELECT * FROM expense_categories WHERE company_id = :cid AND is_active = 1 ORDER BY name ASC");
$catStmt->execute([':cid' => $activeCompanyId]);
$categories = $catStmt->fetchAll();

// Pre-fill parameters: force company safe if tenant has no real estate module
$defaultSource = ($isProjectsEnabled && isset($_GET['source']) && $_GET['source'] === 'project') ? 'project' : 'company';
$selectedProjectId = $isProjectsEnabled ? (int)($_GET['project_id'] ?? ($projects[0]['id'] ?? 0)) : 0;
$returnUrl = trim($_GET['return_url'] ?? '');

$error = '';

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'خطأ في التحقق من صحة الطلب (انتهت الجلسة). يرجى المحاولة ثانية.';
    } else {
        $amount = (float)($_POST['amount'] ?? 0);
        $expCurrency = trim($_POST['currency'] ?? $currency);
        $sourceType = trim($_POST['expense_source_type'] ?? 'project');
        $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $newCategoryName = trim($_POST['new_category_name'] ?? '');
        if (empty($categoryId) && !empty($newCategoryName)) {
            $createdCatId = getOrCreateExpenseCategory($activeCompanyId, $newCategoryName);
            if ($createdCatId) {
                $categoryId = $createdCatId;
            }
        }
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $expenseDate = trim($_POST['expense_date'] ?? date('Y-m-d'));
        $applyVat = isset($_POST['apply_vat']) && $_POST['apply_vat'] === '1';
        $beneficiaryName = trim($_POST['beneficiary_name'] ?? '');
        $beneficiaryId = trim($_POST['beneficiary_id_number'] ?? '');
        $beneficiaryPhone = trim($_POST['beneficiary_phone'] ?? '');
        $paymentMethod = trim($_POST['payment_method'] ?? 'نقدي');
        if (!in_array($paymentMethod, ['نقدي', 'كاش', 'تحويل بنكي'])) {
            $paymentMethod = 'نقدي';
        }
        
        $canApprove = canApproveExpenses();
        $requestedStatus = trim($_POST['expense_status'] ?? 'deducted');
        if (!$canApprove) {
            $expenseStatus = 'pending_approval';
        } else {
            if (in_array($requestedStatus, ['deducted', 'pending_approval', 'pending_receipt'])) {
                $expenseStatus = $requestedStatus;
            } else {
                $expenseStatus = 'deducted';
            }
        }

        $projectId = null;
        $targetSafeId = $mainSafe['id'] ?? null;

        if ($sourceType === 'project' && hasModule('projects')) {
            $projectId = (int)($_POST['project_id'] ?? 0);
            if ($projectId > 0) {
                $pSafe = getProjectSafe($activeCompanyId, $projectId);
                $targetSafeId = $pSafe['id'];
            }
        }

        if ($amount <= 0) {
            $error = 'يرجى إدخال مبلغ صحيح للصرف (أكبر من صفر).';
        } elseif (empty($title)) {
            $error = 'يرجى كتابة بيان وسبب الصرف (مثال: سداد فواتير، إيجار، أدوات مكتبية، صيانة...).';
        } elseif ($sourceType === 'project' && empty($projectId)) {
            $error = 'يرجى تحديد المشروع العقاري المراد الصرف منه.';
        } elseif (empty($targetSafeId)) {
            $error = 'لم يتم العثور على الخزنة المحددة للصرف.';
        } else {
            // VAT Calculation: 14% for EGP (ج.م) and 15% for SAR (ر.س) / default
            $vatPercent = ($expCurrency === 'ج.م' || $currency === 'ج.م') ? 14.00 : 15.00;
            $taxRate = $applyVat ? $vatPercent : 0.00;
            $taxAmount = $applyVat ? round($amount * ($vatPercent / 100), 2) : 0.00;
            $totalAmount = $amount + $taxAmount;

            // File Attachments Payload will be saved immediately after obtaining expense ID
            $attachmentPath = null;
            $uploadedFilesPayload = !empty($_FILES['invoice_files']['name']) ? $_FILES['invoice_files'] : (!empty($_FILES['invoice_file']['name']) ? $_FILES['invoice_file'] : null);

            // Category Name
            $catName = 'مصروف عام';
            if ($categoryId) {
                $cStmt = $db->prepare("SELECT name FROM expense_categories WHERE id = :id AND company_id = :cid");
                $cStmt->execute([':id' => $categoryId, ':cid' => $activeCompanyId]);
                $catData = $cStmt->fetch();
                if ($catData) {
                    $catName = $catData['name'];
                }
            }

            $voucherNo = generateVoucherNumber($activeCompanyId);
            $safeTxId = null;
            $approvedBy = null;
            $approvedAt = null;

            try {
                if ($expenseStatus === 'deducted') {
                    // Deduct from safe atomically only when approved/deducted
                    $finalTitle = $title . ($description ? " - " . $description : "");
                    if ($expCurrency !== $currency && strpos($finalTitle, $expCurrency) === false) {
                        $finalTitle .= " (العملة: " . $expCurrency . ")";
                    }

                    $txResult = recordSafeTransaction(
                        $activeCompanyId,
                        $targetSafeId,
                        $currentUser['id'],
                        'expense',
                        $catName,
                        $totalAmount,
                        $finalTitle,
                        $expenseDate,
                        $expCurrency,
                        $projectId
                    );
                    $safeTxId = $txResult['transaction_id'];
                    $approvedBy = $currentUser['id'];
                    $approvedAt = date('Y-m-d H:i:s');
                }

                $signToken = bin2hex(random_bytes(16));
                $issuerSig = !empty($currentUser['signature']) ? $currentUser['signature'] : ($company['signature'] ?? null);

                // Insert into expenses table
                $insExp = $db->prepare("
                    INSERT INTO expenses (
                        company_id, category_id, safe_id, safe_transaction_id, user_id, project_id,
                        amount, currency, title, description, expense_date,
                        invoice_attachment, tax_rate, tax_amount, beneficiary_name, beneficiary_id_number, beneficiary_phone, voucher_number,
                        status, payment_method, approved_by, approved_at,
                        sign_token, issuer_signature, signature_status,
                        created_at
                    ) VALUES (
                        :cid, :catid, :sid, :txid, :uid, :pid,
                        :amt, :curr, :title, :desc, :edate,
                        :att, :trate, :tamt, :bname, :bid, :bphone, :vnum,
                        :status, :pmethod, :app_by, :app_at,
                        :stok, :isig, 'pending_signature',
                        :now
                    )
                ");
                $insExp->execute([
                    ':cid' => $activeCompanyId,
                    ':catid' => $categoryId,
                    ':sid' => $targetSafeId,
                    ':txid' => $safeTxId,
                    ':uid' => $currentUser['id'],
                    ':pid' => $projectId,
                    ':amt' => $totalAmount,
                    ':curr' => $expCurrency,
                    ':title' => $title,
                    ':desc' => $description,
                    ':edate' => $expenseDate,
                    ':att' => $attachmentPath,
                    ':trate' => $taxRate,
                    ':tamt' => $taxAmount,
                    ':bname' => $beneficiaryName,
                    ':bid' => $beneficiaryId,
                    ':bphone' => $beneficiaryPhone,
                    ':vnum' => $voucherNo,
                    ':status' => $expenseStatus,
                    ':pmethod' => $paymentMethod,
                    ':app_by' => $approvedBy,
                    ':app_at' => $approvedAt,
                    ':stok' => $signToken,
                    ':isig' => $issuerSig,
                    ':now' => date('Y-m-d H:i:s')
                ]);

                $createdExpenseId = (int)$db->lastInsertId();

                // Save multiple invoice files linked to this new expense
                if (!empty($uploadedFilesPayload)) {
                    saveExpenseUploadedFiles($createdExpenseId, $activeCompanyId, $uploadedFilesPayload, $projectId);
                }
                recordAuditLog(
                    $activeCompanyId,
                    (int)$currentUser['id'],
                    $currentUser['name'] ?? 'مستخدم',
                    $currentUser['role'] ?? 'user',
                    'create',
                    'expenses',
                    $createdExpenseId,
                    $voucherNo,
                    "إصدار سند صرف رقم {$voucherNo} بمبلغ " . formatMoney($totalAmount, $expCurrency) . " - طريقة الخصم: [{$paymentMethod}] - البيان: {$title}" . ($beneficiaryName ? " | المستفيد: {$beneficiaryName}" : ""),
                    null,
                    [
                        'amount' => $totalAmount,
                        'currency' => $expCurrency,
                        'tax_amount' => $taxAmount,
                        'payment_method' => $paymentMethod,
                        'title' => $title,
                        'beneficiary_name' => $beneficiaryName,
                        'expense_date' => $expenseDate,
                        'status' => $expenseStatus
                    ]
                );

                if ($expenseStatus === 'pending_approval') {
                    setFlash('warning', "تم تقديم طلب المصروف بمبلغ " . formatMoney($totalAmount, $expCurrency) . " بنجاح، وهو الآن بانتظار موافقة الإدارة (لم يُخصم من الخزنة ولم يُضف للمشروع حتى يتم اعتماده). رقم السند: {$voucherNo}");
                } elseif ($expenseStatus === 'pending_receipt') {
                    setFlash('warning', "تم تسجيل المصروف كـ (معلق بانتظار إرفاق الإيصال) دون خصم مبالغ من الخزنة حالياً. رقم السند: {$voucherNo}");
                } else {
                    setFlash('success', "تم اعتماد وصرف مبلغ " . formatMoney($totalAmount, $expCurrency) . " وخصمه من الخزنة بنجاح! رقم السند: {$voucherNo}");
                }

                // Redirect
                $destination = !empty($_POST['return_url']) ? $_POST['return_url'] : ($projectId > 0 ? "project_view.php?id={$projectId}" : 'expenses.php');
                header("Location: {$destination}");
                exit;

            } catch (Exception $e) {
                $error = 'حدث خطأ أثناء الصرف: ' . $e->getMessage();
            }
        }
    }
}

$pageTitle = 'تسجيل عملية صرف وإصدار سند';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
require_once __DIR__ . '/includes/sidebar.php';

$vatPercent = ($currency === 'ر.س') ? 15 : (($currency === 'ج.م') ? 14 : 15);
$vatLabel = ($currency === 'ر.س') ? 'تطبيق ضريبة القيمة المضافة (15% VAT)' : (($currency === 'ج.م') ? 'تطبيق ضريبة القيمة المضافة (14% VAT)' : 'تطبيق ضريبة القيمة المضافة');
?>

<div class="max-w-7xl mx-auto space-y-6 pb-24 lg:pb-12">

    <!-- Top Action Breadcrumb & Title -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-white rounded-2xl p-4 sm:p-5 border border-slate-200/80 shadow-xs">
        <div class="flex items-center space-x-3 space-x-reverse">
            <button type="button" onclick="goBack()" class="w-10 h-10 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 flex items-center justify-center transition active:scale-95">
                <i class="fa-solid fa-arrow-right text-sm"></i>
            </button>
            <div>
                <h1 class="text-lg sm:text-xl font-black text-slate-900">تسجيل عملية صرف وإصدار سند</h1>
                <div class="flex items-center space-x-2 space-x-reverse text-xs text-slate-500 mt-0.5">
                    <span>الشركة: <strong class="text-slate-800"><?= htmlspecialchars($currentUser['company_name'] ?? '') ?></strong></span>
                    <span>•</span>
                    <span>المسؤول: <strong class="text-indigo-600"><?= htmlspecialchars($currentUser['name']) ?></strong></span>
                </div>
            </div>
        </div>

        <div class="flex items-center space-x-2 space-x-reverse">
            <button type="button" onclick="cancelToDashboard()" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold px-4 py-2 rounded-xl text-xs transition">
                إلغاء والعودة
            </button>
        </div>
    </div>

    <?php if (!empty($error)): ?>
        <div class="p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-800 text-xs font-bold flex items-start space-x-3 space-x-reverse shadow-xs">
            <i class="fa-solid fa-circle-exclamation text-base mt-0.5 text-rose-600 shrink-0"></i>
            <div class="flex-1"><?= htmlspecialchars($error) ?></div>
        </div>
    <?php endif; ?>

    <!-- Main Creation Form (Responsive 2-Column Grid on Desktop, Natural Stack on Mobile) -->
    <form id="expenseForm" action="expense_create.php" method="POST" enctype="multipart/form-data">
        <?= getCSRFInput() ?>
        <input type="hidden" name="expense_source_type" id="input_source_type" value="<?= htmlspecialchars($defaultSource) ?>">
        <input type="hidden" name="return_url" value="<?= htmlspecialchars($returnUrl) ?>">

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
            
            <!-- RIGHT COLUMN: Form Inputs (8 Columns on Large Screens) -->
            <div class="lg:col-span-8 space-y-6">

                <!-- 1. Source & Safe Selector -->
                <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs space-y-4">
                    <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                        <h2 class="text-sm font-black text-slate-900 flex items-center">
                            <span class="w-7 h-7 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center text-xs ml-2">
                                <i class="fa-solid fa-vault"></i>
                            </span>
                            جهة وخزنة الصرف المستهدفة
                        </h2>
                        <span class="text-xs text-slate-400">اختر من أين سيتم الصرف</span>
                    </div>

                    <?php if ($isProjectsEnabled): ?>
                        <div class="grid grid-cols-2 gap-3 bg-slate-100 p-1.5 rounded-2xl">
                            <button type="button" onclick="switchSource('project')" id="tab_source_project" class="py-2.5 px-4 rounded-xl text-xs font-black transition flex items-center justify-center space-x-2 space-x-reverse <?= $defaultSource === 'project' ? 'bg-amber-600 text-white shadow-md' : 'text-slate-600 hover:text-slate-900' ?>">
                                <i class="fa-solid fa-helmet-safety ml-1.5 text-sm"></i>
                                <span>مشروع عقاري</span>
                            </button>
                            <button type="button" onclick="switchSource('company')" id="tab_source_company" class="py-2.5 px-4 rounded-xl text-xs font-black transition flex items-center justify-center space-x-2 space-x-reverse <?= $defaultSource === 'company' ? 'bg-slate-800 text-white shadow-md' : 'text-slate-600 hover:text-slate-900' ?>">
                                <i class="fa-solid fa-building-columns ml-1.5 text-sm"></i>
                                <span>خزنة الشركة العامة</span>
                            </button>
                        </div>

                        <!-- Project Selector Box -->
                        <div id="section_project_selector" class="bg-amber-50/60 rounded-2xl p-4 border border-amber-200 space-y-3 <?= $defaultSource === 'company' ? 'hidden' : '' ?>">
                            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                                <label class="text-xs font-black text-amber-950 flex items-center">
                                    <i class="fa-solid fa-city ml-1.5 text-amber-600"></i>
                                    المشروع العقاري المراد الصرف منه:
                                </label>
                                <div class="flex items-center space-x-2 space-x-reverse text-xs bg-white border border-amber-200 rounded-xl px-3 py-1 text-amber-900 shadow-2xs">
                                    <span class="font-bold">الرصيد المتاح بالمشروع:</span>
                                    <strong class="font-mono text-emerald-700 text-sm font-black" id="display_project_balance">0.00</strong>
                                    <span class="font-bold text-[11px]" id="display_project_currency"><?= htmlspecialchars($currency) ?></span>
                                </div>
                            </div>

                            <select name="project_id" id="project_id_select" onchange="updateProjectBalance()" class="w-full bg-white border border-slate-300 rounded-xl p-3 text-xs font-bold text-slate-900 focus:ring-2 focus:ring-amber-500 focus:outline-none">
                                <?php if (empty($projects)): ?>
                                    <option value="">لا توجد مشاريع عقارية مسجلة</option>
                                <?php else: ?>
                                    <?php foreach ($projects as $pr): ?>
                                        <option value="<?= $pr['id'] ?>" data-balance="<?= $pr['safe_balance'] ?>" data-currency="<?= htmlspecialchars($pr['currency'] ?? $currency) ?>" data-code="<?= htmlspecialchars($pr['code'] ?? '') ?>" <?= $pr['id'] == $selectedProjectId ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($pr['name']) ?> (الرصيد: <?= number_format($pr['safe_balance'], 2) ?> <?= htmlspecialchars($pr['currency'] ?? $currency) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <!-- Company Safe Box -->
                    <div id="section_company_safe" class="bg-slate-50 rounded-2xl p-4 border border-slate-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 <?= ($isProjectsEnabled && $defaultSource === 'project') ? 'hidden' : '' ?>">
                        <div class="flex items-center space-x-3 space-x-reverse">
                            <div class="w-10 h-10 rounded-xl bg-slate-800 text-white flex items-center justify-center text-sm shadow-xs">
                                <i class="fa-solid fa-wallet"></i>
                            </div>
                            <div>
                                <strong class="text-xs font-black text-slate-900 block"><?= htmlspecialchars($mainSafe['name'] ?? 'الخزنة الرئيسية') ?></strong>
                                <span class="text-[11px] text-slate-500">خزنة النقدية العامة بالشركة</span>
                            </div>
                        </div>

                        <div class="flex items-center space-x-2 space-x-reverse bg-white border border-slate-200 px-3 py-1.5 rounded-xl shadow-2xs">
                            <span class="text-xs font-bold text-slate-500">الرصيد المتاح:</span>
                            <span class="text-sm font-black text-emerald-700 font-mono"><?= number_format((float)($mainSafe['balance'] ?? 0), 2) ?></span>
                            <span class="text-xs font-bold text-slate-700"><?= htmlspecialchars($currency) ?></span>
                        </div>
                    </div>
                </div>

                <!-- 2. Financial Amount & Currency & VAT -->
                <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs space-y-4">
                    <h2 class="text-sm font-black text-slate-900 flex items-center border-b border-slate-100 pb-3">
                        <span class="w-7 h-7 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center text-xs ml-2">
                            <i class="fa-solid fa-money-bill-wave"></i>
                        </span>
                        القيمة المالية والضريبة
                    </h2>

                    <div class="grid grid-cols-1 sm:grid-cols-12 gap-4">
                        <div class="sm:col-span-8">
                            <label class="block text-xs font-bold text-slate-700 mb-1.5">
                                المبلغ المطلوب صرفه <span class="text-red-500">*</span>
                            </label>
                            <input type="number" step="0.01" min="0.01" id="input_amount" name="amount" required placeholder="0.00" oninput="calculateTotal()" class="w-full text-2xl sm:text-3xl font-black bg-slate-50 border border-slate-300 rounded-xl px-4 py-2.5 text-slate-900 focus:bg-white focus:ring-2 focus:ring-rose-500 focus:outline-none">
                        </div>

                        <div class="sm:col-span-4">
                            <label class="block text-xs font-bold text-slate-700 mb-1.5">العملة</label>
                            <select id="select_currency" name="currency" onchange="calculateTotal()" class="w-full h-12 bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs font-black text-slate-800 focus:bg-white focus:ring-2 focus:ring-rose-500 focus:outline-none">
                                <option value="ر.س" <?= $currency === 'ر.س' ? 'selected' : '' ?>>ر.س (ريال سعودي)</option>
                                <option value="ج.م" <?= $currency === 'ج.م' ? 'selected' : '' ?>>ج.م (جنيه مصري)</option>
                                <option value="$" <?= $currency === '$' ? 'selected' : '' ?>>$ (دولار أمريكي)</option>
                                <option value="د.إ" <?= $currency === 'د.إ' ? 'selected' : '' ?>>د.إ (درهم إماراتي)</option>
                                <option value="د.ك" <?= $currency === 'د.ك' ? 'selected' : '' ?>>د.ك (دينار كويتي)</option>
                            </select>
                        </div>
                    </div>

                    <!-- VAT Checkbox -->
                    <div class="p-3.5 bg-slate-50 hover:bg-slate-100/80 rounded-xl border border-slate-200 transition">
                        <label class="flex items-center justify-between cursor-pointer">
                            <div class="flex items-center space-x-2 space-x-reverse">
                                <input type="checkbox" id="checkbox_apply_vat" name="apply_vat" value="1" onchange="calculateTotal()" class="w-4 h-4 rounded text-rose-600 focus:ring-rose-500">
                                <span class="text-xs font-bold text-slate-800"><?= htmlspecialchars($vatLabel) ?></span>
                            </div>
                            <span id="vat_calc_preview" class="text-xs font-mono font-bold text-amber-700">+ 0.00</span>
                        </label>
                    </div>

                    <!-- Overdraft alert -->
                    <div id="overdraft_warning" class="hidden p-3 rounded-xl bg-amber-50 border border-amber-300 text-amber-900 text-xs font-bold flex items-center space-x-2 space-x-reverse">
                        <i class="fa-solid fa-triangle-exclamation text-amber-600 shrink-0"></i>
                        <span>تنبيه: المبلغ المطلوب صرفه يتجاوز الرصيد المتوفر بالخزنة حالياً!</span>
                    </div>
                </div>

                <!-- 3. Purpose & Details -->
                <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs space-y-4">
                    <h2 class="text-sm font-black text-slate-900 flex items-center border-b border-slate-100 pb-3">
                        <span class="w-7 h-7 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center text-xs ml-2">
                            <i class="fa-solid fa-file-lines"></i>
                        </span>
                        بيانات وسبب المصروف
                    </h2>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1.5">
                                بيان وسبب الصرف <span class="text-red-500">*</span>
                            </label>
                            <input type="text" name="title" required placeholder="مثال: شراء مواد بناء / سداد إيجار / أدوات مكتبية..." class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3.5 py-2.5 text-xs font-bold focus:bg-white focus:ring-2 focus:ring-rose-500 focus:outline-none">
                        </div>

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

                                <input type="hidden" name="category_id" id="selected_cat_id" value="">
                                <input type="hidden" name="new_category_name" id="selected_cat_new_name" value="">

                                <div id="cat_dropdown_menu" class="absolute z-30 left-0 right-0 mt-1 bg-white border border-slate-200 rounded-2xl shadow-xl max-h-56 overflow-y-auto hidden divide-y divide-slate-100">
                                </div>
                            </div>
                            <p class="text-[11px] text-slate-400 mt-1 flex items-center">
                                <i class="fa-solid fa-keyboard ml-1 text-slate-400"></i>
                                اكتب اسم أي بند جديد واضغط <strong>Enter</strong> ليتم حفظه واعتماده فوراً.
                            </p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1.5">تاريخ الصرف</label>
                            <div class="flex gap-2">
                                <input type="date" name="expense_date" id="input_expense_date" value="<?= date('Y-m-d') ?>" required class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs font-mono font-bold focus:bg-white focus:outline-none">
                                <button type="button" onclick="setTodayDate()" class="shrink-0 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold px-3 py-2 rounded-xl text-xs transition">
                                    اليوم
                                </button>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1.5">ملاحظات إضافية</label>
                            <input type="text" name="description" placeholder="تفاصيل توضيحية إضافية للسند (اختياري)" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3.5 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-rose-500 focus:outline-none">
                        </div>
                    </div>
                </div>

                <!-- 4. Beneficiary Info -->
                <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs space-y-4">
                    <h2 class="text-sm font-black text-slate-900 flex items-center border-b border-slate-100 pb-3">
                        <span class="w-7 h-7 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center text-xs ml-2">
                            <i class="fa-solid fa-user-check"></i>
                        </span>
                        بيانات المستلم / الجهة المصروف لها (تظهر في السند الرسمي)
                    </h2>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1.5">اسم المستلم / الشركة الموردة</label>
                            <input type="text" name="beneficiary_name" placeholder="مثال: شركة البركة / أحمد خالد" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3.5 py-2 text-xs font-bold focus:bg-white focus:ring-2 focus:ring-rose-500 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1.5">رقم الهوية / السجل التجاري</label>
                            <input type="text" name="beneficiary_id_number" placeholder="10xxxxxxxx / 700xxxxxxx" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3.5 py-2 text-xs font-mono focus:bg-white focus:ring-2 focus:ring-rose-500 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1.5">رقم هاتف المستلم</label>
                            <input type="text" name="beneficiary_phone" placeholder="05xxxxxxxx" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3.5 py-2 text-xs font-mono focus:bg-white focus:ring-2 focus:ring-rose-500 focus:outline-none">
                        </div>
                    </div>
                </div>

                <!-- 5. Payment / Deduction Method (طريقة الخصم) -->
                <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs space-y-4">
                    <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                        <label class="block text-xs font-black text-slate-900 flex items-center">
                            <span class="w-7 h-7 rounded-lg bg-rose-50 text-rose-600 flex items-center justify-center text-xs ml-2">
                                <i class="fa-solid fa-money-check-dollar"></i>
                            </span>
                            طريقة الخصم <span class="text-red-500 mr-1">*</span>
                        </label>
                        <span class="text-[11px] text-slate-400 font-bold">حدد طريقة الخصم والسداد</span>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <!-- Option 1: نقدي -->
                        <label id="lbl_pm_naqdi" onclick="setPayMethod('نقدي')" class="relative p-4 rounded-2xl border-2 border-emerald-500 bg-emerald-50/70 flex flex-col justify-between cursor-pointer transition shadow-2xs">
                            <div class="flex items-center justify-between mb-2">
                                <div class="w-9 h-9 rounded-xl bg-emerald-600 text-white flex items-center justify-center text-base shadow-xs">
                                    <i class="fa-solid fa-coins"></i>
                                </div>
                                <input type="radio" name="payment_method" value="نقدي" checked class="w-4 h-4 text-emerald-600 focus:ring-emerald-500">
                            </div>
                            <div>
                                <strong class="text-sm font-black text-slate-900 block">نقدي</strong>
                                <span class="text-[11px] text-slate-500 mt-0.5 block">صرف نقدي من الخزينة</span>
                            </div>
                        </label>

                        <!-- Option 2: كاش -->
                        <label id="lbl_pm_cash" onclick="setPayMethod('كاش')" class="relative p-4 rounded-2xl border-2 border-slate-200 bg-slate-50 hover:border-amber-400 flex flex-col justify-between cursor-pointer transition">
                            <div class="flex items-center justify-between mb-2">
                                <div class="w-9 h-9 rounded-xl bg-amber-500 text-white flex items-center justify-center text-base shadow-xs">
                                    <i class="fa-solid fa-wallet"></i>
                                </div>
                                <input type="radio" name="payment_method" value="كاش" class="w-4 h-4 text-amber-600 focus:ring-amber-500">
                            </div>
                            <div>
                                <strong class="text-sm font-black text-slate-900 block">كاش</strong>
                                <span class="text-[11px] text-slate-500 mt-0.5 block">دفع كاش / عهدة نقدية</span>
                            </div>
                        </label>

                        <!-- Option 3: تحويل بنكي -->
                        <label id="lbl_pm_transfer" onclick="setPayMethod('تحويل بنكي')" class="relative p-4 rounded-2xl border-2 border-slate-200 bg-slate-50 hover:border-blue-400 flex flex-col justify-between cursor-pointer transition">
                            <div class="flex items-center justify-between mb-2">
                                <div class="w-9 h-9 rounded-xl bg-blue-600 text-white flex items-center justify-center text-base shadow-xs">
                                    <i class="fa-solid fa-building-columns"></i>
                                </div>
                                <input type="radio" name="payment_method" value="تحويل بنكي" class="w-4 h-4 text-blue-600 focus:ring-blue-500">
                            </div>
                            <div>
                                <strong class="text-sm font-black text-slate-900 block">تحويل بنكي</strong>
                                <span class="text-[11px] text-slate-500 mt-0.5 block">تحويل بنكي لحساب المستفيد</span>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- 6. Deduction Status & Attachment -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                    
                    <!-- Deduction Status -->
                    <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs space-y-3">
                        <label class="block text-xs font-black text-slate-900 flex items-center">
                            <i class="fa-solid fa-shield-halved ml-1.5 text-rose-600"></i>
                            حالة الخصم والاعتماد
                        </label>

                        <?php $canApprove = canApproveExpenses(); ?>
                        <?php if (!$canApprove): ?>
                            <div class="p-4 rounded-xl border-2 border-amber-300 bg-amber-50/80 space-y-2">
                                <div class="flex items-center space-x-2 space-x-reverse text-amber-900 font-black text-xs">
                                    <i class="fa-solid fa-hourglass-half text-amber-600 text-sm"></i>
                                    <span>نظام موافقة الإدارة مفعل</span>
                                </div>
                                <p class="text-[11px] text-amber-800 leading-relaxed">
                                    وفقاً لسياسة الشركة وصلاحيات حسابك، سيتم تقديم هذا المصروف كطلب للمراجعة والاعتماد.
                                    <strong class="font-bold text-amber-950 block mt-1">لن يتم خصم المبلغ من الخزنة ولن يُضاف لحسابات المشروع إلا بعد اعتماد الأدمن أو المدير المالي.</strong>
                                </p>
                                <input type="hidden" name="expense_status" value="pending_approval">
                            </div>
                        <?php else: ?>
                            <div class="space-y-2.5">
                                <label onclick="setDeductionStatus('deducted')" id="card_status_deducted" class="p-3 rounded-xl border-2 border-emerald-500 bg-emerald-50/70 flex items-start space-x-2.5 space-x-reverse cursor-pointer transition">
                                    <input type="radio" name="expense_status" value="deducted" checked class="mt-1 text-emerald-600 focus:ring-emerald-500">
                                    <div class="flex-1">
                                        <div class="flex items-center justify-between">
                                            <strong class="text-xs font-black text-emerald-950">خصم فوري واعتماد الآن</strong>
                                            <span class="text-[9px] font-black text-emerald-700 bg-emerald-100 px-2 py-0.5 rounded-full">معتمد ومخصوم</span>
                                        </div>
                                        <p class="text-[10.5px] text-emerald-800 mt-0.5">يتم خصم المبلغ من الخزنة وتثبيته في حسابات المشروع فوراً.</p>
                                    </div>
                                </label>

                                <label onclick="setDeductionStatus('pending_receipt')" id="card_status_pending" class="p-3 rounded-xl border-2 border-slate-200 bg-slate-50 flex items-start space-x-2.5 space-x-reverse cursor-pointer transition">
                                    <input type="radio" name="expense_status" value="pending_receipt" class="mt-1 text-amber-600 focus:ring-amber-500">
                                    <div class="flex-1">
                                        <div class="flex items-center justify-between">
                                            <strong class="text-xs font-black text-slate-900">معلق بانتظار الإيصال</strong>
                                            <span class="text-[9px] font-black text-amber-700 bg-amber-100 px-2 py-0.5 rounded-full">معلق</span>
                                        </div>
                                        <p class="text-[10.5px] text-slate-500 mt-0.5">تسجيل السند دون خصم حتى يتم إرفاق الفاتورة أو الإيصال.</p>
                                    </div>
                                </label>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- File Attachments (Multiple Invoices & Receipts) -->
                    <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs space-y-3">
                        <div class="flex items-center justify-between">
                            <label class="block text-xs font-black text-slate-900 flex items-center">
                                <i class="fa-solid fa-receipt ml-1.5 text-indigo-600"></i>
                                إرفاق فواتير وإيصالات الشراء (يمكنك رفع أكثر من فاتورة)
                            </label>
                            <span id="inv_files_count_badge" class="hidden text-[11px] font-bold text-indigo-700 bg-indigo-50 border border-indigo-200 px-2.5 py-0.5 rounded-full">
                                0 فواتير
                            </span>
                        </div>

                        <div id="expense_dropzone" 
                             class="relative border-2 border-dashed border-slate-300 hover:border-indigo-500 rounded-2xl p-5 text-center cursor-pointer bg-slate-50/60 hover:bg-indigo-50/20 transition group"
                             onclick="document.getElementById('mobile_invoice_file').click()"
                             ondragover="handleExpDragOver(event)"
                             ondragleave="handleExpDragLeave(event)"
                             ondrop="handleExpDrop(event)">
                            
                            <input type="file" id="mobile_invoice_file" name="invoice_files[]" multiple accept="image/*,application/pdf,.pdf" class="hidden" onchange="handleExpenseFilesSelected(this.files)">
                            
                            <div id="upload_placeholder" class="space-y-1.5 pointer-events-none">
                                <div class="w-12 h-12 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-xl mx-auto group-hover:scale-110 group-hover:bg-indigo-600 group-hover:text-white transition shadow-xs">
                                    <i class="fa-solid fa-cloud-arrow-up"></i>
                                </div>
                                <p class="text-xs font-bold text-slate-800">اسحب وأفلت الفواتير هنا أو اضغط للاختيار من جهازك</p>
                                <p class="text-[11px] text-slate-400">يمكنك تحديد عدة صور أو ملفات PDF معاً (JPG, PNG, WEBP, PDF)</p>
                            </div>

                            <!-- Live Previews Grid of Attached Invoices -->
                            <div id="invoices_preview_grid" class="hidden grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2.5 pt-3 text-right">
                                <!-- Populated by JS -->
                            </div>
                        </div>
                    </div>

                </div>

            </div>

            <!-- LEFT COLUMN: Desktop Summary & Action Card (Sticky 4 Columns) -->
            <div class="lg:col-span-4 lg:sticky lg:top-24 space-y-6">

                <!-- Live Financial Summary Card -->
                <div class="bg-white rounded-2xl p-5 border-2 border-slate-900 shadow-md space-y-4">
                    <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                        <strong class="text-xs font-black text-slate-900 flex items-center">
                            <i class="fa-solid fa-calculator ml-1.5 text-rose-600"></i>
                            ملخص العملية المالية
                        </strong>
                        <span class="text-[11px] font-mono text-slate-500 font-bold" id="card_curr_display"><?= htmlspecialchars($currency) ?></span>
                    </div>

                    <div class="space-y-2.5 text-xs">
                        <div class="flex items-center justify-between text-slate-600">
                            <span>الرصيد المتوفر بالخزنة:</span>
                            <strong class="font-mono text-slate-900 text-sm" id="card_safe_balance">0.00</strong>
                        </div>

                        <div class="flex items-center justify-between text-slate-600">
                            <span>المبلغ المطلوب صرفه:</span>
                            <strong class="font-mono text-slate-900 text-sm" id="card_base_amt">0.00</strong>
                        </div>

                        <div class="flex items-center justify-between text-slate-600">
                            <span>ضريبة القيمة المضافة:</span>
                            <strong class="font-mono text-amber-700 text-sm" id="card_vat_amt">0.00</strong>
                        </div>

                        <div class="flex items-center justify-between pt-3 border-t-2 border-slate-900 text-slate-900">
                            <span class="text-sm font-black">إجمالي الخصم من الخزنة:</span>
                            <strong class="font-mono text-2xl font-black text-rose-600" id="card_total_deduct">0.00</strong>
                        </div>

                        <div class="flex items-center justify-between pt-2 border-t border-slate-200 text-slate-600">
                            <span>الرصيد المتبقي بعد الصرف:</span>
                            <strong class="font-mono text-sm font-bold text-slate-800" id="card_remaining_balance">0.00</strong>
                        </div>
                    </div>

                    <!-- Desktop Actions -->
                    <div class="pt-2 space-y-2">
                        <button type="submit" id="btn_desktop_submit" class="w-full <?= $canApprove ? 'bg-rose-600 hover:bg-rose-700' : 'bg-amber-600 hover:bg-amber-700' ?> text-white font-black py-3 px-4 rounded-xl shadow-md transition text-xs flex items-center justify-center space-x-2 space-x-reverse active:scale-98">
                            <i class="fa-solid <?= $canApprove ? 'fa-check' : 'fa-paper-plane' ?> ml-1.5 text-sm"></i>
                            <span><?= $canApprove ? 'تأكيد وتسجيل عملية الصرف الآن' : 'إرسال طلب المصروف للاعتماد والموافقة' ?></span>
                        </button>
                        
                        <a href="<?= !empty($returnUrl) ? htmlspecialchars($returnUrl) : 'dashboard.php' ?>" class="block text-center text-xs font-bold text-slate-500 hover:text-slate-800 py-1 transition">
                            إلغاء والعودة
                        </a>
                    </div>
                </div>

                <!-- Quick Help / Tenant Info Card -->
                <div class="bg-indigo-50/70 rounded-2xl p-4 border border-indigo-100 text-xs text-indigo-900 space-y-2">
                    <div class="flex items-center space-x-2 space-x-reverse font-bold">
                        <i class="fa-solid fa-circle-info text-indigo-600"></i>
                        <span><?= $canApprove ? 'سند الصرف المالي A4' : 'نظام تدقيق واعتماد المصروفات' ?></span>
                    </div>
                    <p class="text-[11px] leading-relaxed text-indigo-800">
                        <?= $canApprove ? 'بمجرد اعتماد وصرف المصروف، يتم تسجيل قيد الخزنة تلقائياً ويمكنك طباعة سند الصرف الرسمي (أصل وصورة) بهوية شركتك وشعارها.' : 'يتم حفظ السند قيد المراجعة وإشعار الإدارة لاعتماده وصرفه. بعد الموافقة يتم خصم المبلغ وإدراجه في التقارير الرسمية.' ?>
                    </p>
                </div>

            </div>

        </div>

        <!-- Mobile Floating Sticky Dock (Visible only on mobile screens < lg) -->
        <div class="lg:hidden fixed bottom-0 inset-x-0 bg-white/95 backdrop-blur-md border-t border-slate-200 shadow-2xl z-50 p-3.5 safe-area-bottom">
            <div class="flex items-center justify-between gap-3 max-w-lg mx-auto">
                <div>
                    <span class="text-[10px] font-bold text-slate-400 block">الإجمالي المطلوب خصمه:</span>
                    <div class="flex items-baseline space-x-1 space-x-reverse">
                        <span id="dock_total_display" class="text-xl font-black text-rose-600 font-mono">0.00</span>
                        <span id="dock_currency_display" class="text-xs font-black text-slate-700"><?= htmlspecialchars($currency) ?></span>
                    </div>
                </div>
                <button type="submit" class="flex-1 <?= $canApprove ? 'bg-rose-600 hover:bg-rose-700' : 'bg-amber-600 hover:bg-amber-700' ?> text-white font-black py-3 px-4 rounded-xl shadow-md transition text-xs flex items-center justify-center space-x-1.5 space-x-reverse active:scale-95">
                    <i class="fa-solid <?= $canApprove ? 'fa-check' : 'fa-paper-plane' ?> ml-1"></i>
                    <span><?= $canApprove ? 'تأكيد وتسجيل المصروف' : 'إرسال للاعتماد' ?></span>
                </button>
            </div>
        </div>

    </form>

</div>

<!-- SCRIPTS -->
<script>
    const projectsData = <?= json_encode($projects, JSON_UNESCAPED_UNICODE) ?>;
    const mainSafeBalance = <?= (float)($mainSafe['balance'] ?? 0) ?>;
    const mainSafeCurrency = <?= json_encode($currency) ?>;

    function goBack() {
        if (document.referrer && document.referrer.indexOf(window.location.host) !== -1) {
            window.history.back();
        } else {
            window.location.href = 'dashboard.php';
        }
    }

    function cancelToDashboard() {
        const ret = '<?= addslashes($returnUrl) ?>';
        if (ret) {
            window.location.href = ret;
        } else {
            window.location.href = 'dashboard.php';
        }
    }

    function setPayMethod(val) {
        const radio = document.querySelector(`input[name="payment_method"][value="${val}"]`);
        if (radio) radio.checked = true;

        const cards = {
            'نقدي': { id: 'lbl_pm_naqdi', border: 'border-emerald-500', bg: 'bg-emerald-50/70' },
            'كاش': { id: 'lbl_pm_cash', border: 'border-amber-500', bg: 'bg-amber-50/70' },
            'تحويل بنكي': { id: 'lbl_pm_transfer', border: 'border-blue-500', bg: 'bg-blue-50/70' }
        };

        Object.keys(cards).forEach(k => {
            const el = document.getElementById(cards[k].id);
            if (!el) return;
            if (k === val) {
                el.className = `relative p-4 rounded-2xl border-2 ${cards[k].border} ${cards[k].bg} flex flex-col justify-between cursor-pointer transition shadow-2xs`;
            } else {
                el.className = `relative p-4 rounded-2xl border-2 border-slate-200 bg-slate-50 hover:border-slate-300 flex flex-col justify-between cursor-pointer transition`;
            }
        });
    }

    function switchSource(type) {
        document.getElementById('input_source_type').value = type;
        const tabProj = document.getElementById('tab_source_project');
        const tabComp = document.getElementById('tab_source_company');
        const secProj = document.getElementById('section_project_selector');
        const secComp = document.getElementById('section_company_safe');

        if (type === 'project') {
            if (tabProj) tabProj.className = 'py-2.5 px-4 rounded-xl text-xs font-black transition flex items-center justify-center space-x-2 space-x-reverse bg-amber-600 text-white shadow-md';
            if (tabComp) tabComp.className = 'py-2.5 px-4 rounded-xl text-xs font-black transition flex items-center justify-center space-x-2 space-x-reverse text-slate-600 hover:text-slate-900';
            if (secProj) secProj.classList.remove('hidden');
            if (secComp) secComp.classList.add('hidden');
            updateProjectBalance();
        } else {
            if (tabComp) tabComp.className = 'py-2.5 px-4 rounded-xl text-xs font-black transition flex items-center justify-center space-x-2 space-x-reverse bg-slate-800 text-white shadow-md';
            if (tabProj) tabProj.className = 'py-2.5 px-4 rounded-xl text-xs font-black transition flex items-center justify-center space-x-2 space-x-reverse text-slate-600 hover:text-slate-900';
            if (secComp) secComp.classList.remove('hidden');
            if (secProj) secProj.classList.add('hidden');
            calculateTotal();
        }
    }

    function updateProjectBalance() {
        const sel = document.getElementById('project_id_select');
        if (!sel || !sel.options[sel.selectedIndex]) return;
        const opt = sel.options[sel.selectedIndex];
        const balance = parseFloat(opt.getAttribute('data-balance') || 0);
        const cur = opt.getAttribute('data-currency') || 'ر.س';

        const dispBal = document.getElementById('display_project_balance');
        const dispCur = document.getElementById('display_project_currency');
        if (dispBal) dispBal.innerText = balance.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        if (dispCur) dispCur.innerText = cur;

        // Sync currency select
        const curSelect = document.getElementById('select_currency');
        if (curSelect) {
            for (let i = 0; i < curSelect.options.length; i++) {
                if (curSelect.options[i].value === cur) {
                    curSelect.selectedIndex = i;
                    break;
                }
            }
        }
        calculateTotal();
    }

    function calculateTotal() {
        const amtInput = document.getElementById('input_amount');
        const curSelect = document.getElementById('select_currency');
        const vatCheck = document.getElementById('checkbox_apply_vat');

        const baseAmt = parseFloat(amtInput ? amtInput.value : 0) || 0;
        const currency = curSelect ? curSelect.value : 'ر.س';
        const isVat = vatCheck ? vatCheck.checked : false;
        const vatRate = <?= (float)$vatPercent ?>;

        const vatAmt = isVat ? Math.round(baseAmt * (vatRate / 100) * 100) / 100 : 0;
        const totalAmt = baseAmt + vatAmt;

        // VAT preview text
        const vatPreview = document.getElementById('vat_calc_preview');
        if (vatPreview) {
            vatPreview.innerText = isVat ? ('+ ضريبة: ' + vatAmt.toLocaleString('en-US', {minimumFractionDigits: 2}) + ' ' + currency) : '+ 0.00';
        }

        // Dock displays
        const dockTot = document.getElementById('dock_total_display');
        const dockCur = document.getElementById('dock_currency_display');
        if (dockTot) dockTot.innerText = totalAmt.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        if (dockCur) dockCur.innerText = currency;

        // Determine available balance
        const sourceType = document.getElementById('input_source_type') ? document.getElementById('input_source_type').value : 'company';
        let availableBalance = 0;
        if (sourceType === 'project') {
            const sel = document.getElementById('project_id_select');
            if (sel && sel.options[sel.selectedIndex]) {
                availableBalance = parseFloat(sel.options[sel.selectedIndex].getAttribute('data-balance') || 0);
            }
        } else {
            availableBalance = mainSafeBalance;
        }

        // Desktop summary card updates
        const cardSafeBal = document.getElementById('card_safe_balance');
        const cardBaseAmt = document.getElementById('card_base_amt');
        const cardVatAmt = document.getElementById('card_vat_amt');
        const cardTotal = document.getElementById('card_total_deduct');
        const cardRem = document.getElementById('card_remaining_balance');
        const cardCur = document.getElementById('card_curr_display');

        if (cardSafeBal) cardSafeBal.innerText = availableBalance.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        if (cardBaseAmt) cardBaseAmt.innerText = baseAmt.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        if (cardVatAmt) cardVatAmt.innerText = vatAmt.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        if (cardTotal) cardTotal.innerText = totalAmt.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        if (cardCur) cardCur.innerText = currency;

        const remBal = availableBalance - totalAmt;
        if (cardRem) {
            cardRem.innerText = remBal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            if (remBal < 0) {
                cardRem.className = 'font-mono text-sm font-bold text-rose-600';
            } else {
                cardRem.className = 'font-mono text-sm font-bold text-emerald-700';
            }
        }

        // Check overdraft against active safe
        const warnBox = document.getElementById('overdraft_warning');
        if (warnBox) {
            if (totalAmt > 0 && totalAmt > availableBalance) {
                warnBox.classList.remove('hidden');
            } else {
                warnBox.classList.add('hidden');
            }
        }
    }

    function setDeductionStatus(status) {
        const cardDed = document.getElementById('card_status_deducted');
        const cardPend = document.getElementById('card_status_pending');
        const radios = document.getElementsByName('expense_status');

        for (let r of radios) {
            if (r.value === status) r.checked = true;
        }

        const unselClass = 'p-3 rounded-xl border-2 border-slate-200 bg-slate-50 flex items-start space-x-2.5 space-x-reverse cursor-pointer transition';
        if (cardDed) cardDed.className = unselClass;
        if (cardPend) cardPend.className = unselClass;

        if (status === 'deducted' && cardDed) {
            cardDed.className = 'p-3 rounded-xl border-2 border-emerald-500 bg-emerald-50/70 flex items-start space-x-2.5 space-x-reverse cursor-pointer transition';
        } else if (status === 'pending_receipt' && cardPend) {
            cardPend.className = 'p-3 rounded-xl border-2 border-amber-500 bg-amber-50/70 flex items-start space-x-2.5 space-x-reverse cursor-pointer transition';
        }
    }

    function setTodayDate() {
        const today = new Date().toISOString().split('T')[0];
        const el = document.getElementById('input_expense_date');
        if (el) el.value = today;
    }

    // -------------------------------------------------------------
    // Multi-Invoice Upload & Drag-and-Drop Handler
    // -------------------------------------------------------------
    let expenseAttachedFiles = [];

    function formatExpFileSize(bytes) {
        if (!bytes || bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    }

    function handleExpDragOver(e) {
        e.preventDefault();
        e.stopPropagation();
        const dropzone = document.getElementById('expense_dropzone');
        if (dropzone) {
            dropzone.classList.add('border-indigo-600', 'bg-indigo-50/70', 'ring-2', 'ring-indigo-100');
        }
    }

    function handleExpDragLeave(e) {
        e.preventDefault();
        e.stopPropagation();
        const dropzone = document.getElementById('expense_dropzone');
        if (dropzone) {
            dropzone.classList.remove('border-indigo-600', 'bg-indigo-50/70', 'ring-2', 'ring-indigo-100');
        }
    }

    function handleExpDrop(e) {
        e.preventDefault();
        e.stopPropagation();
        const dropzone = document.getElementById('expense_dropzone');
        if (dropzone) {
            dropzone.classList.remove('border-indigo-600', 'bg-indigo-50/70', 'ring-2', 'ring-indigo-100');
        }
        if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length > 0) {
            appendExpenseFiles(e.dataTransfer.files);
        }
    }

    function handleExpenseFilesSelected(files) {
        if (files && files.length > 0) {
            appendExpenseFiles(files);
        }
    }

    function appendExpenseFiles(fileList) {
        Array.from(fileList).forEach(file => {
            const exists = expenseAttachedFiles.some(f => f.name === file.name && f.size === file.size && f.lastModified === file.lastModified);
            if (!exists) {
                expenseAttachedFiles.push(file);
            }
        });
        syncExpenseFileInput();
        renderExpensePreviews();
    }

    function removeExpenseFile(index, e) {
        if (e) e.stopPropagation();
        if (index >= 0 && index < expenseAttachedFiles.length) {
            expenseAttachedFiles.splice(index, 1);
            syncExpenseFileInput();
            renderExpensePreviews();
        }
    }

    function syncExpenseFileInput() {
        const input = document.getElementById('mobile_invoice_file');
        if (!input) return;
        try {
            const dt = new DataTransfer();
            expenseAttachedFiles.forEach(file => dt.items.add(file));
            input.files = dt.files;
        } catch (err) {
            console.warn('DataTransfer sync warning:', err);
        }
    }

    function renderExpensePreviews() {
        const grid = document.getElementById('invoices_preview_grid');
        const placeholder = document.getElementById('upload_placeholder');
        const badge = document.getElementById('inv_files_count_badge');

        if (!grid) return;
        grid.innerHTML = '';

        if (expenseAttachedFiles.length === 0) {
            grid.classList.add('hidden');
            grid.classList.remove('grid');
            if (placeholder) placeholder.classList.remove('hidden');
            if (badge) badge.classList.add('hidden');
            return;
        }

        if (placeholder) placeholder.classList.add('hidden');
        grid.classList.remove('hidden');
        grid.classList.add('grid');

        if (badge) {
            badge.classList.remove('hidden');
            const count = expenseAttachedFiles.length;
            badge.innerText = count === 1 ? 'فاتورة واحدة مرفقة' : `${count} فواتير مرفقة`;
        }

        expenseAttachedFiles.forEach((file, idx) => {
            const card = document.createElement('div');
            card.className = 'group relative p-2 rounded-xl bg-white border border-slate-200/90 shadow-2xs hover:shadow-xs transition text-center flex flex-col justify-between';
            card.onclick = (e) => e.stopPropagation();

            const isImg = file.type && file.type.startsWith('image/');
            const isPdf = file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf');

            let mediaPreview = '';
            if (isImg) {
                const url = URL.createObjectURL(file);
                mediaPreview = `<img src="${url}" class="w-full h-16 object-cover rounded-lg mb-1.5 border border-slate-100" onload="URL.revokeObjectURL('${url}')">`;
            } else if (isPdf) {
                mediaPreview = `
                    <div class="w-full h-16 rounded-lg bg-red-50 text-red-600 flex flex-col items-center justify-center mb-1.5 border border-red-100">
                        <i class="fa-solid fa-file-pdf text-xl mb-0.5"></i>
                        <span class="text-[9px] font-black uppercase">PDF</span>
                    </div>
                `;
            } else {
                mediaPreview = `
                    <div class="w-full h-16 rounded-lg bg-slate-100 text-slate-600 flex flex-col items-center justify-center mb-1.5">
                        <i class="fa-solid fa-file text-xl mb-0.5"></i>
                    </div>
                `;
            }

            card.innerHTML = `
                ${mediaPreview}
                <div class="min-w-0 px-1">
                    <p class="text-[11px] font-bold text-slate-800 truncate" title="${file.name}">${file.name}</p>
                    <p class="text-[9px] text-slate-400 font-mono">${formatExpFileSize(file.size)}</p>
                </div>
                <button type="button" onclick="removeExpenseFile(${idx}, event)" class="absolute -top-1.5 -left-1.5 w-5 h-5 rounded-full bg-rose-600 hover:bg-rose-700 text-white flex items-center justify-center shadow-xs text-[10px] transition" title="إزالة هذه الفاتورة">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            `;
            grid.appendChild(card);
        });
    }

    // Fallbacks for legacy function calls
    function previewReceipt(input) {
        if (input && input.files) {
            handleExpenseFilesSelected(input.files);
        }
    }
    function clearReceipt(e) {
        if (e) e.stopPropagation();
        expenseAttachedFiles = [];
        syncExpenseFileInput();
        renderExpensePreviews();
    }

    // -------------------------------------------------------------
    // Interactive Category Combobox with Instant Add on Enter
    // -------------------------------------------------------------
    let existingCategories = <?= json_encode($categories, JSON_UNESCAPED_UNICODE) ?> || [];
    const catSearchInput = document.getElementById('cat_search_input');
    const catDropdown = document.getElementById('cat_dropdown_menu');
    const selectedCatIdInput = document.getElementById('selected_cat_id');
    const selectedCatNewNameInput = document.getElementById('selected_cat_new_name');
    const catClearBtn = document.getElementById('cat_clear_btn');
    const catFeedback = document.getElementById('category-feedback');

    function renderCategoryDropdown(query = '') {
        if (!catDropdown) return;
        const q = query.trim().toLowerCase();
        let matches = existingCategories.filter(c => c.name.toLowerCase().includes(q));

        let html = '';

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
        if (selectedCatIdInput) selectedCatIdInput.value = id;
        if (selectedCatNewNameInput) selectedCatNewNameInput.value = '';
        if (catSearchInput) catSearchInput.value = name;
        if (catDropdown) catDropdown.classList.add('hidden');
        if (catClearBtn) catClearBtn.classList.remove('hidden');
        showCategoryFeedback('تم اختيار البند بنجاح');
    }

    function clearCategorySelection() {
        if (selectedCatIdInput) selectedCatIdInput.value = '';
        if (selectedCatNewNameInput) selectedCatNewNameInput.value = '';
        if (catSearchInput) catSearchInput.value = '';
        if (catClearBtn) catClearBtn.classList.add('hidden');
        if (catFeedback) catFeedback.classList.add('hidden');
        if (catSearchInput) {
            catSearchInput.focus();
            renderCategoryDropdown('');
        }
    }

    function showCategoryFeedback(msg) {
        if (!catFeedback) return;
        catFeedback.innerHTML = `<i class="fa-solid fa-circle-check ml-1 text-emerald-500"></i> ${msg}`;
        catFeedback.classList.remove('hidden');
        setTimeout(() => {
            catFeedback.classList.add('hidden');
        }, 3000);
    }

    function createNewCategoryFromInput(catName) {
        if (!catName || !catName.trim()) return;
        const name = catName.trim();
        const csrfEl = document.querySelector('input[name="csrf_token"]');
        const csrf = csrfEl ? csrfEl.value : '';

        if (catFeedback) {
            catFeedback.innerHTML = `<i class="fa-solid fa-spinner fa-spin ml-1 text-indigo-500"></i> جاري إضافة البند...`;
            catFeedback.classList.remove('hidden');
        }

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
                if (!existingCategories.some(c => c.id === data.id)) {
                    existingCategories.push({ id: data.id, name: data.name });
                }
                selectCategory(data.id, data.name);
                showCategoryFeedback('تمت إضافة واعتماد البند بنجاح ✓');
            } else {
                alert(data.error || 'فشل إضافة البند');
                if (catFeedback) catFeedback.classList.add('hidden');
            }
        })
        .catch(err => {
            if (selectedCatIdInput) selectedCatIdInput.value = '';
            if (selectedCatNewNameInput) selectedCatNewNameInput.value = name;
            if (catSearchInput) catSearchInput.value = name;
            if (catDropdown) catDropdown.classList.add('hidden');
            if (catClearBtn) catClearBtn.classList.remove('hidden');
            showCategoryFeedback('سيتم حفظ البند عند إرسال السند');
        });
    }

    function escapeHtml(str) {
        return (str + '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    if (catSearchInput) {
        catSearchInput.addEventListener('focus', () => {
            renderCategoryDropdown(catSearchInput.value);
        });

        catSearchInput.addEventListener('input', () => {
            if (selectedCatIdInput) selectedCatIdInput.value = '';
            if (selectedCatNewNameInput) selectedCatNewNameInput.value = '';
            if (catSearchInput.value.trim().length > 0) {
                if (catClearBtn) catClearBtn.classList.remove('hidden');
            } else {
                if (catClearBtn) catClearBtn.classList.add('hidden');
            }
            renderCategoryDropdown(catSearchInput.value);
        });

        catSearchInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                const val = catSearchInput.value.trim();
                if (!val) return;

                const match = existingCategories.find(c => c.name.toLowerCase() === val.toLowerCase());
                if (match) {
                    selectCategory(match.id, match.name);
                } else {
                    createNewCategoryFromInput(val);
                }
            }
        });

        document.addEventListener('click', (e) => {
            const wrapper = document.getElementById('cat-combobox-wrapper');
            if (wrapper && !wrapper.contains(e.target) && catDropdown) {
                catDropdown.classList.add('hidden');
            }
        });
    }

    // Initialize on load
    document.addEventListener('DOMContentLoaded', () => {
        if (<?= $isProjectsEnabled ? 'true' : 'false' ?>) {
            updateProjectBalance();
        }
        calculateTotal();
    });
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
