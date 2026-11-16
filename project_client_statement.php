<?php
require_once __DIR__ . '/config/app.php';

$projectId = (int)($_GET['id'] ?? 0);
$token = trim($_GET['token'] ?? '');
$db = getDBConnection();

$project = null;

// Allow access via share_token or active logged-in user session
if (!empty($token)) {
    $stmt = $db->prepare("
        SELECT p.*, 
               c.name as company_name, c.logo as company_logo, c.phone as company_phone, 
               c.tax_number as company_tax_number, c.cr_number as company_cr_number, c.address as company_address,
               c.email as company_email, c.location_url as company_location, c.primary_color as company_primary_color,
               c.currency as company_currency
        FROM projects p
        JOIN companies c ON p.company_id = c.id
        WHERE p.share_token = :tok
        LIMIT 1
    ");
    $stmt->execute([':tok' => $token]);
    $project = $stmt->fetch();
} elseif ($projectId > 0) {
    requireLogin();
    $currentUser = getCurrentUser();
    $activeCompanyId = (int)$currentUser['active_company_id'];

    $whereClause = isSuperAdmin() ? "WHERE p.id = :id" : "WHERE p.id = :id AND p.company_id = :cid";
    $params = isSuperAdmin() ? [':id' => $projectId] : [':id' => $projectId, ':cid' => $activeCompanyId];

    $stmt = $db->prepare("
        SELECT p.*, 
               c.name as company_name, c.logo as company_logo, c.phone as company_phone, 
               c.tax_number as company_tax_number, c.cr_number as company_cr_number, c.address as company_address,
               c.email as company_email, c.location_url as company_location, c.primary_color as company_primary_color,
               c.currency as company_currency
        FROM projects p
        JOIN companies c ON p.company_id = c.id
        {$whereClause}
        LIMIT 1
    ");
    $stmt->execute($params);
    $project = $stmt->fetch();
}

if (!$project) {
    die('<div style="text-align:center;padding:50px;font-family:sans-serif;direction:rtl;"><h2>عذراً، لم يتم العثور على المشروع أو كشف الحساب المطلوب.</h2><p>يرجى التأكد من الرابط أو تسجيل الدخول.</p></div>');
}

$projectId = (int)$project['id'];
$companyId = (int)$project['company_id'];
$projCurrency = !empty($project['currency']) ? $project['currency'] : ($project['company_currency'] ?? 'ر.س');
$themeColor = !empty($project['company_primary_color']) ? $project['company_primary_color'] : '#0f172a';

// Fetch safe for this project
$safe = getProjectSafe($companyId, $projectId, $project['name']);

$excludeLand = isset($_GET['exclude_land']) && $_GET['exclude_land'] == '1';

// Fetch all project expenses
$expQuery = "
    SELECT e.*, 
           ec.name as category_name, 
           u.name as user_name,
           st.balance_before,
           st.balance_after,
           st.created_at as transaction_exact_time
    FROM expenses e
    LEFT JOIN expense_categories ec ON e.category_id = ec.id
    LEFT JOIN users u ON e.user_id = u.id
    LEFT JOIN safe_transactions st ON e.safe_transaction_id = st.id
    WHERE e.company_id = :cid AND e.project_id = :pid
      AND (e.status IN ('deducted', 'approved') OR e.status IS NULL)
";
if ($excludeLand) {
    $expQuery .= " AND NOT " . getLandExpensesSqlCondition('e', 'ec');
}
$expQuery .= " ORDER BY e.expense_date ASC, e.id ASC";

$expStmt = $db->prepare($expQuery);
$expStmt->execute([':cid' => $companyId, ':pid' => $projectId]);
$expensesList = $expStmt->fetchAll();

// Fetch client payments & receipts
$recStmt = $db->prepare("
    SELECT r.*, u.name as receiver_name 
    FROM receipts r 
    LEFT JOIN users u ON r.user_id = u.id 
    WHERE r.company_id = :cid AND r.project_id = :pid 
    ORDER BY r.receipt_date ASC, r.id ASC
");
$recStmt->execute([':cid' => $companyId, ':pid' => $projectId]);
$clientReceipts = $recStmt->fetchAll();

// Calculations
$totalExpenses = 0;
$totalTax = 0;
$baseExpenses = 0;
foreach ($expensesList as $ex) {
    $totalExpenses += (float)$ex['amount'];
    $totalTax += (float)$ex['tax_amount'];
    $baseExpenses += ((float)$ex['amount'] - (float)$ex['tax_amount']);
}

$feePercentage = (float)($project['client_fee_percentage'] ?? 0);
$feeAmount = round($totalExpenses * ($feePercentage / 100), 2);
$totalBillable = $totalExpenses + $feeAmount;

$totalReceiptsPaid = 0;
foreach ($clientReceipts as $rec) {
    $totalReceiptsPaid += (float)$rec['amount'];
}

$clientDue = $totalBillable - $totalReceiptsPaid;
$clientDueWords = tafqeetNumber(abs($clientDue), $projCurrency);
$totalBillableWords = tafqeetNumber($totalBillable, $projCurrency);

$autoPrint = isset($_GET['autoprint']) && $_GET['autoprint'] === '1';
$statementDate = date('Y-m-d');
$statementNumber = 'STMT-PRJ-' . str_pad($projectId, 4, '0', STR_PAD_LEFT) . '-' . date('ymd');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>كشف حساب ومطالبة مصروفات - <?= htmlspecialchars($project['name']) ?> - <?= htmlspecialchars($project['company_name']) ?></title>
    
    <!-- Tailwind CSS & Fonts & Icons -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Cairo', sans-serif;
            background-color: #f1f5f9;
            color: #0f172a;
        }
        @media print {
            .no-print { display: none !important; }
            body { background: #fff !important; padding: 0 !important; margin: 0 !important; }
            .print-page {
                box-shadow: none !important;
                border: none !important;
                padding: 0 !important;
                margin: 0 !important;
                max-width: 100% !important;
            }
            .page-break { page-break-after: always; }
            thead { display: table-header-group; }
            tr { page-break-inside: avoid; }
        }
    </style>
</head>
<body class="p-3 sm:p-6 lg:p-8 text-slate-800">

<!-- Top Action Bar (No-Print) -->
<div class="no-print max-w-5xl mx-auto mb-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
    <div class="flex items-center space-x-2 space-x-reverse text-xs text-slate-600 font-bold">
        <a href="project_view.php?id=<?= $projectId ?>" class="hover:text-indigo-600 flex items-center">
            <i class="fa-solid fa-arrow-right ml-1.5"></i>
            العودة لصفحة المشروع
        </a>
        <span class="text-slate-300">|</span>
        <span class="text-indigo-600"><i class="fa-solid fa-file-invoice-dollar ml-1"></i> كشف حساب ومطالبة العميل المالية</span>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <a href="project_client_statement.php?id=<?= $projectId ?>&exclude_land=0<?= !empty($token) ? '&token=' . urlencode($token) : '' ?>" class="px-3 py-1.5 rounded-xl text-xs font-bold transition flex items-center <?= !$excludeLand ? 'bg-indigo-600 text-white shadow-sm' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' ?>">
            <i class="fa-solid fa-list-check ml-1.5"></i> شامل الأرض
        </a>
        <a href="project_client_statement.php?id=<?= $projectId ?>&exclude_land=1<?= !empty($token) ? '&token=' . urlencode($token) : '' ?>" class="px-3 py-1.5 rounded-xl text-xs font-bold transition flex items-center <?= $excludeLand ? 'bg-emerald-600 text-white shadow-sm' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' ?>">
            <i class="fa-solid fa-trowel-bricks ml-1.5"></i> تكلفة البناء فقط (بدون الأرض)
        </a>
        <button onclick="window.print()" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-4 py-2 rounded-xl text-xs transition shadow-md flex items-center">
            <i class="fa-solid fa-print ml-1.5"></i>
            طباعة كشف الحساب A4
        </button>
        <a href="project_report_share.php?token=<?= urlencode($project['share_token'] ?? '') ?><?= $excludeLand ? '&exclude_land=1' : '' ?>" target="_blank" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold px-3.5 py-2 rounded-xl text-xs transition flex items-center">
            <i class="fa-solid fa-eye ml-1.5"></i>
            بوابة تدقيق الفواتير
        </a>
    </div>
</div>

<!-- Main Printable Statement Sheet -->
<div class="print-page max-w-5xl mx-auto bg-white rounded-3xl p-6 sm:p-10 border border-slate-200 shadow-sm space-y-6">

    <!-- Header Section -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-6 pb-6 border-b-2 border-slate-900">
        <!-- Company Brand & Details -->
        <div class="flex items-center space-x-4 space-x-reverse">
            <div class="w-20 h-20 rounded-2xl bg-slate-50 border border-slate-200 flex items-center justify-center p-2 shrink-0">
                <?php if (!empty($project['company_logo']) && file_exists(APP_PATH . '/' . $project['company_logo'])): ?>
                    <img src="<?= htmlspecialchars($project['company_logo']) ?>" alt="شعار الشركة" class="max-h-full max-w-full object-contain">
                <?php else: ?>
                    <i class="fa-solid fa-building text-3xl text-slate-400"></i>
                <?php endif; ?>
            </div>
            <div>
                <span class="text-[11px] font-bold text-indigo-600 uppercase tracking-widest block">نظام إدارة المشاريع والعقارات</span>
                <h1 class="text-2xl font-black text-slate-900"><?= htmlspecialchars($project['company_name']) ?></h1>
                <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500 mt-1 font-medium">
                    <?php if (!empty($project['company_cr_number'])): ?>
                        <span><strong>س.ت:</strong> <?= htmlspecialchars($project['company_cr_number']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($project['company_tax_number'])): ?>
                        <span><strong>الرقم الضريبي:</strong> <?= htmlspecialchars($project['company_tax_number']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($project['company_phone'])): ?>
                        <span><strong>هاتف:</strong> <?= htmlspecialchars($project['company_phone']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($project['company_email'])): ?>
                        <span><strong>بريد:</strong> <?= htmlspecialchars($project['company_email']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($project['company_address'])): ?>
                        <span><strong>العنوان:</strong> <?= htmlspecialchars($project['company_address']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Document Reference & Type -->
        <div class="text-right bg-slate-50 border border-slate-200 rounded-2xl p-4 shrink-0 min-w-[220px]">
            <span class="text-[10px] font-black uppercase text-indigo-700 bg-indigo-50 px-2 py-0.5 rounded border border-indigo-200 block w-fit mb-1.5">
                <?= $excludeLand ? 'كشف تكلفة البناء والتنفيذ الفعلي' : 'كشف حساب ومطالبة سداد شاملة' ?>
            </span>
            <div class="text-xs text-slate-600 space-y-1">
                <div>رقم الكشف: <strong class="font-mono text-slate-900"><?= htmlspecialchars($statementNumber) ?></strong></div>
                <div>تاريخ الإصدار: <strong class="font-mono text-slate-900"><?= htmlspecialchars($statementDate) ?></strong></div>
                <div>نطاق التكلفة: <strong class="<?= $excludeLand ? 'text-emerald-700 font-bold' : 'text-slate-800' ?>"><?= $excludeLand ? 'تكاليف البناء فقط (بدون الأرض)' : 'شامل تكلفة الأرض' ?></strong></div>
                <div>العملة: <strong class="text-indigo-700"><?= htmlspecialchars($projCurrency) ?></strong></div>
            </div>
        </div>
    </div>

    <!-- Client & Project Information Card -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <!-- Client Box -->
        <div class="bg-indigo-50/50 rounded-2xl p-4 border border-indigo-100 space-y-2">
            <span class="text-[11px] font-black text-indigo-900 flex items-center">
                <i class="fa-solid fa-user-tie ml-1.5 text-indigo-600"></i>
                بيانات العميل / المالك المكلف:
            </span>
            <div class="text-xs space-y-1">
                <div class="flex items-center justify-between">
                    <span class="text-slate-500">اسم العميل:</span>
                    <strong class="text-slate-900 text-sm"><?= htmlspecialchars($project['client_name'] ?: 'العميل الكريم') ?></strong>
                </div>
                <?php if (!empty($project['client_phone'])): ?>
                    <div class="flex items-center justify-between">
                        <span class="text-slate-500">رقم الهاتف:</span>
                        <strong class="text-slate-800 font-mono"><?= htmlspecialchars($project['client_phone']) ?></strong>
                    </div>
                <?php endif; ?>
                <div class="flex items-center justify-between">
                    <span class="text-slate-500">نسبة أتعاب الإدارة المتفق عليها:</span>
                    <strong class="text-indigo-700 font-mono"><?= $feePercentage > 0 ? $feePercentage . '%' : 'بدون أتعاب إضافية' ?></strong>
                </div>
            </div>
        </div>

        <!-- Project Box -->
        <div class="bg-amber-50/50 rounded-2xl p-4 border border-amber-100 space-y-2">
            <span class="text-[11px] font-black text-amber-900 flex items-center">
                <i class="fa-solid fa-city ml-1.5 text-amber-600"></i>
                بيانات المشروع العقاري:
            </span>
            <div class="text-xs space-y-1">
                <div class="flex items-center justify-between">
                    <span class="text-slate-500">اسم المشروع:</span>
                    <strong class="text-slate-900 text-sm"><?= htmlspecialchars($project['name']) ?></strong>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-slate-500">كود المشروع:</span>
                    <strong class="text-slate-800 font-mono"><?= htmlspecialchars($project['code'] ?: ('PRJ-' . $project['id'])) ?></strong>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-slate-500">موقع المشروع:</span>
                    <strong class="text-slate-800"><?= htmlspecialchars($project['location'] ?: 'غير محدد') ?></strong>
                </div>
            </div>
        </div>
    </div>

    <!-- Executive Financial Settlement Box (THE USER's PRIMARY REQUIREMENT: المفروض العميل يدفع عليه كام) -->
    <div class="rounded-3xl p-6 border-2 <?= $clientDue > 0 ? 'bg-gradient-to-br from-indigo-950 via-slate-900 to-indigo-900 border-indigo-800 text-white' : 'bg-emerald-950 border-emerald-800 text-white' ?> shadow-lg">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-6 pb-6 border-b border-white/10">
            <div>
                <span class="text-xs font-bold uppercase tracking-wider text-indigo-300 block mb-1">
                    <?= $clientDue > 0 ? 'صافي الرصيد المستحق سداده من العميل' : ($clientDue < 0 ? 'رصيد دائن فائض لصالح العميل' : 'حساب العميل مسدد وخالص بالكامل') ?>
                </span>
                <div class="flex items-baseline space-x-3 space-x-reverse">
                    <h2 class="text-3xl sm:text-4xl font-black font-mono <?= $clientDue > 0 ? 'text-amber-400' : 'text-emerald-400' ?>">
                        <?= formatMoney(abs($clientDue), $projCurrency) ?>
                    </h2>
                    <span class="text-xs font-bold px-3 py-1 rounded-full <?= $clientDue > 0 ? 'bg-amber-500/20 text-amber-300 border border-amber-400/30' : 'bg-emerald-500/20 text-emerald-300 border border-emerald-400/30' ?>">
                        <?= $clientDue > 0 ? 'مستحق ومطلوب سداده الآن' : ($clientDue < 0 ? 'رصيد فائض مسدد مقدماً' : 'مسدد بالكامل (خالص)') ?>
                    </span>
                </div>
                <p class="text-xs text-slate-300 mt-2 font-medium">
                    فقط وقدره: <strong class="text-white"><?= htmlspecialchars($clientDueWords) ?></strong>
                </p>
            </div>

            <div class="text-left md:border-r md:border-white/10 md:pr-6 space-y-1 text-xs">
                <span class="text-slate-400 block">إجمالي المطالبة الشاملة للأتعاب:</span>
                <strong class="text-lg font-black font-mono text-white block"><?= formatMoney($totalBillable, $projCurrency) ?></strong>
                <span class="text-[11px] text-slate-400 font-mono">مسدد منها: <?= formatMoney($totalReceiptsPaid, $projCurrency) ?></span>
            </div>
        </div>

        <!-- 4 Step Equation Breakdown -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 pt-4 text-xs">
            <div class="bg-white/5 rounded-2xl p-3 border border-white/10">
                <span class="text-[10px] text-slate-400 block mb-1">1. إجمالي المصروفات المباشرة</span>
                <strong class="text-base font-black font-mono text-rose-300 block"><?= formatMoney($totalExpenses, $projCurrency) ?></strong>
                <span class="text-[10px] text-slate-400">بناء على <?= count($expensesList) ?> فاتورة مسجلة</span>
            </div>

            <div class="bg-white/5 rounded-2xl p-3 border border-white/10">
                <span class="text-[10px] text-slate-400 block mb-1">2. أتعاب الإشراف (<?= $feePercentage ?>%)</span>
                <strong class="text-base font-black font-mono text-amber-300 block">+<?= formatMoney($feeAmount, $projCurrency) ?></strong>
                <span class="text-[10px] text-slate-400"><?= $feePercentage > 0 ? "نسبة متفق عليها" : "بدون إضافة" ?></span>
            </div>

            <div class="bg-white/5 rounded-2xl p-3 border border-white/10">
                <span class="text-[10px] text-slate-400 block mb-1">3. إجمالي المطلوب كلياً</span>
                <strong class="text-base font-black font-mono text-white block">= <?= formatMoney($totalBillable, $projCurrency) ?></strong>
                <span class="text-[10px] text-slate-400">المصروفات + أتعاب الإشراف</span>
            </div>

            <div class="bg-white/5 rounded-2xl p-3 border border-white/10">
                <span class="text-[10px] text-emerald-300 block mb-1">4. إجمالي المسدد من العميل</span>
                <strong class="text-base font-black font-mono text-emerald-400 block">-<?= formatMoney($totalReceiptsPaid, $projCurrency) ?></strong>
                <span class="text-[10px] text-slate-400">سندات قبض مقيدة (<?= count($clientReceipts) ?> سند)</span>
            </div>
        </div>
    </div>

    <!-- Section 1: Itemized Expenses Breakdown Table -->
    <div class="space-y-3 pt-2">
        <div class="flex items-center justify-between pb-2 border-b border-slate-200">
            <h3 class="font-black text-slate-900 text-sm flex items-center">
                <i class="fa-solid fa-receipt ml-2 text-rose-600"></i>
                أولاً: بيان وتفاصيل مصروفات وفواتير المشروع (المسجلة على حساب العميل)
            </h3>
            <span class="text-xs text-slate-500 font-bold font-mono">العدد: <?= count($expensesList) ?> فاتورة</span>
        </div>

        <div class="overflow-x-auto rounded-2xl border border-slate-200">
            <table class="w-full text-right text-xs">
                <thead class="bg-slate-900 text-white font-bold">
                    <tr>
                        <th class="p-3 text-center">م</th>
                        <th class="p-3">رقم السند</th>
                        <th class="p-3">التاريخ</th>
                        <th class="p-3">بيان المصروف وتفاصيله</th>
                        <th class="p-3">المستفيد / المورد</th>
                        <th class="p-3">المبلغ الصافي</th>
                        <th class="p-3">ضريبة 15%</th>
                        <th class="p-3 text-left">الإجمالي النهائي</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">
                    <?php if (empty($expensesList)): ?>
                        <tr>
                            <td colspan="8" class="p-6 text-center text-slate-400">لا توجد مصروفات مسجلة على هذا المشروع حتى الآن.</td>
                        </tr>
                    <?php else: ?>
                        <?php $rowNum = 1; foreach ($expensesList as $row): ?>
                            <?php 
                            $baseAmt = (float)$row['amount'] - (float)$row['tax_amount'];
                            $hasVat = (float)$row['tax_rate'] > 0;
                            ?>
                            <tr class="hover:bg-slate-50/70 transition">
                                <td class="p-3 text-center font-mono text-slate-400"><?= $rowNum++ ?></td>
                                <td class="p-3 whitespace-nowrap font-mono font-bold text-slate-900">
                                    <?= htmlspecialchars($row['voucher_number'] ?: ('VCH-' . $row['id'])) ?>
                                </td>
                                <td class="p-3 whitespace-nowrap text-slate-600 font-mono">
                                    <?= date('Y-m-d', strtotime($row['expense_date'])) ?>
                                </td>
                                <td class="p-3 font-bold text-slate-900">
                                    <div><?= htmlspecialchars($row['title']) ?></div>
                                    <?php if (!empty($row['description']) && $row['description'] !== $row['title']): ?>
                                        <div class="text-[11px] text-slate-400 font-normal mt-0.5"><?= htmlspecialchars($row['description']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3 text-slate-600">
                                    <?= htmlspecialchars($row['beneficiary_name'] ?: '-') ?>
                                </td>
                                <td class="p-3 whitespace-nowrap font-mono text-slate-600">
                                    <?= formatMoney($baseAmt, $row['currency']) ?>
                                </td>
                                <td class="p-3 whitespace-nowrap font-mono text-slate-500">
                                    <?= $hasVat ? formatMoney($row['tax_amount'], $row['currency']) : '-' ?>
                                </td>
                                <td class="p-3 whitespace-nowrap font-black font-mono text-rose-600 text-left">
                                    <?= formatMoney($row['amount'], $row['currency']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot class="bg-slate-50 font-bold text-slate-900 border-t-2 border-slate-300">
                    <tr>
                        <td colspan="5" class="p-3 text-left">إجمالي المصروفات المباشرة:</td>
                        <td class="p-3 font-mono text-slate-700"><?= formatMoney($baseExpenses, $projCurrency) ?></td>
                        <td class="p-3 font-mono text-slate-700"><?= formatMoney($totalTax, $projCurrency) ?></td>
                        <td class="p-3 font-black font-mono text-rose-600 text-left text-sm"><?= formatMoney($totalExpenses, $projCurrency) ?></td>
                    </tr>
                    <?php if ($feePercentage > 0): ?>
                        <tr class="bg-indigo-50/70 text-indigo-950">
                            <td colspan="7" class="p-2.5 text-left text-xs font-bold">
                                يضاف: أتعاب إدارة وإشراف بنسبة (<?= $feePercentage ?>%):
                            </td>
                            <td class="p-2.5 font-black font-mono text-indigo-700 text-left text-sm">
                                +<?= formatMoney($feeAmount, $projCurrency) ?>
                            </td>
                        </tr>
                        <tr class="bg-slate-900 text-white font-black text-sm">
                            <td colspan="7" class="p-3 text-left">
                                إجمالي المطالبة المالية المستحقة لمصروفات المشروع:
                            </td>
                            <td class="p-3 font-mono text-amber-400 text-left text-base">
                                <?= formatMoney($totalBillable, $projCurrency) ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- Section 2: Client Payments / Receipts Table -->
    <div class="space-y-3 pt-4">
        <div class="flex items-center justify-between pb-2 border-b border-slate-200">
            <h3 class="font-black text-slate-900 text-sm flex items-center">
                <i class="fa-solid fa-hand-holding-dollar ml-2 text-emerald-600"></i>
                ثانياً: بيان الدفعات وسندات القبض المسددة من العميل
            </h3>
            <span class="text-xs text-slate-500 font-bold font-mono">العدد: <?= count($clientReceipts) ?> سند قبض</span>
        </div>

        <div class="overflow-x-auto rounded-2xl border border-slate-200">
            <table class="w-full text-right text-xs">
                <thead class="bg-emerald-950 text-white font-bold">
                    <tr>
                        <th class="p-3 text-center">م</th>
                        <th class="p-3">رقم السند</th>
                        <th class="p-3">تاريخ القبض</th>
                        <th class="p-3">المستلم منه</th>
                        <th class="p-3">طريقة الدفع</th>
                        <th class="p-3">البيان والغرض</th>
                        <th class="p-3">المستلم</th>
                        <th class="p-3 text-left">المبلغ المقبوض</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">
                    <?php if (empty($clientReceipts)): ?>
                        <tr>
                            <td colspan="8" class="p-6 text-center text-slate-400">لا توجد سندات قبض مسجلة من العميل حتى الآن.</td>
                        </tr>
                    <?php else: ?>
                        <?php $rNum = 1; foreach ($clientReceipts as $rec): ?>
                            <tr class="hover:bg-slate-50/70 transition">
                                <td class="p-3 text-center font-mono text-slate-400"><?= $rNum++ ?></td>
                                <td class="p-3 whitespace-nowrap font-mono font-bold text-slate-900">
                                    <?= htmlspecialchars($rec['receipt_number']) ?>
                                </td>
                                <td class="p-3 whitespace-nowrap text-slate-600 font-mono">
                                    <?= date('Y-m-d', strtotime($rec['receipt_date'])) ?>
                                </td>
                                <td class="p-3 font-bold text-slate-800">
                                    <?= htmlspecialchars($rec['customer_name']) ?>
                                </td>
                                <td class="p-3 whitespace-nowrap text-slate-600">
                                    <?= htmlspecialchars($rec['payment_method'] ?: 'نقداً') ?>
                                </td>
                                <td class="p-3 text-slate-600">
                                    <?= htmlspecialchars($rec['purpose'] ?: '-') ?>
                                </td>
                                <td class="p-3 text-slate-500">
                                    <?= htmlspecialchars($rec['receiver_name'] ?? '-') ?>
                                </td>
                                <td class="p-3 whitespace-nowrap font-black font-mono text-emerald-600 text-left text-sm">
                                    +<?= formatMoney($rec['amount'], $rec['currency'] ?? $projCurrency) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot class="bg-emerald-50 font-bold text-emerald-950 border-t-2 border-emerald-300">
                    <tr>
                        <td colspan="7" class="p-3 text-left">إجمالي المبالغ المسددة من العميل:</td>
                        <td class="p-3 font-black font-mono text-emerald-700 text-left text-sm">
                            <?= formatMoney($totalReceiptsPaid, $projCurrency) ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- Final Net Due Strip -->
    <div class="bg-slate-50 rounded-2xl p-5 border border-slate-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <span class="text-xs font-bold text-slate-500 block mb-1">الخلاصة المالية النهائية:</span>
            <div class="text-xs text-slate-700">
                إجمالي المطالبة المالية (<?= formatMoney($totalBillable, $projCurrency) ?>) - إجمالي المسدد (<?= formatMoney($totalReceiptsPaid, $projCurrency) ?>)
            </div>
        </div>
        <div class="text-left">
            <span class="text-xs font-bold text-slate-500 block">صافي الرصيد المطلوب:</span>
            <strong class="text-2xl font-black font-mono <?= $clientDue > 0 ? 'text-amber-600' : 'text-emerald-600' ?>">
                <?= formatMoney(abs($clientDue), $projCurrency) ?>
            </strong>
        </div>
    </div>

    <!-- Signatures and Stamp Section -->
    <div class="pt-8 border-t border-slate-200">
        <div class="grid grid-cols-3 gap-6 text-center text-xs">
            <!-- Preparer -->
            <div class="space-y-12">
                <div>
                    <span class="text-slate-400 font-bold block">إعداد ومطابقة الحسابات</span>
                    <strong class="text-slate-800 text-sm block mt-1"><?= htmlspecialchars($project['user_name'] ?? 'قسم الحسابات') ?></strong>
                </div>
                <div class="border-b border-dashed border-slate-300 w-32 mx-auto"></div>
                <span class="text-[10px] text-slate-400 block">التوقيع والتاريخ</span>
            </div>

            <!-- Management Approval & Stamp -->
            <div class="space-y-4">
                <div>
                    <span class="text-slate-400 font-bold block">اعتماد الإدارة والختم الرسمي</span>
                    <strong class="text-slate-800 text-sm block mt-1"><?= htmlspecialchars($project['company_name']) ?></strong>
                </div>
                <div class="w-24 h-24 border-2 border-dashed border-indigo-200 rounded-full mx-auto flex items-center justify-center text-indigo-300 text-[10px] font-bold">
                    الختم الرسمي
                </div>
            </div>

            <!-- Client Acceptance -->
            <div class="space-y-12">
                <div>
                    <span class="text-slate-400 font-bold block">موافقة وتوقيع العميل / المالك</span>
                    <strong class="text-slate-800 text-sm block mt-1"><?= htmlspecialchars($project['client_name'] ?: 'العميل') ?></strong>
                </div>
                <div class="border-b border-dashed border-slate-300 w-32 mx-auto"></div>
                <span class="text-[10px] text-slate-400 block">التوقيع والتاريخ</span>
            </div>
        </div>
    </div>

    <!-- Footer Note -->
    <div class="pt-6 border-t border-slate-100 flex items-center justify-between text-[11px] text-slate-400">
        <span>تم إنشاء هذا الكشف إلكترونياً عبر <?= htmlspecialchars($project['company_name']) ?> وهو معتمد ومطابق للحسابات البنكية والخزينة.</span>
        <span>طُبع في: <?= date('Y-m-d H:i') ?></span>
    </div>

</div>

<?php if ($autoPrint): ?>
<script>
window.addEventListener('DOMContentLoaded', () => {
    setTimeout(() => {
        window.print();
    }, 400);
});
</script>
<?php endif; ?>

</body>
</html>
