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
if ($activeCompanyId <= 0 && isSuperAdmin()) {
    $firstComp = $db->query("SELECT id FROM companies WHERE is_active = 1 LIMIT 1")->fetch();
    if ($firstComp) {
        $activeCompanyId = (int)$firstComp['id'];
    }
}
$currency = $currentUser['currency'] ?? 'ج.م';
$db = getDBConnection();

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

            // Handle invoice attachment upload
            $attachmentPath = null;
            if (isset($_FILES['invoice_file']) && $_FILES['invoice_file']['error'] === UPLOAD_ERR_OK) {
                $fileTmp = $_FILES['invoice_file']['tmp_name'];
                $fileName = $_FILES['invoice_file']['name'];
                $fileSize = $_FILES['invoice_file']['size'];
                $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

                $allowed = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
                if (!in_array($ext, $allowed)) {
                    throw new Exception('نوع الملف المرفق غير مدعوم. الصيغ المدعومة: JPG, PNG, WEBP, PDF');
                }
                if ($fileSize > 15 * 1024 * 1024) {
                    throw new Exception('حجم ملف الفاتورة كبير جداً، الحد الأقصى 15 ميجابايت.');
                }

                $uploadDir = APP_PATH . '/assets/uploads/invoices/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                $newFileName = 'vch_' . $activeCompanyId . '_' . time() . '_' . rand(100, 999) . '.' . $ext;
                if (move_uploaded_file($fileTmp, $uploadDir . $newFileName)) {
                    $attachmentPath = 'assets/uploads/invoices/' . $newFileName;
                }
            }

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
    if (isset($_FILES['invoice_file']) && $_FILES['invoice_file']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['invoice_file']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'pdf'])) {
            $uploadDir = APP_PATH . '/assets/uploads/invoices/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            $newFileName = 'vch_conf_' . time() . '_' . rand(100, 999) . '.' . $ext;
            if (move_uploaded_file($_FILES['invoice_file']['tmp_name'], $uploadDir . $newFileName)) {
                $attachmentPath = 'assets/uploads/invoices/' . $newFileName;
            }
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
           ec.name as category_name,
           st.balance_before,
           st.balance_after
    FROM expenses e
    LEFT JOIN projects p ON e.project_id = p.id
    LEFT JOIN safes s ON e.safe_id = s.id
    LEFT JOIN users u ON e.user_id = u.id
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
                                    <a href="voucher_share.php?id=<?= $v['id'] ?>" class="text-rose-600 hover:underline inline-flex items-center">
                                        <i class="fa-solid fa-file-invoice-dollar ml-1.5 text-xs text-rose-500"></i>
                                        <?= htmlspecialchars($v['voucher_number'] ?: ('VCH-' . $v['id'])) ?>
                                    </a>
                                    <?php if (!empty($v['user_name'])): ?>
                                        <span class="text-[10px] text-slate-400 font-sans font-normal block mt-0.5" title="سجله المستخدم">
                                            <i class="fa-solid fa-user-pen ml-1 text-slate-400"></i><?= htmlspecialchars($v['user_name']) ?>
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
                                    <?php if (!empty($v['invoice_attachment']) && file_exists(APP_PATH . '/' . $v['invoice_attachment'])): ?>
                                        <a href="<?= htmlspecialchars($v['invoice_attachment']) ?>" target="_blank" class="text-indigo-600 hover:text-indigo-800 p-1.5 rounded-lg bg-indigo-50 font-bold inline-block" title="عرض فاتورة الشراء الأصلية">
                                            <i class="fa-solid fa-paperclip text-sm"></i>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-slate-300">-</span>
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

                            <?php if (!empty($v['invoice_attachment']) && file_exists(APP_PATH . '/' . $v['invoice_attachment'])): ?>
                                <a href="<?= htmlspecialchars($v['invoice_attachment']) ?>" target="_blank" class="text-indigo-600 hover:text-indigo-800 p-1.5 rounded-lg bg-indigo-50 font-bold text-xs" title="معاينة المرفق">
                                    <i class="fa-solid fa-paperclip"></i>
                                </a>
                            <?php endif; ?>
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

<script>
function openNewVoucherModal() {
    window.location.href = 'voucher_create.php';
}
</script>

<?php include_once __DIR__ . '/includes/footer.php'; ?>
