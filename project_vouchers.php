<?php
require_once __DIR__ . '/config/app.php';
requireLogin();

// Redirect ?action=new to dedicated full page voucher_create.php (matching receipt_create.php)
if (isset($_GET['action']) && $_GET['action'] === 'new') {
    $redirectParams = [];
    if (!empty($_GET['project_id'])) {
        $redirectParams[] = 'project_id=' . (int)$_GET['project_id'];
    }
    header('Location: voucher_create.php' . (!empty($redirectParams) ? '?' . implode('&', $redirectParams) : ''));
    exit;
}

$currentUser = getCurrentUser();
$activeCompanyId = (int)($currentUser['active_company_id'] ?? 0);
$db = getDBConnection();
if ($activeCompanyId <= 0 && isSuperAdmin()) {
    $firstComp = $db->query("SELECT id FROM companies WHERE is_active = 1 LIMIT 1")->fetch();
    if ($firstComp) {
        $activeCompanyId = (int)$firstComp['id'];
    }
}
$currency = $currentUser['currency'] ?? 'ج.م';

// Fetch company details
$compStmt = $db->prepare("SELECT * FROM companies WHERE id = :cid LIMIT 1");
$compStmt->execute([':cid' => $activeCompanyId]);
$company = $compStmt->fetch();

// Fetch safes for selection
$safes = getCompanySafes($activeCompanyId);
if (empty($safes)) {
    $mainSafe = getCompanyMainSafe($activeCompanyId);
    $safes = [$mainSafe];
}

// Fetch projects for selection & filter
$projects = [];
if (hasModule('projects')) {
    $pStmt = $db->prepare("SELECT id, name, code FROM projects WHERE company_id = :cid ORDER BY name ASC");
    $pStmt->execute([':cid' => $activeCompanyId]);
    $projects = $pStmt->fetchAll();
}

// Handle POST: Issue Payment Voucher (سند صرف مالي A4)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'issue_voucher') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('خطأ في التحقق من صحة الطلب (CSRF)');
    }

    $beneficiaryName = trim($_POST['beneficiary_name'] ?? '');
    $beneficiaryIdNumber = trim($_POST['beneficiary_id_number'] ?? '');
    $beneficiaryPhone = trim($_POST['beneficiary_phone'] ?? '');
    $baseAmount = (float)($_POST['amount'] ?? 0);
    $applyVat = isset($_POST['apply_vat']) && $_POST['apply_vat'] === '1';
    $voucherCurrency = trim($_POST['currency'] ?? $currency);
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $paymentMethod = trim($_POST['payment_method'] ?? 'نقداً كاش');
    $chequeNumber = trim($_POST['cheque_number'] ?? '');
    $bankName = trim($_POST['bank_name'] ?? '');
    $chequeDate = !empty($_POST['cheque_date']) ? trim($_POST['cheque_date']) : null;
    $expenseDate = !empty($_POST['expense_date']) ? trim($_POST['expense_date']) : date('Y-m-d');
    $safeIdVal = $_POST['safe_id'] ?? '';
    $projectId = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;
    $printAfter = isset($_POST['print_after']) && $_POST['print_after'] === '1';

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

    if (empty($beneficiaryName)) {
        setFlash('error', 'يرجى إدخال اسم الجهة أو الشركة المستفيدة أو الشخص المستلم.');
    } elseif ($baseAmount <= 0) {
        setFlash('error', 'يرجى إدخال مبلغ صحيح لسند الصرف.');
    } elseif (empty($title)) {
        setFlash('error', 'يرجى إدخال بند أو سبب الصرف (البيان).');
    } else {
        $vatPercent = ($voucherCurrency === 'ج.م' || $currency === 'ج.م') ? 14.00 : 15.00;
        $taxRate = $applyVat ? $vatPercent : 0.00;
        $taxAmount = $applyVat ? round($baseAmount * ($vatPercent / 100), 2) : 0.00;
        $totalAmount = $baseAmount + $taxAmount;

        $db->beginTransaction();
        try {
            // Safe determination
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

            // Project verification if chosen
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

            // Handle invoice attachment upload (single or multiple files)
            $attachmentPath = null;
            $uploadedFilesPayload = !empty($_FILES['invoice_files']['name']) ? $_FILES['invoice_files'] : (!empty($_FILES['invoice_file']['name']) ? $_FILES['invoice_file'] : null);

            $voucherNo = generateVoucherNumber($activeCompanyId);

            $safeTxId = null;
            $approvedBy = null;
            $approvedAt = null;
            $safeIdToSave = $targetSafe ? (int)$targetSafe['id'] : (int)(getCompanyMainSafe($activeCompanyId)['id']);

            // Deduct from safe only if deducted mode is selected
            if ($targetSafe && $expenseStatus === 'deducted') {
                $txDesc = "سند صرف رقم {$voucherNo} إلى: {$beneficiaryName} - {$title}";
                if ($projectName) {
                    $txDesc .= " | مشروع: {$projectName}";
                }
                if ($applyVat) {
                    $txDesc .= " (شامل ضريبة 15%: " . formatMoney($taxAmount, $voucherCurrency) . ")";
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

            $signToken = bin2hex(random_bytes(16));
            $issuerSig = !empty($currentUser['signature']) ? $currentUser['signature'] : ($company['signature'] ?? null);

            // Insert into expenses
            $insStmt = $db->prepare("
                INSERT INTO expenses (
                    company_id, safe_id, safe_transaction_id, user_id, project_id,
                    amount, currency, title, description, expense_date,
                    invoice_attachment, tax_rate, tax_amount,
                    beneficiary_name, beneficiary_id_number, beneficiary_phone,
                    voucher_number, status, payment_method, cheque_number, bank_name, cheque_date,
                    approved_by, approved_at,
                    sign_token, issuer_signature, signature_status,
                    created_at
                ) VALUES (
                    :cid, :safe_id, :safe_tx_id, :uid, :pid,
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

            recordAuditLog(
                $activeCompanyId,
                (int)$currentUser['id'],
                $currentUser['name'] ?? 'مستخدم',
                $currentUser['role'] ?? 'user',
                'create',
                'expenses',
                $newExpenseId,
                $voucherNo,
                "إصدار سند صرف رقم {$voucherNo} بمبلغ " . formatMoney($totalAmount, $voucherCurrency) . " - البيان: {$title}" . ($beneficiaryName ? " | المستفيد: {$beneficiaryName}" : ""),
                null,
                [
                    'amount' => $totalAmount,
                    'currency' => $voucherCurrency,
                    'tax_amount' => $taxAmount,
                    'title' => $title,
                    'beneficiary_name' => $beneficiaryName,
                    'expense_date' => $expenseDate,
                    'status' => $expenseStatus
                ]
            );

            $db->commit();

            if ($expenseStatus === 'pending_approval') {
                setFlash('warning', "تم حفظ سند الصرف رقم ({$voucherNo}) وهو بانتظار موافقة الإدارة، ورابط التوقيع الإلكتروني جاهز للإرسال للمستفيد.");
            } elseif ($expenseStatus === 'pending_receipt') {
                setFlash('warning', "تم حفظ سند الصرف رقم ({$voucherNo}) كـ (معلق بانتظار الإيصال) ورابط التوقيع جاهز.");
            } else {
                setFlash('success', "تم إصدار سند الصرف رقم ({$voucherNo}) إلى ({$beneficiaryName}) بنجاح! رابط التوقيع الإلكتروني جاهز للإرسال.");
            }

            header('Location: voucher_share.php?id=' . $newExpenseId . ($projectId ? '&from_project=' . $projectId : ''));
            exit;
        } catch (Exception $e) {
            $db->rollBack();
            setFlash('error', $e->getMessage());
        }
    }
}

// Action: Confirm Pending Expense
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'confirm_pending_expense') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('خطأ في التحقق من صحة الطلب');
    }
    $expenseId = (int)($_POST['expense_id'] ?? 0);
    $redirectUrl = trim($_POST['redirect_url'] ?? 'project_vouchers.php');

    $stmt = $db->prepare("SELECT * FROM expenses WHERE id = :id AND company_id = :cid AND status = 'pending_receipt'");
    $stmt->execute([':id' => $expenseId, ':cid' => $activeCompanyId]);
    $exp = $stmt->fetch();

    if (!$exp) {
        setFlash('error', 'السند غير موجود أو تم تأكيده مسبقاً.');
        header('Location: ' . $redirectUrl);
        exit;
    }

    $attachmentPath = $exp['invoice_attachment'];
    $uploadedConfFiles = !empty($_FILES['invoice_files']['name']) ? $_FILES['invoice_files'] : (!empty($_FILES['invoice_file']['name']) ? $_FILES['invoice_file'] : null);
    if (!empty($uploadedConfFiles)) {
        $savedConfAtts = saveExpenseUploadedFiles($expenseId, $activeCompanyId, $uploadedConfFiles, (int)$exp['project_id']);
        if (!empty($savedConfAtts)) {
            $attachmentPath = $savedConfAtts[0]['file_path'];
        }
    }

    if (empty($attachmentPath)) {
        setFlash('error', 'يرجى رفع صورة الإيصال أو الفاتورة لتأكيد الخصم من الخزنة.');
        header('Location: ' . $redirectUrl);
        exit;
    }

    try {
        $targetSafeId = (int)$exp['safe_id'];
        $expCurrency = $exp['currency'];
        $totalAmount = (float)$exp['amount'];
        $finalTitle = "سند صرف رقم " . ($exp['voucher_number'] ?: $exp['id']) . " - " . $exp['title'] . ($exp['beneficiary_name'] ? " | المستفيد: " . $exp['beneficiary_name'] : "");

        $txResult = recordSafeTransaction(
            $activeCompanyId,
            $targetSafeId,
            $currentUser['id'],
            'expense',
            $exp['title'],
            $totalAmount,
            $finalTitle,
            date('Y-m-d'),
            $expCurrency,
            $exp['project_id']
        );

        $upd = $db->prepare("
            UPDATE expenses
            SET invoice_attachment = :att,
                safe_transaction_id = :txid,
                status = 'deducted'
            WHERE id = :id AND company_id = :cid
        ");
        $upd->execute([
            ':att' => $attachmentPath,
            ':txid' => $txResult['transaction_id'],
            ':id' => $expenseId,
            ':cid' => $activeCompanyId
        ]);

        setFlash('success', 'تم إرفاق صورة الإيصال بنجاح وخصم مبلغ ' . formatMoney($totalAmount, $expCurrency) . ' من الخزنة!');
        header('Location: ' . $redirectUrl);
        exit;
    } catch (Exception $e) {
        setFlash('error', $e->getMessage());
        header('Location: ' . $redirectUrl);
        exit;
    }
}

// Action: Edit Expense / Voucher
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_expense') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('خطأ في التحقق من صحة الطلب');
    }
    $expenseId = (int)($_POST['expense_id'] ?? 0);
    $redirectUrl = trim($_POST['redirect_url'] ?? 'project_vouchers.php');
    try {
        $result = editExpenseRecord($expenseId, $_POST, $currentUser);
        setFlash('success', $result['message']);
    } catch (Exception $e) {
        setFlash('error', 'فشل تعديل السند: ' . $e->getMessage());
    }
    header('Location: ' . $redirectUrl);
    exit;
}

// Filters for listing
$filterProject = !empty($_GET['project_id']) ? (int)$_GET['project_id'] : null;
$filterCat = !empty($_GET['category_id']) ? (int)$_GET['category_id'] : null;
$filterFrom = trim($_GET['from'] ?? '');
$filterTo = trim($_GET['to'] ?? '');
$search = trim($_GET['search'] ?? '');
$excludeLand = isset($_GET['exclude_land']) && $_GET['exclude_land'] == '1';

$query = "
    SELECT e.*, 
           p.name as project_name, p.code as project_code,
           s.name as safe_name,
           u.name as user_name,
           u_edit.name as editor_name,
           ec.name as category_name,
           st.balance_before,
           st.balance_after
    FROM expenses e
    LEFT JOIN projects p ON e.project_id = p.id
    LEFT JOIN safes s ON e.safe_id = s.id
    LEFT JOIN users u ON e.user_id = u.id
    LEFT JOIN users u_edit ON e.last_edited_by = u_edit.id
    LEFT JOIN expense_categories ec ON e.category_id = ec.id
    LEFT JOIN safe_transactions st ON e.safe_transaction_id = st.id
    WHERE e.company_id = :cid AND e.voucher_number IS NOT NULL
";
$params = [':cid' => $activeCompanyId];

if ($filterProject) {
    $query .= " AND e.project_id = :pid";
    $params[':pid'] = $filterProject;
}
if ($filterCat) {
    $query .= " AND e.category_id = :cat";
    $params[':cat'] = $filterCat;
}
if (!empty($filterFrom)) {
    $query .= " AND e.expense_date >= :from";
    $params[':from'] = $filterFrom;
}
if (!empty($filterTo)) {
    $query .= " AND e.expense_date <= :to";
    $params[':to'] = $filterTo;
}
if (!empty($search)) {
    $query .= " AND (
        e.voucher_number LIKE :s 
        OR e.beneficiary_name LIKE :s 
        OR e.beneficiary_id_number LIKE :s 
        OR e.title LIKE :s 
        OR e.description LIKE :s 
        OR ec.name LIKE :s 
        OR u.name LIKE :s
    )";
    $params[':s'] = "%{$search}%";
}
if ($excludeLand) {
    $query .= " AND NOT " . getLandExpensesSqlCondition('e', 'ec');
}
$query .= " ORDER BY e.id DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$vouchers = $stmt->fetchAll();

// Batch fetch attachments for vouchers (supports multiple invoices)
$allVchIds = array_column($vouchers, 'id');
$expenseAttachmentsGrouped = getBatchExpenseAttachments($allVchIds);

// Categories for filter
$vchCatStmt = $db->prepare("SELECT id, name FROM expense_categories WHERE company_id = :cid AND is_active = 1 ORDER BY name ASC");
$vchCatStmt->execute([':cid' => $activeCompanyId]);
$voucherCategories = $vchCatStmt->fetchAll();

// Totals
$totalVouchersAmt = 0;
$totalTaxAmt = 0;
foreach ($vouchers as $v) {
    $totalVouchersAmt += (float)$v['amount'];
    $totalTaxAmt += (float)$v['tax_amount'];
}

$pageTitle = 'سندات الصرف وفواتير A4 المعتمدة';
require_once __DIR__ . '/includes/header.php';
$canApprove = canApproveExpenses();
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center space-x-2 space-x-reverse">
                <span class="w-10 h-10 rounded-xl bg-rose-600 text-white flex items-center justify-center font-bold text-lg shadow-md">
                    <i class="fa-solid fa-file-invoice-dollar"></i>
                </span>
                <div>
                    <h1 class="text-2xl font-black text-slate-900">سندات الصرف والفواتير المعتمدة (A4)</h1>
                    <p class="text-xs text-slate-500">إصدار وأرشيف وطباعة سندات الصرف المالي وفواتير الشراء المعتمدة</p>
                </div>
            </div>
        </div>

        <a href="voucher_create.php" class="inline-flex items-center bg-rose-600 hover:bg-rose-700 text-white font-bold px-5 py-2.5 rounded-xl shadow-md transition text-xs sm:text-sm">
            <i class="fa-solid fa-plus-circle ml-2"></i>
            إصدار سند صرف جديد (A4)
        </a>
    </div>

    <!-- KPI Summary Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-xs font-bold text-slate-400 mb-1">عدد السندات المسجلة</p>
                <h3 class="text-2xl font-black text-slate-800"><?= count($vouchers) ?> <span class="text-xs font-normal text-slate-400">سند</span></h3>
            </div>
            <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-lg font-bold">
                <i class="fa-solid fa-receipt"></i>
            </div>
        </div>

        <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-xs font-bold text-slate-400 mb-1">إجمالي المبالغ المصروفة</p>
                <h3 class="text-xl font-black text-rose-600"><?= formatMoney($totalVouchersAmt, $currency) ?></h3>
            </div>
            <div class="w-10 h-10 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center text-lg font-bold">
                <i class="fa-solid fa-hand-holding-dollar"></i>
            </div>
        </div>

        <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-xs font-bold text-slate-400 mb-1">إجمالي ضريبة القيمة المضافة</p>
                <h3 class="text-xl font-black text-amber-600"><?= formatMoney($totalTaxAmt, $currency) ?></h3>
            </div>
            <div class="w-10 h-10 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center text-lg font-bold">
                <i class="fa-solid fa-percent"></i>
            </div>
        </div>
    </div>

    <!-- Filters Bar -->
    <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-xs">
        <form action="project_vouchers.php" method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3 items-end">
            <?php if (hasModule('projects') && !empty($projects)): ?>
                <div>
                    <label class="block text-[11px] font-bold text-slate-600 mb-1">تصفية حسب المشروع</label>
                    <select name="project_id" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs font-bold focus:bg-white focus:outline-none">
                        <option value="">كافة المشاريع والمصروفات</option>
                        <?php foreach ($projects as $pr): ?>
                            <option value="<?= $pr['id'] ?>" <?= $filterProject === (int)$pr['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($pr['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div>
                <label class="block text-[11px] font-bold text-slate-600 mb-1">البند / التصنيف</label>
                <select name="category_id" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs font-bold focus:bg-white focus:outline-none">
                    <option value="">كل التصنيفات</option>
                    <?php foreach ($voucherCategories as $vc): ?>
                        <option value="<?= $vc['id'] ?>" <?= $filterCat === (int)$vc['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($vc['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-[11px] font-bold text-slate-600 mb-1">من تاريخ</label>
                <input type="date" name="from" value="<?= htmlspecialchars($filterFrom) ?>" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:outline-none">
            </div>

            <div>
                <label class="block text-[11px] font-bold text-slate-600 mb-1">إلى تاريخ</label>
                <input type="date" name="to" value="<?= htmlspecialchars($filterTo) ?>" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:outline-none">
            </div>

            <div>
                <label class="block text-[11px] font-bold text-slate-600 mb-1">بحث متقدم</label>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="بند، براند، رقم سند، مستفيد، مسجل المصروف..." class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:outline-none">
            </div>

            <div class="flex gap-2">
                <button type="submit" class="flex-1 bg-brand-600 hover:bg-brand-700 text-white font-bold py-2 px-3 rounded-xl text-xs transition shadow-xs">
                    <i class="fa-solid fa-filter ml-1"></i> تصفية
                </button>
                <a href="project_vouchers.php" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold py-2 px-3 rounded-xl text-xs transition flex items-center justify-center" title="إعادة تعيين">
                    <i class="fa-solid fa-rotate-right"></i>
                </a>
            </div>

            <!-- Exclude Land Checkbox Filter -->
            <div class="sm:col-span-2 lg:col-span-6 pt-2 border-t border-slate-100 flex items-center justify-between">
                <label class="inline-flex items-center space-x-2 space-x-reverse cursor-pointer text-xs font-bold text-slate-700 hover:text-amber-800 transition">
                    <input type="checkbox" name="exclude_land" value="1" <?= $excludeLand ? 'checked' : '' ?> onchange="this.form.submit()" class="w-4 h-4 text-amber-600 rounded border-slate-300 focus:ring-amber-500">
                    <span class="text-amber-950 font-bold">
                        <i class="fa-solid fa-filter ml-1 text-amber-600"></i>
                        استبعاد تكاليف شراء الأرض والسعي والضريبة (عرض سندات ومصروفات البناء والتنفيذ فقط)
                    </span>
                </label>
                <?php if ($excludeLand): ?>
                    <span class="text-[11px] text-emerald-800 font-bold bg-emerald-50 px-2.5 py-0.5 rounded-lg border border-emerald-200">
                        <i class="fa-solid fa-circle-check ml-1 text-emerald-600"></i> فلتر استبعاد الأرض نشط
                    </span>
                <?php endif; ?>
            </div>
        </form>
        </form>
    </div>

    <!-- Desktop Vouchers Table (Hidden on Mobile) -->
    <div class="hidden md:block bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="bg-slate-50 text-slate-500 font-bold border-b border-slate-200">
                    <tr>
                        <th class="p-3.5">رقم السند</th>
                        <th class="p-3.5">التاريخ</th>
                        <?php if (hasModule('projects')): ?>
                            <th class="p-3.5">المشروع / الجهة</th>
                        <?php endif; ?>
                        <th class="p-3.5">اسم المستفيد / الهوية</th>
                        <th class="p-3.5">البند والبيان</th>
                        <th class="p-3.5">المبلغ الأساسي</th>
                        <th class="p-3.5">الضريبة</th>
                        <th class="p-3.5">الإجمالي</th>
                        <th class="p-3.5 text-center">المرفق</th>
                        <th class="p-3.5 text-center">التوقيع الإلكتروني</th>
                        <th class="p-3.5 text-center">إجراءات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">
                    <?php if (empty($vouchers)): ?>
                        <tr>
                            <td colspan="<?= hasModule('projects') ? 11 : 10 ?>" class="p-12 text-center text-slate-400">
                                <i class="fa-solid fa-file-invoice text-4xl mb-3 text-slate-300 block"></i>
                                <p class="font-bold text-slate-600 mb-2">لا توجد سندات صرف أو فواتير مسجلة</p>
                                <p class="text-xs text-slate-400 mb-4">يمكنك إصدار أول سند صرف مالي بالضغط على الزر أدناه</p>
                                <a href="voucher_create.php" class="inline-flex items-center bg-rose-600 hover:bg-rose-700 text-white font-bold px-4 py-2 rounded-xl text-xs transition">
                                    <i class="fa-solid fa-plus-circle ml-1.5"></i> إصدار سند صرف جديد
                                </a>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($vouchers as $v): ?>
                            <?php 
                            $base = (float)$v['amount'] - (float)$v['tax_amount']; 
                            $vCurr = !empty($v['currency']) ? $v['currency'] : $currency;
                            ?>
                            <tr class="hover:bg-slate-50/70 transition">
                                <td class="p-3.5 whitespace-nowrap font-mono font-bold text-slate-900">
                                    <div class="flex items-center gap-1.5">
                                        <a href="voucher_share.php?id=<?= $v['id'] ?>" class="text-rose-600 hover:underline inline-flex items-center">
                                            <i class="fa-solid fa-file-invoice-dollar ml-1.5 text-xs text-rose-500"></i>
                                            <?= htmlspecialchars($v['voucher_number'] ?: ('VCH-' . $v['id'])) ?>
                                        </a>
                                        <?php if (!empty($v['is_edited'])): ?>
                                            <button type="button" onclick="openExpenseHistoryModal(<?= (int)$v['id'] ?>)" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-black bg-amber-100 hover:bg-amber-200 text-amber-900 border border-amber-300 transition shadow-2xs cursor-pointer" title="سند معدل - انقر لعرض سجل التعديل والرقابة (Equals)">
                                                <i class="fa-solid fa-pen-to-square text-amber-600 text-[9px]"></i> مُعدّلة
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($v['user_name'])): ?>
                                        <span class="text-[10px] text-slate-400 font-sans font-normal block mt-0.5" title="سجله المستخدم">
                                            <i class="fa-solid fa-user-pen ml-1 text-slate-400"></i><?= htmlspecialchars($v['user_name']) ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!empty($v['is_edited']) && !empty($v['editor_name'])): ?>
                                        <span class="text-[9px] text-amber-700 font-sans font-bold block mt-0.5">
                                            <i class="fa-solid fa-user-gear ml-0.5 text-amber-500"></i> عُدّلت بواسطة: <?= htmlspecialchars($v['editor_name']) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3.5 whitespace-nowrap text-slate-500">
                                    <?= date('Y-m-d', strtotime($v['expense_date'])) ?>
                                </td>
                                <?php if (hasModule('projects')): ?>
                                    <td class="p-3.5 whitespace-nowrap">
                                        <?php if (!empty($v['project_name'])): ?>
                                            <a href="project_view.php?id=<?= $v['project_id'] ?>" class="font-bold text-amber-700 hover:underline inline-flex items-center">
                                                <i class="fa-solid fa-helmet-safety ml-1 text-xs"></i>
                                                <?= htmlspecialchars($v['project_name']) ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-slate-100 text-slate-600">مصروف عام</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                                <td class="p-3.5">
                                    <strong class="text-slate-900 block"><?= htmlspecialchars($v['beneficiary_name'] ?: 'غير محدد') ?></strong>
                                    <?php if (!empty($v['beneficiary_id_number'])): ?>
                                        <span class="text-[10px] text-slate-400 font-mono">سجل/هوية: <?= htmlspecialchars($v['beneficiary_id_number']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3.5">
                                    <span class="font-bold text-slate-800 block"><?= htmlspecialchars($v['title']) ?></span>
                                    <?php if (!empty($v['category_name'])): ?>
                                        <span class="inline-block px-1.5 py-0.5 rounded text-[10px] font-bold bg-slate-100 text-slate-600 mt-0.5">
                                            <i class="fa-solid fa-tag ml-1 text-slate-400"></i><?= htmlspecialchars($v['category_name']) ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!empty($v['payment_method'])): ?>
                                        <?php
                                        $vpm = trim($v['payment_method']);
                                        $vpmBadge = match($vpm) {
                                            'تحويل بنكي' => ['bg' => 'bg-blue-50 text-blue-700 border-blue-200', 'icon' => 'fa-building-columns'],
                                            'كاش' => ['bg' => 'bg-amber-50 text-amber-800 border-amber-200', 'icon' => 'fa-wallet'],
                                            default => ['bg' => 'bg-emerald-50 text-emerald-800 border-emerald-200', 'icon' => 'fa-coins']
                                        };
                                        ?>
                                        <span class="inline-flex items-center mt-0.5 mr-1 px-1.5 py-0.5 rounded text-[10px] font-bold border <?= $vpmBadge['bg'] ?>" title="طريقة الخصم">
                                            <i class="fa-solid <?= $vpmBadge['icon'] ?> ml-1 text-[9px]"></i><?= htmlspecialchars($vpm) ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!empty($v['description'])): ?>
                                        <span class="text-[11px] text-slate-400 block truncate max-w-xs"><?= htmlspecialchars($v['description']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3.5 whitespace-nowrap font-bold text-slate-700">
                                    <?= formatMoney($base, $vCurr) ?>
                                </td>
                                <td class="p-3.5 whitespace-nowrap">
                                    <?php if ((float)$v['tax_rate'] > 0): ?>
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-50 text-amber-800 border border-amber-200">
                                            <?= round((float)$v['tax_rate']) ?>% (<?= formatMoney($v['tax_amount'], $vCurr) ?>)
                                        </span>
                                    <?php else: ?>
                                        <span class="text-slate-400 text-[11px]">بدون ضريبة</span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3.5 whitespace-nowrap font-black text-rose-600 text-sm">
                                    <?= formatMoney($v['amount'], $vCurr) ?>
                                    <div class="mt-0.5 space-y-1">
                                        <?php 
                                        $vSt = $v['status'] ?? 'deducted';
                                        ?>
                                        <?php if ($vSt === 'pending_approval'): ?>
                                            <span class="px-1.5 py-0.5 rounded text-[9px] font-black bg-amber-100 text-amber-900 border border-amber-300 block text-center">
                                                <i class="fa-solid fa-hourglass-half ml-0.5 text-amber-600"></i> بانتظار الاعتماد
                                            </span>
                                            <?php if ($canApprove): ?>
                                                <div class="flex items-center justify-center gap-1">
                                                    <button type="button" onclick="openApproveExpenseModal(<?= $v['id'] ?>, '<?= htmlspecialchars(addslashes($v['title'])) ?>', '<?= formatMoney($v['amount'], $vCurr) ?>', '<?= htmlspecialchars(addslashes($v['beneficiary_name'] ?? '')) ?>', '<?= htmlspecialchars(addslashes($v['project_name'] ?? '')) ?>')" class="bg-emerald-600 hover:bg-emerald-700 text-white px-2 py-0.5 rounded text-[9px] font-bold inline-flex items-center">
                                                        <i class="fa-solid fa-check ml-0.5"></i> اعتماد
                                                    </button>
                                                    <button type="button" onclick="openRejectExpenseModal(<?= $v['id'] ?>, '<?= htmlspecialchars(addslashes($v['title'])) ?>', '<?= formatMoney($v['amount'], $vCurr) ?>')" class="bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-300 px-2 py-0.5 rounded text-[9px] font-bold inline-flex items-center">
                                                        <i class="fa-solid fa-ban ml-0.5"></i> رفض
                                                    </button>
                                                </div>
                                            <?php endif; ?>
                                        <?php elseif ($vSt === 'rejected'): ?>
                                            <span class="px-1.5 py-0.5 rounded text-[9px] font-bold bg-rose-100 text-rose-800 border border-rose-300 block text-center">
                                                <i class="fa-solid fa-ban ml-0.5 text-rose-600"></i> مرفوض
                                            </span>
                                        <?php elseif ($vSt === 'pending_receipt'): ?>
                                            <span class="px-1.5 py-0.5 rounded text-[9px] font-bold bg-amber-100 text-amber-800 border border-amber-300 block text-center">
                                                معلق بانتظار الإيصال
                                            </span>
                                            <button type="button" onclick="openConfirmReceiptModal(<?= $v['id'] ?>, '<?= htmlspecialchars(addslashes($v['title'])) ?>', '<?= formatMoney($v['amount'], $vCurr) ?>', '<?= htmlspecialchars(addslashes($v['beneficiary_name'] ?? '')) ?>')" class="mt-1 bg-amber-600 hover:bg-amber-700 text-white px-2 py-0.5 rounded text-[9px] font-bold inline-flex items-center w-full justify-center">
                                                <i class="fa-solid fa-cloud-arrow-up ml-1 text-[8px]"></i> إرفاق الإيصال
                                            </button>
                                        <?php else: ?>
                                            <span class="px-1.5 py-0.5 rounded text-[9px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200 block text-center">
                                                مخصوم بالخزنة
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="p-3.5 whitespace-nowrap text-center">
                                    <?php 
                                    $rowAtts = $expenseAttachmentsGrouped[$v['id']] ?? [];
                                    if (empty($rowAtts) && !empty($v['invoice_attachment'])) {
                                        $cleanPath = ltrim($v['invoice_attachment'], '/');
                                        $rowAtts = [
                                            [
                                                'id' => 0,
                                                'file_path' => $cleanPath,
                                                'file_name' => basename($cleanPath),
                                                'file_ext' => strtolower(pathinfo($cleanPath, PATHINFO_EXTENSION))
                                            ]
                                        ];
                                    }
                                    $attCount = count($rowAtts);
                                    ?>
                                    <?php if ($attCount === 0): ?>
                                        <span class="text-slate-300">-</span>
                                    <?php elseif ($attCount === 1): ?>
                                        <a href="<?= htmlspecialchars($rowAtts[0]['file_path']) ?>" target="_blank" class="text-indigo-600 hover:text-indigo-800 p-1.5 rounded-lg bg-indigo-50 font-bold inline-block" title="<?= htmlspecialchars($rowAtts[0]['file_name']) ?>">
                                            <i class="fa-solid fa-paperclip text-sm"></i>
                                        </a>
                                    <?php else: ?>
                                        <button type="button" onclick='openEditExpenseModal(<?= json_encode($v, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>, <?= json_encode($rowAtts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>)' class="inline-flex items-center space-x-1 space-x-reverse px-2 py-0.5 rounded-lg text-xs font-bold bg-indigo-50 text-indigo-700 hover:bg-indigo-100 border border-indigo-200" title="عرض كافة الفواتير (<?= $attCount ?>)">
                                            <i class="fa-solid fa-layer-group text-[10px]"></i>
                                            <span><?= $attCount ?> فواتير</span>
                                        </button>
                                    <?php endif; ?>
                                </td>
                                <!-- Electronic Signature Status -->
                                <td class="p-3.5 whitespace-nowrap text-center">
                                    <?php if (($v['signature_status'] ?? '') === 'signed'): ?>
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-black bg-emerald-100 text-emerald-800 border border-emerald-200 shadow-2xs" title="تم توقيع واستلام المبلغ من المستفيد">
                                            <i class="fa-solid fa-signature ml-1 text-emerald-600"></i>
                                            موقّع ومستلم ✓
                                        </span>
                                    <?php else: ?>
                                        <a href="voucher_share.php?id=<?= $v['id'] ?>" class="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-bold bg-amber-50 text-amber-800 border border-amber-200 hover:bg-amber-100 transition shadow-2xs" title="اضغط لفتح رابط التوقيع ومشاركته">
                                            <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse ml-1.5"></span>
                                            بانتظار التوقيع
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3.5 whitespace-nowrap text-center">
                                    <div class="flex items-center justify-center space-x-1.5 space-x-reverse">
                                        <button type="button" 
                                                onclick='openEditExpenseModal(<?= json_encode($v, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>, <?= json_encode($rowAtts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>)' 
                                                class="inline-flex items-center bg-blue-50 hover:bg-blue-100 text-blue-700 border border-blue-200 font-bold px-2.5 py-1.5 rounded-xl text-xs transition" 
                                                title="تعديل هذا السند">
                                            <i class="fa-solid fa-pen-to-square ml-1 text-blue-600"></i> تعديل
                                        </button>
                                        <?php if (!empty($v['is_edited'])): ?>
                                            <button type="button" 
                                                    onclick="openExpenseHistoryModal(<?= (int)$v['id'] ?>)" 
                                                    class="inline-flex items-center bg-amber-50 hover:bg-amber-100 text-amber-800 border border-amber-200 font-bold px-2 py-1.5 rounded-xl text-xs transition" 
                                                    title="سجل التعديلات (Equals)">
                                                <i class="fa-solid fa-clock-rotate-left ml-1 text-amber-600"></i> السجل
                                            </button>
                                        <?php endif; ?>
                                        <a href="voucher_share.php?id=<?= $v['id'] ?>" class="inline-flex items-center bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 font-bold px-2.5 py-1.5 rounded-xl text-xs transition" title="رابط ومشاركة التوقيع الإلكتروني">
                                            <i class="fa-solid fa-share-nodes ml-1 text-rose-600"></i> رابط التوقيع
                                        </a>
                                        <a href="project_voucher_print.php?id=<?= $v['id'] ?>" target="_blank" class="inline-flex items-center bg-slate-900 hover:bg-slate-800 text-white font-bold px-3 py-1.5 rounded-xl text-xs transition shadow-xs" title="طباعة سند A4">
                                            <i class="fa-solid fa-print ml-1 text-slate-300"></i> طباعة A4
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Mobile Vouchers Cards View (Shown only on small screens) -->
    <div class="block md:hidden space-y-3">
        <?php if (empty($vouchers)): ?>
            <div class="bg-white rounded-2xl p-8 border border-slate-200/80 text-center shadow-xs">
                <div class="w-14 h-14 rounded-2xl bg-rose-50 text-rose-500 flex items-center justify-center text-2xl mx-auto mb-3">
                    <i class="fa-solid fa-file-invoice-dollar"></i>
                </div>
                <h3 class="font-bold text-slate-800 text-sm">لا توجد سندات صرف مسجلة</h3>
                <p class="text-xs text-slate-400 mt-1">يمكنك إصدار أول سند صرف مالي بالضغط أدناه</p>
                <a href="expense_create.php" class="mt-3 inline-flex items-center bg-rose-600 hover:bg-rose-700 text-white font-bold px-4 py-2 rounded-xl text-xs transition">
                    <i class="fa-solid fa-plus-circle ml-1.5"></i> إصدار سند صرف
                </a>
            </div>
        <?php else: ?>
            <div class="flex items-center justify-between px-1">
                <span class="text-xs font-bold text-slate-600">سندات الصرف المسجلة (<?= count($vouchers) ?>)</span>
                <span class="text-[11px] text-slate-400">مرتبة من الأحدث للأقدم</span>
            </div>

            <?php foreach ($vouchers as $v): 
                $base = (float)$v['amount'] - (float)$v['tax_amount'];
                $vCurr = !empty($v['currency']) ? $v['currency'] : $currency;
            ?>
                <div class="bg-white rounded-2xl p-4 border border-slate-200/90 shadow-xs active:scale-[0.99] transition space-y-3">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-black font-mono bg-rose-50 text-rose-700 border border-rose-200">
                                <i class="fa-solid fa-file-pdf ml-1.5 text-rose-500 text-[11px]"></i>
                                <?= htmlspecialchars($v['voucher_number'] ?: ('VCH-' . $v['id'])) ?>
                            </span>
                            <?php if (!empty($v['is_edited'])): ?>
                                <button type="button" onclick="openExpenseHistoryModal(<?= (int)$v['id'] ?>)" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-black bg-amber-100 text-amber-900 border border-amber-300 mr-1">
                                    <i class="fa-solid fa-pen-to-square text-amber-600 text-[9px]"></i> مُعدّلة
                                </button>
                            <?php endif; ?>
                            <span class="text-[11px] text-slate-400 mr-2"><?= date('Y-m-d', strtotime($v['expense_date'])) ?></span>
                        </div>
                        <div class="text-left">
                            <span class="text-base font-black text-rose-600 dir-ltr block">
                                -<?= formatMoney($v['amount'], $vCurr) ?>
                            </span>
                            <?php if ((float)$v['tax_rate'] > 0): ?>
                                <span class="text-[10px] text-amber-700 font-bold block">
                                    شامل ضريبة <?= formatMoney($v['tax_amount'], $vCurr) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div>
                        <div class="flex items-center space-x-1.5 space-x-reverse mb-1">
                            <i class="fa-solid fa-user-tag text-xs text-slate-400"></i>
                            <strong class="text-slate-900 text-xs font-extrabold"><?= htmlspecialchars($v['beneficiary_name'] ?: 'مستفيد غير محدد') ?></strong>
                            <?php if (hasModule('projects') && !empty($v['project_name'])): ?>
                                <span class="text-[10px] bg-amber-50 text-amber-800 border border-amber-200 px-2 py-0.5 rounded-full font-bold">
                                    <i class="fa-solid fa-city text-[9px] ml-0.5"></i> <?= htmlspecialchars($v['project_name']) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <h4 class="font-bold text-slate-800 text-xs leading-snug"><?= htmlspecialchars($v['title']) ?></h4>
                        <?php if (!empty($v['description'])): ?>
                            <p class="text-[11px] text-slate-500 mt-1 bg-slate-50 p-2 rounded-xl border border-slate-100"><?= htmlspecialchars($v['description']) ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="pt-3 border-t border-slate-100 flex flex-wrap items-center justify-between gap-2 text-xs">
                        <div>
                            <?php 
                            $mvSt = $v['status'] ?? 'deducted';
                            ?>
                            <?php if ($mvSt === 'pending_approval'): ?>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-black bg-amber-100 text-amber-900 border border-amber-300 inline-block">
                                    <i class="fa-solid fa-hourglass-half ml-1 text-amber-600"></i> بانتظار الاعتماد
                                </span>
                            <?php elseif ($mvSt === 'rejected'): ?>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-100 text-rose-800 border border-rose-300 inline-block">
                                    <i class="fa-solid fa-ban ml-0.5 text-rose-600"></i> مرفوض
                                </span>
                            <?php elseif ($mvSt === 'pending_receipt'): ?>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800 border border-amber-300 inline-block">
                                    معلق بانتظار الإيصال
                                </span>
                            <?php else: ?>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200 inline-block">
                                    مخصوم بالخزنة
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="flex items-center space-x-1.5 space-x-reverse">
                            <?php if ($mvSt === 'pending_approval' && $canApprove): ?>
                                <button type="button" onclick="openApproveExpenseModal(<?= $v['id'] ?>, '<?= htmlspecialchars(addslashes($v['title'])) ?>', '<?= formatMoney($v['amount'], $vCurr) ?>', '<?= htmlspecialchars(addslashes($v['beneficiary_name'] ?? '')) ?>', '<?= htmlspecialchars(addslashes($v['project_name'] ?? '')) ?>')" class="bg-emerald-600 hover:bg-emerald-700 text-white px-2.5 py-1 rounded-xl text-[10px] font-bold inline-flex items-center">
                                    <i class="fa-solid fa-check ml-1 text-[9px]"></i> اعتماد
                                </button>
                                <button type="button" onclick="openRejectExpenseModal(<?= $v['id'] ?>, '<?= htmlspecialchars(addslashes($v['title'])) ?>', '<?= formatMoney($v['amount'], $vCurr) ?>')" class="bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-300 px-2.5 py-1 rounded-xl text-[10px] font-bold inline-flex items-center">
                                    <i class="fa-solid fa-ban ml-1 text-[9px]"></i> رفض
                                </button>
                            <?php elseif ($mvSt === 'pending_receipt'): ?>
                                <button type="button" onclick="openConfirmReceiptModal(<?= $v['id'] ?>, '<?= htmlspecialchars(addslashes($v['title'])) ?>', '<?= formatMoney($v['amount'], $vCurr) ?>', '<?= htmlspecialchars(addslashes($v['beneficiary_name'] ?? '')) ?>')" class="bg-amber-600 hover:bg-amber-700 text-white px-2.5 py-1 rounded-xl text-[10px] font-bold inline-flex items-center">
                                    <i class="fa-solid fa-cloud-arrow-up ml-1 text-[9px]"></i> إرفاق الإيصال
                                </button>
                            <?php endif; ?>

                            <?php 
                            $mRowAtts = $expenseAttachmentsGrouped[$v['id']] ?? [];
                            if (empty($mRowAtts) && !empty($v['invoice_attachment'])) {
                                $cleanPath = ltrim($v['invoice_attachment'], '/');
                                $mRowAtts = [
                                    [
                                        'id' => 0,
                                        'file_path' => $cleanPath,
                                        'file_name' => basename($cleanPath),
                                        'file_ext' => strtolower(pathinfo($cleanPath, PATHINFO_EXTENSION))
                                    ]
                                ];
                            }
                            ?>
                            <?php if (!empty($mRowAtts)): ?>
                                <button type="button" onclick='openEditExpenseModal(<?= json_encode($v, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>, <?= json_encode($mRowAtts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>)' class="text-indigo-600 hover:text-indigo-800 p-1.5 rounded-lg bg-indigo-50 font-bold text-xs" title="الفواتير والمرفقات">
                                    <i class="fa-solid fa-paperclip"></i> <?= count($mRowAtts) > 1 ? count($mRowAtts) : '' ?>
                                </button>
                            <?php endif; ?>
                            <button type="button" onclick='openEditExpenseModal(<?= json_encode($v, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>, <?= json_encode($mRowAtts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>)' class="inline-flex items-center bg-blue-50 hover:bg-blue-100 text-blue-700 border border-blue-200 font-bold px-2 py-1.5 rounded-xl text-xs transition">
                                <i class="fa-solid fa-pen-to-square ml-1 text-blue-600"></i> تعديل
                            </button>
                            <a href="voucher_share.php?id=<?= $v['id'] ?>" class="inline-flex items-center bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 font-bold px-2.5 py-1.5 rounded-xl text-xs transition">
                                <i class="fa-solid fa-share-nodes ml-1 text-rose-600"></i> رابط التوقيع
                            </a>
                            <a href="project_voucher_print.php?id=<?= $v['id'] ?>" target="_blank" class="inline-flex items-center bg-slate-900 hover:bg-slate-800 text-white font-bold px-2.5 py-1.5 rounded-xl text-xs transition">
                                <i class="fa-solid fa-print ml-1 text-slate-300"></i> طباعة A4
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Modal: Edit Expense (تعديل سند الصرف / المصروف) -->
<div id="edit-project-expense-modal" class="modal-container fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4 overflow-y-auto">
    <div class="modal-backdrop fixed inset-0" onclick="closeEditExpenseModal()"></div>
    <div class="relative bg-white rounded-3xl shadow-2xl max-w-xl w-full p-6 sm:p-7 modal-animate-enter z-10 border border-slate-100 my-8">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
            <div class="flex items-center space-x-2.5 space-x-reverse">
                <div class="w-10 h-10 rounded-2xl bg-blue-600 text-white flex items-center justify-center font-bold text-lg shadow-md shadow-blue-600/20">
                    <i class="fa-solid fa-pen-to-square"></i>
                </div>
                <div>
                    <h3 class="font-black text-slate-900 text-lg">تعديل سند الصرف / الفاتورة</h3>
                    <p class="text-xs text-slate-400 font-mono mt-0.5" id="edit_exp_modal_subtitle">رقم السند: VCH-XXXX</p>
                </div>
            </div>
            <button type="button" onclick="closeEditExpenseModal()" class="text-slate-400 hover:text-slate-600 text-lg p-1.5">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form action="project_vouchers.php" method="POST" enctype="multipart/form-data" class="space-y-4">
            <?= getCSRFInput() ?>
            <input type="hidden" name="action" value="edit_expense">
            <input type="hidden" name="expense_id" id="edit_exp_id" value="">
            <input type="hidden" name="redirect_url" value="project_vouchers.php">
            <input type="hidden" name="currency" id="edit_exp_currency" value="<?= htmlspecialchars($currency) ?>">

            <!-- Notice: Audit Trail Warning -->
            <div class="p-3 bg-amber-50 border border-amber-200 rounded-2xl text-xs text-amber-900 flex items-start gap-2.5">
                <i class="fa-solid fa-shield-halved text-amber-600 text-base mt-0.5 shrink-0"></i>
                <div>
                    <strong class="block font-bold">نظام الرقابة والتدقيق (Equals) نشط:</strong>
                    <span>سيتم توثيق هذا التعديل في سجل النظام باسمك وتوقيتك وبيانات جهازك، وتحديث رصيد الخزنة تلقائياً.</span>
                </div>
            </div>

            <!-- Title & Category -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">بيان وسبب الصرف <span class="text-red-500">*</span></label>
                    <input type="text" name="title" id="edit_exp_title" required placeholder="مثال: سباكة، كهرباء، صيانة..." class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs font-bold focus:bg-white focus:ring-2 focus:ring-blue-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">التصنيف / البند</label>
                    <select name="category_id" id="edit_exp_category_id" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs font-bold focus:bg-white focus:ring-2 focus:ring-blue-500 focus:outline-none">
                        <option value="">مصروف عام (بدون تصنيف)</option>
                        <?php foreach ($vchCategories as $cat): ?>
                            <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Amount & VAT calculation -->
            <div class="p-3.5 bg-slate-50 rounded-2xl border border-slate-200 space-y-3">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">المبلغ الأساسي (قبل الضريبة) <span class="text-red-500">*</span></label>
                        <div class="relative">
                            <input type="number" step="0.01" min="0.01" id="edit_exp_amount" name="amount" required oninput="calcEditExpenseTotal()" class="w-full text-lg font-black bg-white border border-slate-300 rounded-xl px-3 py-1.5 text-blue-700 focus:ring-2 focus:ring-blue-500 focus:outline-none font-mono">
                            <span class="absolute left-3 top-2 font-bold text-slate-400 text-xs" id="edit_exp_currency_label"><?= $currency ?></span>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">تاريخ الصرف</label>
                        <input type="date" name="expense_date" id="edit_exp_date" required class="w-full bg-white border border-slate-300 rounded-xl px-3 py-2 text-xs font-bold focus:ring-2 focus:ring-blue-500 focus:outline-none">
                    </div>
                </div>

                <!-- VAT checkbox & live total preview -->
                <div class="flex items-center justify-between p-2.5 bg-white rounded-xl border border-slate-200">
                    <label class="flex items-center space-x-2 space-x-reverse cursor-pointer">
                        <input type="checkbox" id="edit_exp_apply_vat" name="apply_vat" value="1" onchange="calcEditExpenseTotal()" class="rounded text-blue-600 focus:ring-blue-500 w-4 h-4">
                        <span class="text-xs font-bold text-slate-800">تطبيق ضريبة القيمة المضافة (15% VAT)</span>
                    </label>
                    <span id="edit_exp_vat_preview" class="text-xs font-mono font-bold text-slate-400">+ 0.00</span>
                </div>

                <div class="flex items-center justify-between pt-1 border-t border-slate-200 text-xs">
                    <span class="font-bold text-slate-600">المجموع النهائي بعد التعديل:</span>
                    <strong id="edit_exp_total_preview" class="text-base font-black text-rose-600 font-mono">0.00 <?= $currency ?></strong>
                </div>
            </div>

            <!-- Beneficiary info -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">اسم المستفيد</label>
                    <input type="text" name="beneficiary_name" id="edit_exp_bname" placeholder="اسم المورد أو الفني" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-2.5 py-1.5 text-xs focus:bg-white focus:ring-2 focus:ring-blue-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">رقم الهوية / السجل</label>
                    <input type="text" name="beneficiary_id_number" id="edit_exp_bid" placeholder="رقم الهوية" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-2.5 py-1.5 text-xs font-mono focus:bg-white focus:ring-2 focus:ring-blue-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">رقم الجوال</label>
                    <input type="text" name="beneficiary_phone" id="edit_exp_bphone" placeholder="05XXXXXXXX" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-2.5 py-1.5 text-xs font-mono focus:bg-white focus:ring-2 focus:ring-blue-500 focus:outline-none">
                </div>
            </div>

            <!-- Payment method & Reason for edit -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">طريقة الخصم</label>
                    <select name="payment_method" id="edit_exp_pmethod" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-2.5 py-2 text-xs font-bold focus:bg-white focus:ring-2 focus:ring-blue-500 focus:outline-none">
                        <option value="نقدي">نقدي</option>
                        <option value="كاش">كاش</option>
                        <option value="تحويل بنكي">تحويل بنكي</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-amber-800 mb-1">سبب التعديل (لتوثيقه في سجل Equals) <span class="text-amber-600">*</span></label>
                    <input type="text" name="edit_reason" id="edit_exp_reason" required placeholder="مثلاً: تصحيح خطأ بالمبلغ، تحديث المورد..." class="w-full bg-amber-50/50 border border-amber-300 rounded-xl px-3 py-2 text-xs font-bold focus:bg-white focus:ring-2 focus:ring-amber-500 focus:outline-none">
                </div>
            </div>

            <!-- Description / Notes -->
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">ملاحظات وتفاصيل إضافية</label>
                <textarea name="description" id="edit_exp_desc" rows="2" placeholder="أي تفاصيل أو ملاحظات تخص الفاتورة..." class="w-full bg-slate-50 border border-slate-300 rounded-xl p-2.5 text-xs focus:bg-white focus:ring-2 focus:ring-blue-500 focus:outline-none"></textarea>
            </div>

            <!-- Attachments Management: View Existing, Delete, and Add More (Append) -->
            <div class="border border-slate-200 rounded-2xl p-3.5 bg-slate-50/70 space-y-3">
                <div class="flex items-center justify-between border-b border-slate-200/80 pb-2">
                    <label class="block text-xs font-bold text-slate-800 flex items-center">
                        <i class="fa-solid fa-receipt text-indigo-600 ml-1.5"></i>
                        فواتير ومستندات المصروف المرفقة
                    </label>
                    <span id="edit_exp_att_badge" class="px-2.5 py-0.5 rounded-full text-[10px] font-black bg-indigo-100 text-indigo-800 border border-indigo-200">
                        0 فواتير
                    </span>
                </div>

                <!-- Hidden inputs for deleted attachments -->
                <div id="edit_exp_deleted_inputs"></div>

                <!-- Existing Invoices List -->
                <div>
                    <span class="text-[11px] font-bold text-slate-600 block mb-1.5">
                        <i class="fa-solid fa-folder-open text-amber-500 ml-1"></i>
                        الفواتير المحفوظة حالياً (يمكنك حذف أي منها):
                    </span>
                    <div id="edit_exp_existing_list" class="grid grid-cols-1 sm:grid-cols-2 gap-2 max-h-48 overflow-y-auto p-0.5">
                        <!-- Populated dynamically via JS -->
                    </div>
                    <div id="edit_exp_no_existing" class="text-[11px] text-slate-400 italic py-2 text-center bg-white rounded-xl border border-dashed border-slate-200 hidden">
                        لا توجد أي فواتير مرفقة مسجلة لهذا المصروف حالياً.
                    </div>
                </div>

                <!-- Add New Invoices Section (Append without replacing) -->
                <div class="pt-2 border-t border-slate-200/80">
                    <span class="text-[11px] font-bold text-slate-700 block mb-1.5">
                        <i class="fa-solid fa-circle-plus text-emerald-600 ml-1"></i>
                        إضافة فواتير جديدة (تُضاف مع الفواتير السابقة دون استبدالها):
                    </span>
                    <div id="edit_exp_dropzone" onclick="document.getElementById('edit_exp_new_files_input').click()" class="border-2 border-dashed border-blue-200 hover:border-blue-400 bg-white rounded-xl p-3.5 text-center cursor-pointer transition hover:bg-blue-50/30">
                        <input type="file" id="edit_exp_new_files_input" name="invoice_files[]" multiple accept="image/*,.pdf" class="hidden">
                        <div class="flex flex-col items-center justify-center pointer-events-none">
                            <i class="fa-solid fa-cloud-arrow-up text-blue-500 text-2xl mb-1"></i>
                            <p class="text-xs font-bold text-slate-700">اضغط هنا لاختيار فواتير جديدة أو اسحبها وأفلتها</p>
                            <p class="text-[10px] text-slate-400 mt-0.5">يمكنك رفع عدة فواتير بصيغ (JPG, PNG, PDF)</p>
                        </div>
                    </div>

                    <!-- Selected New Files Preview List -->
                    <div id="edit_exp_new_preview" class="grid grid-cols-1 sm:grid-cols-2 gap-2 mt-2 hidden max-h-36 overflow-y-auto p-0.5">
                        <!-- New files selected appear here with preview & remove button -->
                    </div>
                </div>
            </div>

            <!-- Action buttons -->
            <div class="flex items-center space-x-2 space-x-reverse pt-2">
                <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-bold py-2.5 px-4 rounded-xl shadow-md transition text-xs sm:text-sm flex items-center justify-center">
                    <i class="fa-solid fa-floppy-disk ml-1.5"></i> حفظ التعديلات والتحديث المالي
                </button>
                <button type="button" onclick="closeEditExpenseModal()" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold py-2.5 px-4 rounded-xl transition text-xs sm:text-sm">
                    إلغاء
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Expense History (Equals Audit Trail) -->
<div id="expense-history-modal" class="modal-container fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4 overflow-y-auto">
    <div class="modal-backdrop fixed inset-0" onclick="closeExpenseHistoryModal()"></div>
    <div class="relative bg-white rounded-3xl shadow-2xl max-w-2xl w-full p-6 sm:p-7 z-10 border border-slate-100 modal-animate-enter my-8">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
            <div class="flex items-center space-x-3 space-x-reverse">
                <div class="w-10 h-10 rounded-2xl bg-amber-500 text-white flex items-center justify-center text-lg font-bold shadow-md shadow-amber-500/20">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                </div>
                <div>
                    <h3 class="font-black text-slate-900 text-lg">سجل رقابة وتعديلات السند (Equals)</h3>
                    <p id="hist_modal_subtitle" class="text-xs text-slate-500 font-mono mt-0.5">VCH-XXXX</p>
                </div>
            </div>
            <button type="button" onclick="closeExpenseHistoryModal()" class="text-slate-400 hover:text-slate-600 text-lg p-1.5">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- History Content (Loaded via AJAX) -->
        <div id="hist_modal_content" class="space-y-4 max-h-[65vh] overflow-y-auto pr-1">
            <div class="text-center py-10 text-slate-400">
                <i class="fa-solid fa-circle-notch fa-spin text-2xl mb-2 text-amber-500"></i>
                <p class="text-xs font-bold">جاري تحميل سجل العمليات والتدقيق...</p>
            </div>
        </div>

        <div class="pt-4 border-t border-slate-100 flex items-center justify-between">
            <a href="audit_logs.php" target="_blank" class="text-xs font-bold text-amber-700 hover:text-amber-800 flex items-center">
                <i class="fa-solid fa-arrow-up-right-from-square ml-1.5 text-[11px]"></i>
                فتح السجل العام للرقابة (Equals)
            </a>
            <button type="button" onclick="closeExpenseHistoryModal()" class="bg-slate-900 hover:bg-slate-800 text-white font-bold px-6 py-2 rounded-xl text-xs transition">
                إغلاق
            </button>
        </div>
    </div>
</div>

<script>
window.FIELD_NAMES_AR = {
    'amount': 'المبلغ الإجمالي',
    'title': 'بيان / سبب الصرف',
    'category_name': 'التصنيف / البند',
    'expense_date': 'تاريخ الصرف',
    'currency': 'العملة',
    'tax_amount': 'مبلغ الضريبة',
    'beneficiary_name': 'المستفيد',
    'beneficiary_id_number': 'رقم هوية المستفيد',
    'beneficiary_phone': 'رقم هاتف المستفيد',
    'payment_method': 'طريقة الدفع',
    'invoice_attachment': 'مرفق الفاتورة',
    'edit_reason': 'سبب التعديل'
};

let editExpExistingAttachments = [];
let editExpDeletedIds = new Set();
let editExpNewFilesData = [];

function openEditExpenseModal(exp, attachments) {
    const defaultCurr = '<?= addslashes($currency) ?>';
    document.getElementById('edit_exp_id').value = exp.id || '';
    document.getElementById('edit_exp_modal_subtitle').innerText = 'سند صرف رقم: ' + (exp.voucher_number || ('VCH-' + exp.id));
    document.getElementById('edit_exp_title').value = exp.title || '';
    document.getElementById('edit_exp_currency').value = exp.currency || defaultCurr;
    document.getElementById('edit_exp_currency_label').innerText = exp.currency || defaultCurr;
    
    // Base amount: if tax_amount exists, base = amount - tax_amount
    const totalAmt = parseFloat(exp.amount) || 0;
    const taxAmt = parseFloat(exp.tax_amount) || 0;
    const baseAmt = taxAmt > 0 ? (totalAmt - taxAmt) : totalAmt;
    document.getElementById('edit_exp_amount').value = baseAmt.toFixed(2);

    document.getElementById('edit_exp_date').value = exp.expense_date ? exp.expense_date.substring(0, 10) : '';
    document.getElementById('edit_exp_category_id').value = exp.category_id || '';
    document.getElementById('edit_exp_apply_vat').checked = taxAmt > 0;

    document.getElementById('edit_exp_bname').value = exp.beneficiary_name || '';
    document.getElementById('edit_exp_bid').value = exp.beneficiary_id_number || '';
    document.getElementById('edit_exp_bphone').value = exp.beneficiary_phone || '';
    document.getElementById('edit_exp_pmethod').value = exp.payment_method || 'نقدي';
    document.getElementById('edit_exp_desc').value = exp.description || '';
    document.getElementById('edit_exp_reason').value = exp.edit_reason || '';

    // Initialize attachments: clone passed array
    editExpExistingAttachments = Array.isArray(attachments) ? JSON.parse(JSON.stringify(attachments)) : [];
    if (editExpExistingAttachments.length === 0 && exp && exp.invoice_attachment) {
        const cleanPath = String(exp.invoice_attachment).replace(/^\/+/, '');
        const fname = cleanPath.split('/').pop() || 'فاتورة';
        const fext = fname.includes('.') ? fname.split('.').pop().toLowerCase() : '';
        editExpExistingAttachments.push({
            id: 0,
            file_path: cleanPath,
            file_name: fname,
            file_ext: fext
        });
    }

    editExpDeletedIds.clear();
    editExpNewFilesData = [];
    const input = document.getElementById('edit_exp_new_files_input');
    if (input) input.value = '';

    renderEditExpExistingList();
    renderEditExpNewFiles();

    // Async check to guarantee we fetch all attached invoices even if table row had partial data
    if (exp && exp.id) {
        fetch('ajax_expense_attachments.php?expense_id=' + exp.id)
            .then(res => res.json())
            .then(data => {
                if (data && data.success && Array.isArray(data.attachments)) {
                    if (data.attachments.length > 0 || editExpExistingAttachments.length === 0) {
                        editExpExistingAttachments = data.attachments;
                        renderEditExpExistingList();
                    }
                }
            })
            .catch(() => {});
    }

    calcEditExpenseTotal();
    openModal('edit-project-expense-modal');
}

function renderEditExpExistingList() {
    const listEl = document.getElementById('edit_exp_existing_list');
    const noEl = document.getElementById('edit_exp_no_existing');
    const badgeEl = document.getElementById('edit_exp_att_badge');
    const delInputs = document.getElementById('edit_exp_deleted_inputs');

    if (!listEl) return;
    listEl.innerHTML = '';
    if (delInputs) delInputs.innerHTML = '';

    const activeCount = editExpExistingAttachments.filter(a => !editExpDeletedIds.has(a.id)).length;
    if (badgeEl) badgeEl.innerText = activeCount + ' فواتير';

    if (editExpExistingAttachments.length === 0) {
        if (noEl) noEl.classList.remove('hidden');
        return;
    }
    if (noEl) noEl.classList.add('hidden');

    if (delInputs) {
        editExpDeletedIds.forEach(id => {
            const hid = document.createElement('input');
            hid.type = 'hidden';
            hid.name = 'delete_attachment_ids[]';
            hid.value = id;
            delInputs.appendChild(hid);
        });
    }

    editExpExistingAttachments.forEach(att => {
        const isDeleted = editExpDeletedIds.has(att.id);
        const isImg = ['jpg', 'jpeg', 'png', 'webp', 'gif'].includes((att.file_ext || '').toLowerCase());
        const card = document.createElement('div');
        card.className = `flex items-center justify-between p-2 rounded-xl border text-xs transition ${
            isDeleted 
                ? 'bg-rose-50/80 border-rose-300 opacity-70 line-through text-rose-800' 
                : 'bg-white border-slate-200 text-slate-800 shadow-2xs hover:border-slate-300'
        }`;

        const leftSide = document.createElement('div');
        leftSide.className = 'flex items-center space-x-2 space-x-reverse min-w-0 flex-1';

        if (isImg) {
            leftSide.innerHTML = `
                <img src="${att.file_path}" alt="فاتورة" class="w-8 h-8 rounded-lg object-cover border border-slate-200 shrink-0">
                <div class="min-w-0 flex-1">
                    <p class="font-bold truncate text-[11px]" title="${att.file_name}">${att.file_name}</p>
                    <span class="text-[9px] text-slate-400 font-mono block uppercase">${att.file_ext || 'IMG'}</span>
                </div>
            `;
        } else {
            leftSide.innerHTML = `
                <div class="w-8 h-8 rounded-lg bg-rose-50 text-rose-600 flex items-center justify-center text-sm shrink-0 border border-rose-100">
                    <i class="fa-solid fa-file-pdf"></i>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="font-bold truncate text-[11px]" title="${att.file_name}">${att.file_name}</p>
                    <span class="text-[9px] text-slate-400 font-mono block uppercase">${att.file_ext || 'PDF'}</span>
                </div>
            `;
        }

        const actions = document.createElement('div');
        actions.className = 'flex items-center space-x-1 space-x-reverse shrink-0 mr-1.5';

        if (!isDeleted) {
            const previewBtn = document.createElement('a');
            previewBtn.href = att.file_path;
            previewBtn.target = '_blank';
            previewBtn.className = 'p-1.5 text-indigo-600 hover:text-indigo-800 hover:bg-indigo-50 rounded-lg text-xs transition';
            previewBtn.title = 'معاينة الفاتورة';
            previewBtn.innerHTML = '<i class="fa-solid fa-arrow-up-right-from-square"></i>';
            actions.appendChild(previewBtn);
        }

        const toggleBtn = document.createElement('button');
        toggleBtn.type = 'button';
        if (isDeleted) {
            toggleBtn.className = 'px-2 py-1 text-[10px] font-bold text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-100 shadow-2xs transition';
            toggleBtn.innerText = 'تراجع';
            toggleBtn.onclick = () => {
                editExpDeletedIds.delete(att.id);
                renderEditExpExistingList();
            };
        } else {
            toggleBtn.className = 'p-1.5 text-rose-500 hover:text-rose-700 hover:bg-rose-50 rounded-lg text-xs transition';
            toggleBtn.title = 'حذف هذه الفاتورة';
            toggleBtn.innerHTML = '<i class="fa-solid fa-trash-can"></i>';
            toggleBtn.onclick = () => {
                editExpDeletedIds.add(att.id);
                renderEditExpExistingList();
            };
        }
        actions.appendChild(toggleBtn);

        card.appendChild(leftSide);
        card.appendChild(actions);
        listEl.appendChild(card);
    });
}

function handleEditExpFilesSelected(files) {
    if (!files || files.length === 0) return;
    for (let i = 0; i < files.length; i++) {
        editExpNewFilesData.push(files[i]);
    }
    syncEditExpNewFilesInput();
    renderEditExpNewFiles();
}

function syncEditExpNewFilesInput() {
    const input = document.getElementById('edit_exp_new_files_input');
    if (!input) return;
    try {
        const dt = new DataTransfer();
        editExpNewFilesData.forEach(file => dt.items.add(file));
        input.files = dt.files;
    } catch(e) {
        // Fallback
    }
}

function removeEditExpNewFile(idx) {
    editExpNewFilesData.splice(idx, 1);
    syncEditExpNewFilesInput();
    renderEditExpNewFiles();
}

function renderEditExpNewFiles() {
    const previewContainer = document.getElementById('edit_exp_new_preview');
    if (!previewContainer) return;
    previewContainer.innerHTML = '';

    if (editExpNewFilesData.length === 0) {
        previewContainer.classList.add('hidden');
        return;
    }
    previewContainer.classList.remove('hidden');

    editExpNewFilesData.forEach((file, idx) => {
        const isImg = file.type.startsWith('image/');
        const card = document.createElement('div');
        card.className = 'flex items-center justify-between p-2 rounded-xl border border-emerald-200 bg-emerald-50/70 text-xs shadow-2xs';

        const leftSide = document.createElement('div');
        leftSide.className = 'flex items-center space-x-2 space-x-reverse min-w-0 flex-1';

        const thumb = document.createElement('div');
        thumb.className = 'w-8 h-8 rounded-lg bg-emerald-100 text-emerald-700 flex items-center justify-center shrink-0 overflow-hidden text-sm';
        if (isImg) {
            const img = document.createElement('img');
            img.className = 'w-full h-full object-cover';
            img.src = URL.createObjectURL(file);
            thumb.appendChild(img);
        } else {
            thumb.innerHTML = '<i class="fa-solid fa-file-pdf text-red-500"></i>';
        }

        const info = document.createElement('div');
        info.className = 'min-w-0 flex-1';
        info.innerHTML = `
            <p class="font-bold truncate text-[11px] text-emerald-950" title="${file.name}">${file.name}</p>
            <span class="text-[9px] text-emerald-700 font-mono block">جديد (+ ${(file.size / 1024).toFixed(0)} KB)</span>
        `;

        leftSide.appendChild(thumb);
        leftSide.appendChild(info);

        const rmBtn = document.createElement('button');
        rmBtn.type = 'button';
        rmBtn.className = 'p-1.5 text-rose-500 hover:text-rose-700 hover:bg-rose-100 rounded-lg text-xs shrink-0 mr-1 transition';
        rmBtn.title = 'إلغاء هذا الملف الجديد';
        rmBtn.innerHTML = '<i class="fa-solid fa-xmark"></i>';
        rmBtn.onclick = () => removeEditExpNewFile(idx);

        card.appendChild(leftSide);
        card.appendChild(rmBtn);
        previewContainer.appendChild(card);
    });
}

document.addEventListener('DOMContentLoaded', function() {
    const editDropzone = document.getElementById('edit_exp_dropzone');
    const editFileInput = document.getElementById('edit_exp_new_files_input');
    if (editFileInput) {
        editFileInput.addEventListener('change', function(e) {
            handleEditExpFilesSelected(e.target.files);
        });
    }
    if (editDropzone) {
        ['dragenter', 'dragover'].forEach(evt => {
            editDropzone.addEventListener(evt, function(e) {
                e.preventDefault();
                e.stopPropagation();
                editDropzone.classList.add('border-blue-500', 'bg-blue-50/50');
            });
        });
        ['dragleave', 'drop'].forEach(evt => {
            editDropzone.addEventListener(evt, function(e) {
                e.preventDefault();
                e.stopPropagation();
                editDropzone.classList.remove('border-blue-500', 'bg-blue-50/50');
            });
        });
        editDropzone.addEventListener('drop', function(e) {
            const files = e.dataTransfer ? e.dataTransfer.files : [];
            if (files && files.length > 0) {
                handleEditExpFilesSelected(files);
            }
        });
    }
});

function closeEditExpenseModal() {
    closeModal('edit-project-expense-modal');
}

function calcEditExpenseTotal() {
    const amtInput = document.getElementById('edit_exp_amount');
    const isVat = document.getElementById('edit_exp_apply_vat').checked;
    const curr = document.getElementById('edit_exp_currency').value || 'ر.س';
    const vatRate = (curr === 'ج.م') ? 14.0 : 15.0;

    const base = parseFloat(amtInput.value) || 0;
    const vat = isVat ? Math.round(base * (vatRate / 100) * 100) / 100 : 0;
    const total = base + vat;

    document.getElementById('edit_exp_vat_preview').innerText = isVat ? `+ ${vat.toFixed(2)} (${vatRate}% VAT)` : `بدون ضريبة (0.00)`;
    document.getElementById('edit_exp_total_preview').innerText = `${total.toFixed(2)} ${curr}`;
}

function openExpenseHistoryModal(expenseId) {
    const content = document.getElementById('hist_modal_content');
    const sub = document.getElementById('hist_modal_subtitle');
    
    sub.innerText = 'سند صرف رقم: #' + expenseId;
    content.innerHTML = `
        <div class="text-center py-10 text-slate-400">
            <i class="fa-solid fa-circle-notch fa-spin text-2xl mb-2 text-amber-500"></i>
            <p class="text-xs font-bold">جاري تحميل سجل العمليات والتدقيق...</p>
        </div>
    `;

    openModal('expense-history-modal');

    fetch('ajax_expense_history.php?id=' + expenseId)
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                content.innerHTML = `<div class="p-4 bg-rose-50 text-rose-700 rounded-2xl text-xs font-bold">${data.error || 'فشل تحميل السجل'}</div>`;
                return;
            }

            sub.innerText = 'سند: ' + data.expense.voucher_number + ' - ' + data.expense.title;
            
            if (!data.logs || data.logs.length === 0) {
                content.innerHTML = `
                    <div class="text-center py-8 text-slate-400">
                        <i class="fa-solid fa-file-circle-check text-3xl mb-2 text-slate-300"></i>
                        <p class="text-xs font-bold">لا توجد تعديلات مسجلة على هذا السند بعد. تم إصداره بحالته الأصلية.</p>
                    </div>
                `;
                return;
            }

            let html = '';
            data.logs.forEach(log => {
                const isEdit = log.action === 'edit';
                const actionBadgeClass = isEdit ? 'bg-amber-100 text-amber-900 border-amber-300' : 'bg-emerald-100 text-emerald-900 border-emerald-300';
                const actionName = isEdit ? 'تعديل وتصحيح' : 'إصدار أصلي';

                html += `
                    <div class="p-4 bg-slate-50 rounded-2xl border border-slate-200 space-y-2.5">
                        <div class="flex items-center justify-between">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-black ${actionBadgeClass} border">
                                <i class="fa-solid ${isEdit ? 'fa-pen-to-square' : 'fa-plus'} ml-1 text-[9px]"></i>
                                ${actionName}
                            </span>
                            <span class="text-[11px] font-mono text-slate-400 font-bold">
                                <i class="fa-regular fa-clock ml-1"></i> ${log.created_at_formatted}
                            </span>
                        </div>

                        <div class="text-xs text-slate-800 leading-relaxed font-bold">
                            ${log.summary}
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-[11px] text-slate-500 pt-2 border-t border-slate-200/60">
                            <div>
                                <i class="fa-solid fa-user-pen ml-1 text-slate-400"></i> المنفذ: <strong class="text-slate-800">${log.user_name}</strong> (${log.user_role})
                            </div>
                            <div>
                                <i class="fa-solid fa-laptop ml-1 text-slate-400"></i> الجهاز: <strong class="text-slate-700">${log.device_info}</strong>
                            </div>
                            <div class="col-span-full">
                                <i class="fa-solid fa-network-wired ml-1 text-slate-400"></i> عنوان IP: <span class="font-mono text-slate-600">${log.ip_address}</span>
                            </div>
                        </div>
                `;

                if (log.old_values && log.new_values) {
                    html += `
                        <div class="mt-2 pt-2 border-t border-slate-200/60">
                            <span class="text-[10px] font-bold text-slate-600 block mb-1">جدول المقارنة والفروقات:</span>
                            <table class="w-full text-right text-[11px] border border-slate-200 rounded-xl overflow-hidden">
                                <thead>
                                    <tr class="bg-slate-200/60 text-slate-700">
                                        <th class="p-1.5 font-bold">الحقل</th>
                                        <th class="p-1.5 bg-rose-50 text-rose-800 font-bold">السابق</th>
                                        <th class="p-1.5 bg-emerald-50 text-emerald-800 font-bold">الجديد</th>
                                    </tr>
                                </thead>
                                <tbody>
                    `;
                    const allKeys = Array.from(new Set([...Object.keys(log.old_values), ...Object.keys(log.new_values)]));
                    allKeys.forEach(k => {
                        const oVal = log.old_values[k] !== undefined && log.old_values[k] !== null ? String(log.old_values[k]) : '—';
                        const nVal = log.new_values[k] !== undefined && log.new_values[k] !== null ? String(log.new_values[k]) : '—';
                        if (oVal !== nVal) {
                            const lbl = (window.FIELD_NAMES_AR && window.FIELD_NAMES_AR[k]) ? window.FIELD_NAMES_AR[k] : k;
                            html += `
                                <tr class="border-t border-slate-100 bg-white">
                                    <td class="p-1.5 font-bold text-slate-700">${lbl}</td>
                                    <td class="p-1.5 bg-rose-50/50 text-rose-900 font-mono">${oVal}</td>
                                    <td class="p-1.5 bg-emerald-50/50 text-emerald-900 font-mono font-bold">${nVal}</td>
                                </tr>
                            `;
                        }
                    });
                    html += `
                                </tbody>
                            </table>
                        </div>
                    `;
                }

                html += `</div>`;
            });

            content.innerHTML = html;
        })
        .catch(() => {
            content.innerHTML = `<div class="p-4 bg-rose-50 text-rose-700 rounded-2xl text-xs font-bold">حدث خطأ أثناء الاتصال بالسيرفر.</div>`;
        });
}

function closeExpenseHistoryModal() {
    closeModal('expense-history-modal');
}

function openNewVoucherModal() {
    window.location.href = 'voucher_create.php';
}
</script>

<?php include_once __DIR__ . '/includes/footer.php'; ?>
