<?php
require_once __DIR__ . '/config/app.php';
requireLogin();
requireModule('expenses');
requirePermission('expenses');

$currentUser = getCurrentUser();
$db = getDBConnection();
$activeCompanyId = (int)($currentUser['active_company_id'] ?? 0);
if ($activeCompanyId <= 0 && isSuperAdmin()) {
    $firstComp = $db->query("SELECT id FROM companies WHERE is_active = 1 LIMIT 1")->fetch();
    if ($firstComp) {
        $activeCompanyId = (int)$firstComp['id'];
    }
}
$currency = $currentUser['currency'] ?? 'ج.م';
$safe = getCompanyMainSafe($activeCompanyId);

// Handle POST: Create Expense
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireNotViewOnly();
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('خطأ في التحقق من صحة الطلب');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create_expense') {
        $amount = (float)($_POST['amount'] ?? 0);
        $expCurrency = trim($_POST['currency'] ?? $currency);
        $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $newCategoryName = trim($_POST['new_category_name'] ?? '');
        if (empty($categoryId) && !empty($newCategoryName)) {
            $createdCatId = getOrCreateExpenseCategory($activeCompanyId, $newCategoryName);
            if ($createdCatId) {
                $categoryId = $createdCatId;
            }
        }
        $projectId = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $expenseDate = trim($_POST['expense_date'] ?? date('Y-m-d'));
        $applyVat = isset($_POST['apply_vat']) && $_POST['apply_vat'] === '1';
        $beneficiaryName = trim($_POST['beneficiary_name'] ?? '');
        $beneficiaryId = trim($_POST['beneficiary_id_number'] ?? '');
        $beneficiaryPhone = trim($_POST['beneficiary_phone'] ?? '');

        if ($amount <= 0 || empty($title)) {
            setFlash('error', 'يرجى إدخال المبلغ وعنوان/سبب المصروف.');
        } else {
            // Determine Safe
            $targetSafeId = $safe['id'];
            if ($projectId > 0) {
                $pSafe = getProjectSafe($activeCompanyId, $projectId);
                $targetSafeId = $pSafe['id'];
            } elseif (!empty($_POST['safe_id'])) {
                $targetSafeId = (int)$_POST['safe_id'];
            }

            // VAT calculation
            $taxRate = $applyVat ? 15.00 : 0.00;
            $taxAmount = $applyVat ? round($amount * 0.15, 2) : 0.00;
            $totalAmount = $amount + $taxAmount;

            // Invoice Attachment (Single or Multiple)
            $attachmentPath = null;
            $uploadedFilesPayload = !empty($_FILES['invoice_files']['name']) ? $_FILES['invoice_files'] : (!empty($_FILES['invoice_file']['name']) ? $_FILES['invoice_file'] : null);

            // Get category name
            $catName = 'مصروف عام';
            if ($categoryId) {
                $cStmt = $db->prepare("SELECT name FROM expense_categories WHERE id = :id AND company_id = :cid");
                $cStmt->execute([':id' => $categoryId, ':cid' => $activeCompanyId]);
                $catData = $cStmt->fetch();
                if ($catData) {
                    $catName = $catData['name'];
                }
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

            $voucherNo = generateVoucherNumber($activeCompanyId);
            $safeTxId = null;
            $approvedBy = null;
            $approvedAt = null;

            try {
                if ($expenseStatus === 'deducted') {
                    // Deduct from safe atomically only when approved
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

                // 2. Insert into expenses table
                $insExp = $db->prepare("
                    INSERT INTO expenses (
                        company_id, category_id, safe_id, safe_transaction_id, user_id, project_id,
                        amount, currency, title, description, expense_date,
                        invoice_attachment, tax_rate, tax_amount, beneficiary_name, beneficiary_id_number, beneficiary_phone, voucher_number,
                        status, approved_by, approved_at,
                        sign_token, issuer_signature, signature_status,
                        created_at
                    ) VALUES (
                        :cid, :catid, :sid, :txid, :uid, :pid,
                        :amt, :curr, :title, :desc, :edate,
                        :att, :trate, :tamt, :bname, :bid, :bphone, :vnum,
                        :status, :app_by, :app_at,
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
                    ':app_by' => $approvedBy,
                    ':app_at' => $approvedAt,
                    ':stok' => $signToken,
                    ':isig' => $issuerSig,
                    ':now' => date('Y-m-d H:i:s')
                ]);
                $newExpId = (int)$db->lastInsertId();

                if (!empty($uploadedFilesPayload)) {
                    saveExpenseUploadedFiles($newExpId, $activeCompanyId, $uploadedFilesPayload, $projectId);
                }

                if ($expenseStatus === 'pending_approval') {
                    setFlash('warning', "تم تقديم طلب المصروف بمبلغ " . formatMoney($totalAmount, $expCurrency) . " بنجاح، وهو الآن بانتظار موافقة الإدارة (لم يُخصم من الخزنة ولم يُضف للمشروع حتى يتم اعتماده). رقم السند: {$voucherNo}");
                } elseif ($expenseStatus === 'pending_receipt') {
                    setFlash('warning', "تم تسجيل المصروف كـ (معلق بانتظار إرفاق الإيصال) دون خصم مبالغ من الخزنة حالياً. رقم السند: {$voucherNo}");
                } else {
                    setFlash('success', "تم تسجيل صرف مبلغ " . formatMoney($totalAmount, $expCurrency) . " وخصمه من الخزنة بنجاح! رقم السند: {$voucherNo}");
                }
                header('Location: expenses.php');
                exit;
            } catch (Exception $e) {
                setFlash('error', $e->getMessage());
            }
        }
    }

    // Action: Approve Expense (High Privilege Admin Action)
    elseif ($action === 'approve_expense') {
        if (!canApproveExpenses()) {
            setFlash('error', 'عفواً، ليس لديك صلاحية اعتماد والموافقة على المصروفات.');
            header('Location: expenses.php');
            exit;
        }

        $expenseId = (int)($_POST['expense_id'] ?? 0);
        $redirectUrl = trim($_POST['redirect_url'] ?? 'expenses.php');

        $stmt = $db->prepare("SELECT * FROM expenses WHERE id = :id AND company_id = :cid");
        $stmt->execute([':id' => $expenseId, ':cid' => $activeCompanyId]);
        $exp = $stmt->fetch();

        if (!$exp) {
            setFlash('error', 'المصروف غير موجود أو تم حذفه.');
            header('Location: ' . $redirectUrl);
            exit;
        }

        if (in_array($exp['status'], ['deducted', 'approved'])) {
            setFlash('warning', 'هذا المصروف معتمد ومخصوم مسبقاً.');
            header('Location: ' . $redirectUrl);
            exit;
        }

        try {
            // Determine Safe
            $targetSafeId = (int)$exp['safe_id'];
            if (empty($targetSafeId)) {
                if (!empty($exp['project_id'])) {
                    $pSafe = getProjectSafe($activeCompanyId, (int)$exp['project_id']);
                    $targetSafeId = $pSafe['id'];
                } else {
                    $targetSafeId = $safe['id'];
                }
            }

            $expCurrency = !empty($exp['currency']) ? $exp['currency'] : $currency;
            $totalAmount = (float)$exp['amount'];
            $finalTitle = $exp['title'] . ($exp['beneficiary_name'] ? " | المستفيد: " . $exp['beneficiary_name'] : "");
            if ($expCurrency !== $currency && strpos($finalTitle, $expCurrency) === false) {
                $finalTitle .= " (العملة: " . $expCurrency . ")";
            }

            // Deduct from safe atomically
            $txResult = recordSafeTransaction(
                $activeCompanyId,
                $targetSafeId,
                $currentUser['id'],
                'expense',
                $exp['title'],
                $totalAmount,
                $finalTitle,
                $exp['expense_date'] ?: date('Y-m-d'),
                $expCurrency,
                $exp['project_id']
            );

            // Update expense status to approved
            $upd = $db->prepare("
                UPDATE expenses
                SET status = 'approved',
                    safe_id = :sid,
                    safe_transaction_id = :txid,
                    approved_by = :uid,
                    approved_at = :now,
                    rejected_by = NULL,
                    rejected_at = NULL,
                    rejection_reason = NULL
                WHERE id = :id AND company_id = :cid
            ");
            $upd->execute([
                ':sid' => $targetSafeId,
                ':txid' => $txResult['transaction_id'],
                ':uid' => $currentUser['id'],
                ':now' => date('Y-m-d H:i:s'),
                ':id' => $expenseId,
                ':cid' => $activeCompanyId
            ]);

            setFlash('success', 'تم اعتماد المصروف بنجاح وخصم مبلغ ' . formatMoney($totalAmount, $expCurrency) . ' من الخزنة وإدراجه في حسابات وتكاليف المشروع!');
            header('Location: ' . $redirectUrl);
            exit;
        } catch (Exception $e) {
            setFlash('error', 'حدث خطأ أثناء اعتماد المصروف: ' . $e->getMessage());
            header('Location: ' . $redirectUrl);
            exit;
        }
    }

    // Action: Reject Expense
    elseif ($action === 'reject_expense') {
        if (!canApproveExpenses()) {
            setFlash('error', 'عفواً، ليس لديك صلاحية رفض المصروفات.');
            header('Location: expenses.php');
            exit;
        }

        $expenseId = (int)($_POST['expense_id'] ?? 0);
        $redirectUrl = trim($_POST['redirect_url'] ?? 'expenses.php');
        $reason = trim($_POST['rejection_reason'] ?? '');

        if (empty($reason)) {
            setFlash('error', 'يرجى كتابة سبب رفض المصروف.');
            header('Location: ' . $redirectUrl);
            exit;
        }

        $stmt = $db->prepare("SELECT * FROM expenses WHERE id = :id AND company_id = :cid");
        $stmt->execute([':id' => $expenseId, ':cid' => $activeCompanyId]);
        $exp = $stmt->fetch();

        if (!$exp) {
            setFlash('error', 'المصروف غير موجود.');
            header('Location: ' . $redirectUrl);
            exit;
        }

        if (in_array($exp['status'], ['deducted', 'approved'])) {
            setFlash('error', 'لا يمكن رفض مصروف تم صرفه وخصمه بالفعل من الخزنة.');
            header('Location: ' . $redirectUrl);
            exit;
        }

        $upd = $db->prepare("
            UPDATE expenses
            SET status = 'rejected',
                rejected_by = :uid,
                rejected_at = :now,
                rejection_reason = :reason
            WHERE id = :id AND company_id = :cid
        ");
        $upd->execute([
            ':uid' => $currentUser['id'],
            ':now' => date('Y-m-d H:i:s'),
            ':reason' => $reason,
            ':id' => $expenseId,
            ':cid' => $activeCompanyId
        ]);

        setFlash('success', 'تم رفض طلب المصروف بنجاح. لم يتم خصم أي مبالغ من الخزنة ولم يُضف للمشروع.');
        header('Location: ' . $redirectUrl);
        exit;
    }

    // Action: Confirm Pending Expense & Deduct from Safe
    elseif ($action === 'confirm_pending_expense') {
        $expenseId = (int)($_POST['expense_id'] ?? 0);
        $redirectUrl = trim($_POST['redirect_url'] ?? 'expenses.php');

        $stmt = $db->prepare("SELECT * FROM expenses WHERE id = :id AND company_id = :cid AND status = 'pending_receipt'");
        $stmt->execute([':id' => $expenseId, ':cid' => $activeCompanyId]);
        $exp = $stmt->fetch();

        if (!$exp) {
            setFlash('error', 'المصروف غير موجود أو تم تأكيد خصمه مسبقاً.');
            header('Location: ' . $redirectUrl);
            exit;
        }

        $attachmentPath = $exp['invoice_attachment'];
        $uploadedConfFiles = !empty($_FILES['invoice_files']['name']) ? $_FILES['invoice_files'] : (!empty($_FILES['invoice_file']['name']) ? $_FILES['invoice_file'] : null);
        if (!empty($uploadedConfFiles)) {
            $savedConfAtts = saveExpenseUploadedFiles($expenseId, $activeCompanyId, $uploadedConfFiles, (int)($exp['project_id'] ?? 0));
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
            $finalTitle = $exp['title'] . ($exp['beneficiary_name'] ? " | المستفيد: " . $exp['beneficiary_name'] : "");

            // Deduct from safe now
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

            // Update expense
            $upd = $db->prepare("
                UPDATE expenses
                SET invoice_attachment = :att,
                    safe_transaction_id = :txid,
                    status = 'approved',
                    approved_by = :uid,
                    approved_at = :now
                WHERE id = :id AND company_id = :cid
            ");
            $upd->execute([
                ':att' => $attachmentPath,
                ':txid' => $txResult['transaction_id'],
                ':uid' => $currentUser['id'],
                ':now' => date('Y-m-d H:i:s'),
                ':id' => $expenseId,
                ':cid' => $activeCompanyId
            ]);

            setFlash('success', 'تم إرفاق صورة الإيصال بنجاح وتأكيد خصم مبلغ ' . formatMoney($totalAmount, $expCurrency) . ' من الخزنة!');
            header('Location: ' . $redirectUrl);
            exit;
        } catch (Exception $e) {
            setFlash('error', $e->getMessage());
            header('Location: ' . $redirectUrl);
            exit;
        }
    }
}

// Fetch categories for filtering
$catStmt = $db->prepare("SELECT * FROM expense_categories WHERE company_id = :cid AND is_active = 1 ORDER BY name ASC");
$catStmt->execute([':cid' => $activeCompanyId]);
$categories = $catStmt->fetchAll();

// Filters
$filterStatus = trim($_GET['status'] ?? 'all');
$filterCat = !empty($_GET['category_id']) ? (int)$_GET['category_id'] : null;
$filterFrom = $_GET['from'] ?? date('Y-m-01');
$filterTo = $_GET['to'] ?? date('Y-m-d');
$search = trim($_GET['search'] ?? '');

$query = "
    SELECT e.*, 
           ec.name as category_name, 
           u.name as user_name, 
           u_app.name as approver_name,
           u_rej.name as rejector_name,
           p.name as project_name,
           st.balance_before,
           st.balance_after
    FROM expenses e
    LEFT JOIN expense_categories ec ON e.category_id = ec.id
    LEFT JOIN users u ON e.user_id = u.id
    LEFT JOIN users u_app ON e.approved_by = u_app.id
    LEFT JOIN users u_rej ON e.rejected_by = u_rej.id
    LEFT JOIN projects p ON e.project_id = p.id
    LEFT JOIN safe_transactions st ON e.safe_transaction_id = st.id
    WHERE e.company_id = :cid
";
$params = [':cid' => $activeCompanyId];

if ($filterStatus === 'pending_approval') {
    $query .= " AND e.status = 'pending_approval'";
} elseif ($filterStatus === 'approved') {
    $query .= " AND (e.status IN ('deducted', 'approved') OR e.status IS NULL)";
} elseif ($filterStatus === 'pending_receipt') {
    $query .= " AND e.status = 'pending_receipt'";
} elseif ($filterStatus === 'rejected') {
    $query .= " AND e.status = 'rejected'";
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
    $query .= " AND (e.title LIKE :s OR e.description LIKE :s OR e.beneficiary_name LIKE :s OR e.voucher_number LIKE :s)";
    $params[':s'] = "%{$search}%";
}
$query .= " ORDER BY e.id DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$expensesList = $stmt->fetchAll();

$allExpIds = array_column($expensesList, 'id');
$expenseAttachmentsGrouped = getBatchExpenseAttachments($allExpIds);

// Status Counts
$statusCountsStmt = $db->prepare("
    SELECT 
        COUNT(CASE WHEN status = 'pending_approval' THEN 1 END) as pending_approval_count,
        COALESCE(SUM(CASE WHEN status = 'pending_approval' THEN amount END), 0) as pending_approval_sum,
        COUNT(CASE WHEN status = 'pending_receipt' THEN 1 END) as pending_receipt_count,
        COALESCE(SUM(CASE WHEN status = 'pending_receipt' THEN amount END), 0) as pending_receipt_sum,
        COUNT(CASE WHEN status IN ('deducted', 'approved') OR status IS NULL THEN 1 END) as approved_count,
        COALESCE(SUM(CASE WHEN status IN ('deducted', 'approved') OR status IS NULL THEN amount END), 0) as approved_sum,
        COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected_count,
        COUNT(*) as all_count
    FROM expenses
    WHERE company_id = :cid
");
$statusCountsStmt->execute([':cid' => $activeCompanyId]);
$statusCounts = $statusCountsStmt->fetch();

$totalExpensesAmount = array_sum(array_column($expensesList, 'amount'));

$pageTitle = 'سجل المصروفات';
require_once __DIR__ . '/includes/header.php';
$canApprove = canApproveExpenses();
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-slate-900">سجل المصروفات والبنود</h1>
            <p class="text-xs text-slate-500 mt-1">
                تسجيل ومتابعة جميع النفقات، تصنيفها حسب البنود ونظام الاعتماد والموافقة الإدارية
            </p>
        </div>
        <div class="flex items-center space-x-2 space-x-reverse">
            <?php if (!isViewOnly()): ?>
                <button type="button" onclick="openModal('cash-voucher-modal')" class="inline-flex items-center bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-4 py-2.5 rounded-xl shadow-md transition text-xs sm:text-sm">
                    <i class="fa-solid fa-receipt ml-2"></i>
                    وصل استلام نقدي (سند)
                </button>
                <a href="expense_create.php" class="inline-flex items-center bg-slate-800 hover:bg-slate-900 text-white font-bold px-4 py-2.5 rounded-xl shadow-md transition text-xs sm:text-sm">
                    <i class="fa-solid fa-circle-minus ml-2"></i>
                    <?= $canApprove ? 'تسجيل وصرف مصروف' : 'تقديم طلب مصروف' ?>
                </a>
            <?php else: ?>
                <span class="inline-flex items-center px-3 py-1.5 rounded-xl text-xs font-black bg-amber-100 text-amber-800 border border-amber-300">
                    <i class="fa-solid fa-eye ml-1.5"></i> وضع المشاهدة فقط
                </span>
            <?php endif; ?>
            <?php if (isCompanyAdmin() && !isViewOnly()): ?>
                <a href="expense_categories.php" class="inline-flex items-center bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold px-3 py-2 rounded-xl text-xs transition border border-slate-200">
                    <i class="fa-solid fa-tags ml-1.5"></i> إدارة البنود
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- PENDING APPROVAL ALERT BANNER -->
    <?php if ((int)($statusCounts['pending_approval_count'] ?? 0) > 0): ?>
        <div class="bg-gradient-to-r from-amber-500/15 via-amber-500/10 to-transparent border-2 border-amber-400 rounded-2xl p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 shadow-sm">
            <div class="flex items-center space-x-3.5 space-x-reverse">
                <div class="w-12 h-12 rounded-2xl bg-amber-500 text-white flex items-center justify-center text-xl shrink-0 shadow-md">
                    <i class="fa-solid fa-shield-halved"></i>
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <strong class="text-sm sm:text-base font-black text-amber-950">يوجد عدد (<?= $statusCounts['pending_approval_count'] ?>) طلبات مصروفات بانتظار موافقة واعتماد الإدارة</strong>
                        <span class="animate-pulse inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-black bg-amber-500 text-white">تتطلب اعتماد</span>
                    </div>
                    <p class="text-xs text-amber-900 mt-1">
                        إجمالي المبالغ المعلقة: <strong class="font-mono font-black text-amber-950 text-sm"><?= formatMoney($statusCounts['pending_approval_sum'], $currency) ?></strong>
                        — <span class="text-slate-600 font-medium">لم يتم خصمها من الخزنة ولم يتم تحميلها على تكاليف المشاريع حتى الآن.</span>
                    </p>
                </div>
            </div>
            <div class="flex items-center space-x-2 space-x-reverse">
                <a href="expenses.php?status=pending_approval" class="bg-amber-600 hover:bg-amber-700 text-white text-xs font-bold px-4 py-2.5 rounded-xl shadow-xs transition flex items-center">
                    <i class="fa-solid fa-list-check ml-1.5"></i>
                    عرض المصروفات المعلقة
                </a>
            </div>
        </div>
    <?php endif; ?>

    <!-- STATUS FILTER TABS -->
    <div class="bg-white rounded-2xl p-2 border border-slate-200/80 shadow-xs flex flex-wrap items-center gap-1.5 text-xs">
        <a href="expenses.php?status=all" class="px-3.5 py-2 rounded-xl font-bold transition flex items-center space-x-1.5 space-x-reverse <?= $filterStatus === 'all' ? 'bg-slate-900 text-white shadow-xs' : 'text-slate-600 hover:bg-slate-100' ?>">
            <span>جميع المصروفات</span>
            <span class="px-1.5 py-0.5 rounded-full text-[10px] font-mono <?= $filterStatus === 'all' ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-700' ?>"><?= (int)($statusCounts['all_count'] ?? 0) ?></span>
        </a>

        <a href="expenses.php?status=pending_approval" class="px-3.5 py-2 rounded-xl font-bold transition flex items-center space-x-1.5 space-x-reverse <?= $filterStatus === 'pending_approval' ? 'bg-amber-600 text-white shadow-xs' : 'text-amber-800 bg-amber-50 hover:bg-amber-100' ?>">
            <i class="fa-solid fa-hourglass-half text-[11px] ml-1"></i>
            <span>بانتظار موافقة الإدارة</span>
            <span class="px-1.5 py-0.5 rounded-full text-[10px] font-mono font-black <?= $filterStatus === 'pending_approval' ? 'bg-white text-amber-900' : 'bg-amber-200 text-amber-900' ?>"><?= (int)($statusCounts['pending_approval_count'] ?? 0) ?></span>
        </a>

        <a href="expenses.php?status=approved" class="px-3.5 py-2 rounded-xl font-bold transition flex items-center space-x-1.5 space-x-reverse <?= $filterStatus === 'approved' ? 'bg-emerald-600 text-white shadow-xs' : 'text-slate-600 hover:bg-slate-100' ?>">
            <i class="fa-solid fa-circle-check text-[11px] ml-1 text-emerald-600 <?= $filterStatus === 'approved' ? 'text-white' : '' ?>"></i>
            <span>معتمدة ومخصومة</span>
            <span class="px-1.5 py-0.5 rounded-full text-[10px] font-mono <?= $filterStatus === 'approved' ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-700' ?>"><?= (int)($statusCounts['approved_count'] ?? 0) ?></span>
        </a>

        <a href="expenses.php?status=pending_receipt" class="px-3.5 py-2 rounded-xl font-bold transition flex items-center space-x-1.5 space-x-reverse <?= $filterStatus === 'pending_receipt' ? 'bg-blue-600 text-white shadow-xs' : 'text-slate-600 hover:bg-slate-100' ?>">
            <i class="fa-solid fa-receipt text-[11px] ml-1 text-blue-500 <?= $filterStatus === 'pending_receipt' ? 'text-white' : '' ?>"></i>
            <span>بانتظار الإيصال</span>
            <span class="px-1.5 py-0.5 rounded-full text-[10px] font-mono <?= $filterStatus === 'pending_receipt' ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-700' ?>"><?= (int)($statusCounts['pending_receipt_count'] ?? 0) ?></span>
        </a>

        <a href="expenses.php?status=rejected" class="px-3.5 py-2 rounded-xl font-bold transition flex items-center space-x-1.5 space-x-reverse <?= $filterStatus === 'rejected' ? 'bg-rose-600 text-white shadow-xs' : 'text-slate-600 hover:bg-slate-100' ?>">
            <i class="fa-solid fa-ban text-[11px] ml-1 text-rose-500 <?= $filterStatus === 'rejected' ? 'text-white' : '' ?>"></i>
            <span>المرفوضة</span>
            <span class="px-1.5 py-0.5 rounded-full text-[10px] font-mono <?= $filterStatus === 'rejected' ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-700' ?>"><?= (int)($statusCounts['rejected_count'] ?? 0) ?></span>
        </a>
    </div>

    <?php 
    $pendingExpList = array_filter($expensesList, fn($x) => ($x['status'] ?? '') === 'pending_receipt');
    $pendingCount = count($pendingExpList);
    $pendingTotal = array_sum(array_column($pendingExpList, 'amount'));
    ?>
    <?php if ($pendingCount > 0): ?>
        <div class="bg-amber-50 border-2 border-amber-300 rounded-2xl p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 shadow-xs">
            <div class="flex items-center space-x-3 space-x-reverse">
                <div class="w-10 h-10 rounded-xl bg-amber-500 text-white flex items-center justify-center text-lg font-bold">
                    <i class="fa-solid fa-clock"></i>
                </div>
                <div>
                    <strong class="text-sm font-black text-amber-950 block">يوجد عدد (<?= $pendingCount ?>) مصروفات معلقة بانتظار إرفاق صورة الإيصال</strong>
                    <p class="text-xs text-amber-800">إجمالي المبالغ المعلقة: <span class="font-bold font-mono"><?= formatMoney($pendingTotal, $currency) ?></span> (لم تُخصم من الخزنة بعد، وتنتظر رفع الفاتورة لتأكيد الخصم).</p>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-xs font-bold text-slate-500">إجمالي مصروفات الفترة</p>
                <p class="text-2xl font-black text-rose-600 mt-1"><?= formatMoney($totalExpensesAmount, $currency) ?></p>
                <span class="text-[11px] text-slate-400">من <?= htmlspecialchars($filterFrom) ?> إلى <?= htmlspecialchars($filterTo) ?></span>
            </div>
            <div class="w-12 h-12 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center text-xl">
                <i class="fa-solid fa-receipt"></i>
            </div>
        </div>

        <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-xs font-bold text-slate-500">عدد حركات الصرف</p>
                <p class="text-2xl font-black text-slate-800 mt-1"><?= count($expensesList) ?> عملية</p>
                <span class="text-[11px] text-slate-400">مسجلة في النظام</span>
            </div>
            <div class="w-12 h-12 rounded-xl bg-indigo-50 text-brand-600 flex items-center justify-center text-xl">
                <i class="fa-solid fa-calculator"></i>
            </div>
        </div>

        <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div class="min-w-0 flex-1">
                <p class="text-xs font-bold text-slate-500">الرصيد المتاح بالخزنة</p>
                <?php 
                $expBals = $safe['balances'] ?? [$currency => $safe['balance']];
                $activeExpBals = array_filter($expBals, fn($b) => abs($b) > 0.001);
                if (empty($activeExpBals)) $activeExpBals = [$currency => 0.00];
                ?>
                <div class="flex flex-wrap gap-1.5 mt-1.5">
                    <?php foreach ($activeExpBals as $bCurr => $bAmt): ?>
                        <span class="inline-flex items-center px-2 py-0.5 rounded-lg bg-emerald-50 text-emerald-800 font-black text-sm border border-emerald-200 dir-ltr">
                            <?= formatMoney($bAmt, $bCurr) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
                <span class="text-[11px] text-emerald-600 font-semibold block mt-1">متاح للصرف</span>
            </div>
            <div class="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-xl shrink-0 mr-3">
                <i class="fa-solid fa-vault"></i>
            </div>
        </div>
    </div>

    <!-- Filter Form -->
    <div class="no-print bg-white rounded-2xl p-4 border border-slate-200/80 shadow-xs">
        <form method="GET" action="expenses.php" class="grid grid-cols-1 sm:grid-cols-5 gap-3 items-end text-xs">
            <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
            <div>
                <label class="block font-bold text-slate-700 mb-1">بند المصروف</label>
                <select name="category_id" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 focus:bg-white focus:outline-none">
                    <option value="">-- كل البنود --</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $filterCat == $c['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block font-bold text-slate-700 mb-1">بحث بالسبب / الوصف</label>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="كلمة بحث..." class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 focus:bg-white focus:outline-none">
            </div>
            <div>
                <label class="block font-bold text-slate-700 mb-1">من تاريخ</label>
                <input type="date" name="from" value="<?= htmlspecialchars($filterFrom) ?>" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 focus:bg-white focus:outline-none">
            </div>
            <div>
                <label class="block font-bold text-slate-700 mb-1">إلى تاريخ</label>
                <input type="date" name="to" value="<?= htmlspecialchars($filterTo) ?>" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 focus:bg-white focus:outline-none">
            </div>
            <div class="flex items-center space-x-2 space-x-reverse">
                <button type="submit" class="flex-1 bg-brand-600 hover:bg-brand-700 text-white font-bold py-2 px-3 rounded-xl transition text-center">
                    <i class="fa-solid fa-filter ml-1"></i> تصفية
                </button>
                <a href="expenses.php" class="bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold py-2 px-3 rounded-xl transition text-center" title="إلغاء التصفية">
                    <i class="fa-solid fa-rotate-right"></i>
                </a>
            </div>
        </form>
    </div>

    <!-- Desktop Expenses Table (Hidden on Mobile) -->
    <div class="hidden md:block bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="bg-slate-50 text-slate-500 font-bold border-b border-slate-200">
                    <tr>
                        <th class="p-3.5">#</th>
                        <th class="p-3.5">تاريخ الصرف</th>
                        <th class="p-3.5">البند</th>
                        <th class="p-3.5">البيان / السبب</th>
                        <th class="p-3.5">المبلغ</th>
                        <th class="p-3.5">سجل بواسطة</th>
                        <th class="p-3.5 text-center no-print">سند صرف</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">
                    <?php if (empty($expensesList)): ?>
                        <tr>
                            <td colspan="7" class="p-8 text-center text-slate-400">
                                لا توجد مصروفات مسجلة تطابق خيارات البحث الحالية.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($expensesList as $exp): ?>
                            <tr class="hover:bg-slate-50/70 transition">
                                <td class="p-3.5 font-mono text-slate-400 text-[11px]">
                                    #<?= $exp['id'] ?>
                                </td>
                                <td class="p-3.5 whitespace-nowrap font-medium text-slate-600">
                                    <?= htmlspecialchars($exp['expense_date']) ?>
                                </td>
                                <td class="p-3.5 whitespace-nowrap">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-[11px] font-bold bg-slate-100 text-slate-800 border border-slate-200">
                                        <i class="fa-solid fa-tag ml-1 text-slate-400 text-[10px]"></i>
                                        <?= htmlspecialchars($exp['category_name'] ?? 'عام') ?>
                                    </span>
                                </td>
                                <td class="p-3.5">
                                    <div class="flex items-center space-x-1.5 space-x-reverse">
                                        <span class="font-extrabold text-slate-900"><?= htmlspecialchars($exp['title']) ?></span>
                                        <?php if (!empty($exp['is_edited'])): ?>
                                            <a href="audit_logs.php?search=<?= urlencode($exp['voucher_number'] ?: ('VCH-' . $exp['id'])) ?>" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-black bg-amber-100 hover:bg-amber-200 text-amber-900 border border-amber-300 transition shadow-2xs mr-1" title="فاتورة معدلة - انقر لعرض سجل التعديل والرقابة (Equals)">
                                                <i class="fa-solid fa-pen-to-square text-amber-600 text-[9px]"></i> مُعدّلة
                                            </a>
                                        <?php endif; ?>
                                        <?php if (hasModule('projects') && !empty($exp['project_name'])): ?>
                                            <a href="project_view.php?id=<?= $exp['project_id'] ?>" class="text-[10px] bg-amber-50 text-amber-800 border border-amber-200 px-2 py-0.5 rounded-full font-bold hover:bg-amber-100 transition">
                                                <i class="fa-solid fa-city text-[9px] ml-0.5"></i> <?= htmlspecialchars($exp['project_name']) ?>
                                            </a>
                                        <?php endif; ?>
                                        <?php 
                                        $eAtts = $expenseAttachmentsGrouped[$exp['id']] ?? [];
                                        if (empty($eAtts) && !empty($exp['invoice_attachment']) && file_exists(APP_PATH . '/' . $exp['invoice_attachment'])) {
                                            $eAtts = [['file_path' => $exp['invoice_attachment']]];
                                        }
                                        $eAttCount = count($eAtts);
                                        ?>
                                        <?php if ($eAttCount === 1): ?>
                                            <a href="<?= htmlspecialchars($eAtts[0]['file_path']) ?>" target="_blank" class="text-indigo-600 hover:text-indigo-800 text-[11px]" title="معاينة الفاتورة المرفقة">
                                                <i class="fa-solid fa-paperclip"></i>
                                            </a>
                                        <?php elseif ($eAttCount > 1): ?>
                                            <a href="<?= htmlspecialchars($eAtts[0]['file_path']) ?>" target="_blank" class="inline-flex items-center text-[10px] font-bold text-indigo-700 bg-indigo-50 border border-indigo-200 px-1.5 py-0.5 rounded" title="مرفق <?= $eAttCount ?> فواتير">
                                                <i class="fa-solid fa-layer-group ml-1 text-[9px]"></i> <?= $eAttCount ?> فواتير
                                            </a>
                                        <?php endif; ?>
                                        <?php if (!empty($exp['payment_method'])): ?>
                                            <?php
                                            $epm = trim($exp['payment_method']);
                                            $epmBadge = match($epm) {
                                                'تحويل بنكي' => ['bg' => 'bg-blue-50 text-blue-700 border-blue-200', 'icon' => 'fa-building-columns'],
                                                'كاش' => ['bg' => 'bg-amber-50 text-amber-800 border-amber-200', 'icon' => 'fa-wallet'],
                                                default => ['bg' => 'bg-emerald-50 text-emerald-800 border-emerald-200', 'icon' => 'fa-coins']
                                            };
                                            ?>
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold border <?= $epmBadge['bg'] ?>" title="طريقة الخصم">
                                                <i class="fa-solid <?= $epmBadge['icon'] ?> ml-1 text-[9px]"></i><?= htmlspecialchars($epm) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($exp['description'])): ?>
                                        <p class="text-[11px] text-slate-500 mt-0.5"><?= htmlspecialchars($exp['description']) ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($exp['beneficiary_name'])): ?>
                                        <span class="text-[10px] text-slate-400 font-medium block">المستفيد: <?= htmlspecialchars($exp['beneficiary_name']) ?></span>
                                    <?php endif; ?>
                                    
                                    <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                        <?php 
                                        $expSt = $exp['status'] ?? 'deducted';
                                        $isPendingApp = ($expSt === 'pending_approval');
                                        $isPendingRec = ($expSt === 'pending_receipt');
                                        $isRej = ($expSt === 'rejected');
                                        ?>
                                        <?php if ($isPendingApp): ?>
                                            <span class="px-2 py-0.5 rounded-lg text-[10px] font-black bg-amber-100 text-amber-900 border border-amber-300 inline-flex items-center">
                                                <i class="fa-solid fa-hourglass-half ml-1 text-amber-600"></i> بانتظار موافقة الإدارة
                                            </span>
                                            <?php if ($canApprove): ?>
                                                <button type="button" onclick="openApproveExpenseModal(<?= $exp['id'] ?>, '<?= htmlspecialchars(addslashes($exp['title'])) ?>', '<?= formatMoney($exp['amount'], !empty($exp['currency']) ? $exp['currency'] : $currency) ?>', '<?= htmlspecialchars(addslashes($exp['beneficiary_name'] ?? '')) ?>', '<?= htmlspecialchars(addslashes($exp['project_name'] ?? '')) ?>')" class="bg-emerald-600 hover:bg-emerald-700 text-white px-2.5 py-0.5 rounded-lg text-[10px] font-black inline-flex items-center shadow-xs transition active:scale-95">
                                                    <i class="fa-solid fa-check ml-1 text-[9px]"></i> اعتماد وصرف
                                                </button>
                                                <button type="button" onclick="openRejectExpenseModal(<?= $exp['id'] ?>, '<?= htmlspecialchars(addslashes($exp['title'])) ?>', '<?= formatMoney($exp['amount'], !empty($exp['currency']) ? $exp['currency'] : $currency) ?>')" class="bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-300 px-2 py-0.5 rounded-lg text-[10px] font-black inline-flex items-center transition active:scale-95">
                                                    <i class="fa-solid fa-ban ml-1 text-[9px]"></i> رفض
                                                </button>
                                            <?php endif; ?>
                                        <?php elseif ($isPendingRec): ?>
                                            <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-800 border border-amber-300 inline-flex items-center">
                                                <i class="fa-solid fa-clock ml-1 text-amber-600"></i> معلق بانتظار الإيصال
                                            </span>
                                            <button type="button" onclick="openConfirmReceiptModal(<?= $exp['id'] ?>, '<?= htmlspecialchars(addslashes($exp['title'])) ?>', '<?= formatMoney($exp['amount'], !empty($exp['currency']) ? $exp['currency'] : $currency) ?>', '<?= htmlspecialchars(addslashes($exp['beneficiary_name'] ?? '')) ?>')" class="bg-amber-600 hover:bg-amber-700 text-white px-2 py-0.5 rounded text-[10px] font-bold inline-flex items-center shadow-xs">
                                                <i class="fa-solid fa-cloud-arrow-up ml-1 text-[9px]"></i> إرفاق الإيصال وخصم
                                            </button>
                                        <?php elseif ($isRej): ?>
                                            <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-rose-100 text-rose-800 border border-rose-300 inline-flex items-center">
                                                <i class="fa-solid fa-ban ml-1 text-rose-600"></i> مرفوض
                                            </span>
                                            <?php if (!empty($exp['rejection_reason'])): ?>
                                                <span class="text-[9.5px] text-rose-600 font-bold block" title="<?= htmlspecialchars($exp['rejection_reason']) ?>">
                                                    سبب الرفض: <?= htmlspecialchars(mb_strimwidth($exp['rejection_reason'], 0, 35, '...')) ?>
                                                </span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200 inline-flex items-center">
                                                <i class="fa-solid fa-check-double ml-1 text-emerald-600"></i> معتمد ومخصوم
                                            </span>
                                            <?php if (!empty($exp['approver_name'])): ?>
                                                <span class="text-[9px] text-slate-400">
                                                    (اعتمد بواسطة: <?= htmlspecialchars($exp['approver_name']) ?>)
                                                </span>
                                            <?php endif; ?>
                                            <?php if (isset($exp['balance_before']) && $exp['balance_before'] !== null): ?>
                                                <span class="text-[9px] text-slate-400 font-mono">
                                                    (قبل: <?= formatMoney($exp['balance_before'], $exp['currency']) ?> | بعد: <?= formatMoney($exp['balance_after'], $exp['currency']) ?>)
                                                </span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="p-3.5 whitespace-nowrap font-black text-rose-600 text-sm">
                                    -<?= formatMoney($exp['amount'], !empty($exp['currency']) ? $exp['currency'] : $currency) ?>
                                </td>
                                <td class="p-3.5 whitespace-nowrap text-slate-500">
                                    <?= htmlspecialchars($exp['user_name'] ?? 'مستخدم') ?>
                                </td>
                                <td class="p-3.5 whitespace-nowrap text-center no-print">
                                    <?php if (!empty($exp['project_id']) || !empty($exp['voucher_number'])): ?>
                                        <a href="project_voucher_print.php?id=<?= $exp['id'] ?>" target="_blank" class="bg-amber-50 hover:bg-amber-100 text-amber-800 px-2 py-1 rounded-lg text-[11px] font-bold transition border border-amber-200 inline-flex items-center">
                                            <i class="fa-solid fa-print ml-1"></i> سند A4
                                        </a>
                                    <?php else: ?>
                                        <button onclick='printVoucher(<?= json_encode($exp) ?>, "<?= htmlspecialchars($currentUser['company_name'] ?? 'الشركة') ?>", "<?= !empty($exp['currency']) ? $exp['currency'] : $currency ?>")' class="bg-slate-100 hover:bg-slate-200 text-slate-700 px-2 py-1 rounded-lg text-[11px] font-bold transition">
                                            <i class="fa-solid fa-receipt ml-1"></i> طباعة سند
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Mobile Expense Cards Feed (Shown Only on Mobile) -->
    <div class="block md:hidden space-y-3">
        <?php if (empty($expensesList)): ?>
            <div class="bg-white rounded-2xl p-8 border border-slate-200/80 text-center shadow-xs">
                <div class="w-14 h-14 rounded-2xl bg-rose-50 text-rose-500 flex items-center justify-center text-2xl mx-auto mb-3">
                    <i class="fa-solid fa-receipt"></i>
                </div>
                <h3 class="font-bold text-slate-800 text-sm">لا توجد مصروفات مسجلة</h3>
                <p class="text-xs text-slate-400 mt-1">لا توجد حركات صرف تطابق خيارات البحث الحالية.</p>
                <a href="expense_create.php" class="mt-3 inline-flex items-center bg-rose-600 hover:bg-rose-700 text-white font-bold px-4 py-2 rounded-xl text-xs transition">
                    <i class="fa-solid fa-plus-circle ml-1.5"></i> تسجيل مصروف جديد
                </a>
            </div>
        <?php else: ?>
            <div class="flex items-center justify-between px-1">
                <span class="text-xs font-bold text-slate-600">قائمة المصروفات (<?= count($expensesList) ?>)</span>
                <a href="expense_create.php" class="text-xs font-bold text-rose-600 hover:underline flex items-center">
                    <i class="fa-solid fa-plus-circle ml-1"></i> تسجيل مصروف
                </a>
            </div>

            <?php foreach ($expensesList as $exp): 
                $expCurr = !empty($exp['currency']) ? $exp['currency'] : $currency;
                $isPending = (($exp['status'] ?? '') === 'pending_receipt');
            ?>
                <div class="bg-white rounded-2xl p-4 border border-slate-200/90 shadow-xs active:scale-[0.99] transition space-y-3">
                    <!-- Top row: Category Badge & Amount -->
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-center space-x-2 space-x-reverse min-w-0">
                            <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-[11px] font-bold bg-slate-100 text-slate-800 border border-slate-200 shrink-0">
                                <i class="fa-solid fa-tag ml-1 text-slate-400 text-[10px]"></i>
                                <?= htmlspecialchars($exp['category_name'] ?? 'عام') ?>
                            </span>
                            <span class="text-[11px] text-slate-400 font-mono">#<?= $exp['id'] ?></span>
                            <?php if (hasModule('projects') && !empty($exp['project_name'])): ?>
                                <span class="text-[10px] bg-amber-50 text-amber-800 border border-amber-200 px-2 py-0.5 rounded-full font-bold">
                                    <i class="fa-solid fa-city text-[9px] ml-0.5"></i> <?= htmlspecialchars($exp['project_name']) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="text-left shrink-0">
                            <span class="text-base font-black text-rose-600 block dir-ltr">
                                -<?= formatMoney($exp['amount'], $expCurr) ?>
                            </span>
                        </div>
                    </div>

                    <!-- Middle: Title, Description, Beneficiary -->
                    <div>
                        <h3 class="font-extrabold text-slate-900 text-sm leading-snug">
                            <?= htmlspecialchars($exp['title']) ?>
                        </h3>
                        <?php if (!empty($exp['beneficiary_name'])): ?>
                            <div class="flex items-center text-[11px] text-slate-500 mt-1">
                                <i class="fa-solid fa-user-tag ml-1 text-slate-400 text-[10px]"></i>
                                <span>المستفيد: <b class="text-slate-700"><?= htmlspecialchars($exp['beneficiary_name']) ?></b></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($exp['description'])): ?>
                            <p class="text-xs text-slate-600 mt-1.5 bg-slate-50 p-2.5 rounded-xl border border-slate-100 leading-relaxed">
                                <?= htmlspecialchars($exp['description']) ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <!-- Status Pill & Confirmation Button if Pending -->
                    <div class="flex flex-wrap items-center justify-between gap-2 pt-1 border-t border-slate-100">
                        <div>
                            <?php 
                            $mExpSt = $exp['status'] ?? 'deducted';
                            $mIsPendingApp = ($mExpSt === 'pending_approval');
                            $mIsPendingRec = ($mExpSt === 'pending_receipt');
                            $mIsRej = ($mExpSt === 'rejected');
                            ?>
                            <?php if ($mIsPendingApp): ?>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-black bg-amber-100 text-amber-900 border border-amber-300 inline-block">
                                    <i class="fa-solid fa-hourglass-half ml-1 text-amber-600"></i> بانتظار موافقة الإدارة
                                </span>
                            <?php elseif ($mIsPendingRec): ?>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800 border border-amber-300 inline-block">
                                    معلق بانتظار الإيصال
                                </span>
                            <?php elseif ($mIsRej): ?>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-100 text-rose-800 border border-rose-300 inline-block">
                                    <i class="fa-solid fa-ban ml-1 text-rose-600"></i> مرفوض
                                </span>
                                <?php if (!empty($exp['rejection_reason'])): ?>
                                    <span class="text-[9.5px] text-rose-600 font-bold block mt-0.5">
                                        سبب الرفض: <?= htmlspecialchars($exp['rejection_reason']) ?>
                                    </span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200 inline-block">
                                    مخصوم بالخزنة
                                </span>
                                <?php if (isset($exp['balance_before']) && $exp['balance_before'] !== null): ?>
                                    <span class="text-[9.5px] text-slate-400 font-mono block mt-0.5">
                                        بعد: <?= formatMoney($exp['balance_after'], $expCurr) ?>
                                    </span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>

                        <div class="flex items-center space-x-1.5 space-x-reverse">
                            <?php if ($mIsPendingApp && $canApprove): ?>
                                <button type="button" onclick="openApproveExpenseModal(<?= $exp['id'] ?>, '<?= htmlspecialchars(addslashes($exp['title'])) ?>', '<?= formatMoney($exp['amount'], $expCurr) ?>', '<?= htmlspecialchars(addslashes($exp['beneficiary_name'] ?? '')) ?>', '<?= htmlspecialchars(addslashes($exp['project_name'] ?? '')) ?>')" class="bg-emerald-600 hover:bg-emerald-700 text-white px-2.5 py-1 rounded-xl text-[10px] font-black inline-flex items-center shadow-xs">
                                    <i class="fa-solid fa-check ml-1 text-[9px]"></i> اعتماد
                                </button>
                                <button type="button" onclick="openRejectExpenseModal(<?= $exp['id'] ?>, '<?= htmlspecialchars(addslashes($exp['title'])) ?>', '<?= formatMoney($exp['amount'], $expCurr) ?>')" class="bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-300 px-2.5 py-1 rounded-xl text-[10px] font-black inline-flex items-center">
                                    <i class="fa-solid fa-ban ml-1 text-[9px]"></i> رفض
                                </button>
                            <?php elseif ($mIsPendingRec): ?>
                                <button type="button" onclick="openConfirmReceiptModal(<?= $exp['id'] ?>, '<?= htmlspecialchars(addslashes($exp['title'])) ?>', '<?= formatMoney($exp['amount'], $expCurr) ?>', '<?= htmlspecialchars(addslashes($exp['beneficiary_name'] ?? '')) ?>')" class="bg-amber-600 hover:bg-amber-700 text-white px-2.5 py-1 rounded-xl text-[11px] font-bold inline-flex items-center shadow-xs">
                                    <i class="fa-solid fa-cloud-arrow-up ml-1 text-[10px]"></i> إرفاق الإيصال
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Bottom row: Date, User & Action -->
                    <div class="flex items-center justify-between pt-3 border-t border-slate-100 text-xs">
                        <div class="flex items-center text-slate-500 text-[11px] truncate">
                            <i class="fa-regular fa-calendar ml-1 text-slate-400"></i>
                            <span><?= htmlspecialchars($exp['expense_date']) ?></span>
                            <span class="mx-1.5 text-slate-300">•</span>
                            <i class="fa-regular fa-user ml-1 text-slate-400"></i>
                            <span class="truncate"><?= htmlspecialchars($exp['user_name'] ?? 'المسؤول') ?></span>
                        </div>

                        <div class="flex items-center space-x-1.5 space-x-reverse shrink-0">
                            <?php if (!empty($exp['invoice_attachment']) && file_exists(APP_PATH . '/' . $exp['invoice_attachment'])): ?>
                                <a href="<?= htmlspecialchars($exp['invoice_attachment']) ?>" target="_blank" class="text-indigo-600 hover:text-indigo-800 p-1.5 rounded-lg bg-indigo-50 font-bold text-xs" title="معاينة الفاتورة">
                                    <i class="fa-solid fa-paperclip"></i>
                                </a>
                            <?php endif; ?>

                            <?php if (!empty($exp['project_id']) || !empty($exp['voucher_number'])): ?>
                                <a href="project_voucher_print.php?id=<?= $exp['id'] ?>" target="_blank" class="inline-flex items-center bg-slate-900 hover:bg-slate-800 text-white px-3 py-1.5 rounded-xl text-xs font-bold transition">
                                    <i class="fa-solid fa-print ml-1 text-slate-300"></i>
                                    <span>سند A4</span>
                                </a>
                            <?php else: ?>
                                <button onclick='printVoucher(<?= json_encode($exp) ?>, "<?= htmlspecialchars($currentUser['company_name'] ?? 'الشركة') ?>", "<?= $expCurr ?>")' class="inline-flex items-center bg-slate-100 hover:bg-rose-50 hover:text-rose-600 text-slate-700 px-3 py-1.5 rounded-xl text-xs font-bold transition border border-slate-200">
                                    <i class="fa-solid fa-receipt ml-1.5 text-rose-500"></i>
                                    <span>سند</span>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Print Voucher Modal Window -->
<div id="voucher-print-modal" class="modal-container fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4 overflow-y-auto">
    <div class="modal-backdrop fixed inset-0"></div>
    <div class="relative bg-white rounded-3xl shadow-2xl max-w-md w-full p-6 modal-animate-enter z-10 border border-slate-200" id="voucher-printable-area">
        <div class="text-center pb-4 border-b border-dashed border-slate-300">
            <h2 id="v_company" class="font-black text-lg text-slate-800">اسم الشركة</h2>
            <p class="text-xs text-slate-500 font-bold mt-0.5">سند صرف نقدي من الخزنة</p>
            <p id="v_id" class="text-[11px] font-mono text-slate-400 mt-1">#000</p>
        </div>

        <div class="py-4 space-y-3 text-xs">
            <div class="flex justify-between py-1 border-b border-slate-100">
                <span class="text-slate-500">تاريخ الصرف:</span>
                <span id="v_date" class="font-bold text-slate-800"></span>
            </div>
            <div class="flex justify-between py-1 border-b border-slate-100">
                <span class="text-slate-500">المبلغ المصروف:</span>
                <span id="v_amount" class="font-black text-rose-600 text-base"></span>
            </div>
            <div class="flex justify-between py-1 border-b border-slate-100">
                <span class="text-slate-500">بند المصروف:</span>
                <span id="v_category" class="font-bold text-slate-800"></span>
            </div>
            <div class="py-1 border-b border-slate-100">
                <span class="text-slate-500 block mb-1">البيان / السبب:</span>
                <span id="v_title" class="font-bold text-slate-900 block"></span>
                <p id="v_desc" class="text-slate-600 text-[11px] mt-0.5"></p>
            </div>
            <div class="flex justify-between py-1">
                <span class="text-slate-500">المحاسب / المستلم:</span>
                <span id="v_user" class="font-bold text-slate-800"></span>
            </div>
        </div>

        <div class="mt-4 pt-4 border-t border-dashed border-slate-300 grid grid-cols-2 gap-4 text-center text-[11px] text-slate-500">
            <div>
                <p>توقيع المستلم</p>
                <div class="h-10"></div>
                <p>....................</p>
            </div>
            <div>
                <p>اعتماد الإدارة</p>
                <div class="h-10"></div>
                <p>....................</p>
            </div>
        </div>

        <div class="mt-6 flex space-x-2 space-x-reverse no-print">
            <button onclick="window.print()" class="flex-1 bg-brand-600 hover:bg-brand-700 text-white font-bold py-2 rounded-xl text-xs transition">
                <i class="fa-solid fa-print ml-1"></i> طباعة السند
            </button>
            <button onclick="closeModal('voucher-print-modal')" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold py-2 px-4 rounded-xl text-xs transition">
                إغلاق
            </button>
        </div>
    </div>
</div>

<script>
function printVoucher(exp, compName, currency) {
    document.getElementById('v_company').innerText = compName;
    document.getElementById('v_id').innerText = 'سند صرف رقم: #' + exp.id;
    document.getElementById('v_date').innerText = exp.expense_date;
    document.getElementById('v_amount').innerText = parseFloat(exp.amount).toLocaleString('en-US', {minimumFractionDigits: 2}) + ' ' + currency;
    document.getElementById('v_category').innerText = exp.category_name || 'عام';
    document.getElementById('v_title').innerText = exp.title;
    document.getElementById('v_desc').innerText = exp.description || '';
    document.getElementById('v_user').innerText = exp.user_name || 'المسؤول';
    openModal('voucher-print-modal');
}
</script>

<?php include_once __DIR__ . '/includes/footer.php'; ?>
