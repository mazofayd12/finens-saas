<?php
require_once __DIR__ . '/config/app.php';

$token = trim($_GET['token'] ?? '');
if (empty($token)) {
    die('<div style="text-align:center;padding:50px;font-family:sans-serif;direction:rtl;"><h2>عذراً، رابط التقرير غير صحيح أو غير متوفر.</h2><p>يرجى طلب الرابط الصحيح من إدارة الشركة.</p></div>');
}

$db = getDBConnection();

// Fetch Project & Company by share_token
$stmt = $db->prepare("
    SELECT p.*, 
           c.name as company_name, c.logo as company_logo, c.phone as company_phone, 
           c.tax_number as company_tax_number, c.cr_number as company_cr_number, c.address as company_address,
           c.currency as company_currency
    FROM projects p
    JOIN companies c ON p.company_id = c.id
    WHERE p.share_token = :tok
    LIMIT 1
");
$stmt->execute([':tok' => $token]);
$project = $stmt->fetch();

if (!$project) {
    die('<div style="text-align:center;padding:50px;font-family:sans-serif;direction:rtl;"><h2>عذراً، لم يتم العثور على المشروع أو أن رابط المشاركة منتهي الصلاحية.</h2></div>');
}

$projectId = (int)$project['id'];
$companyId = (int)$project['company_id'];
$projCurrency = !empty($project['currency']) ? $project['currency'] : ($project['company_currency'] ?? 'ر.س');

// Fetch project safe
$safe = getProjectSafe($companyId, $projectId, $project['name']);

// Filter by month: 'all', '2026-04', '2026-05', '2026-12', etc.
$filterMonth = trim($_GET['month'] ?? '');
if ($filterMonth === 'all') {
    $filterMonth = '';
}
$excludeLand = isset($_GET['exclude_land']) && $_GET['exclude_land'] == '1';

$query = "
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
$params = [':cid' => $companyId, ':pid' => $projectId];

if (!empty($filterMonth)) {
    $from = date('Y-m-01', strtotime($filterMonth . '-01'));
    $to = date('Y-m-t', strtotime($filterMonth . '-01'));
    $query .= " AND e.expense_date >= :from AND e.expense_date <= :to";
    $params[':from'] = $from;
    $params[':to'] = $to;
}
if ($excludeLand) {
    $query .= " AND NOT " . getLandExpensesSqlCondition('e', 'ec');
}

$query .= " ORDER BY e.expense_date DESC, e.id DESC";

$expStmt = $db->prepare($query);
$expStmt->execute($params);
$expenses = $expStmt->fetchAll();

// Calculations
$totalExpenses = 0;
$totalTax = 0;
$pendingCount = 0;
foreach ($expenses as $ex) {
    $totalExpenses += (float)$ex['amount'];
    $totalTax += (float)$ex['tax_amount'];
}

// Available months in transactions for switcher
$monthsStmt = $db->prepare("
    SELECT DISTINCT strftime('%Y-%m', expense_date) as m
    FROM expenses
    WHERE company_id = :cid AND project_id = :pid
      AND (status IN ('deducted', 'approved') OR status IS NULL)
    ORDER BY m DESC
");
$monthsStmt->execute([':cid' => $companyId, ':pid' => $projectId]);
$availableMonths = $monthsStmt->fetchAll(PDO::FETCH_COLUMN);

// Ensure April, May, December are available for quick click
$suggestedMonths = [date('Y-04'), date('Y-05'), date('Y-12'), date('Y-m')];
foreach ($suggestedMonths as $sm) {
    if (!in_array($sm, $availableMonths)) {
        $availableMonths[] = $sm;
    }
}
rsort($availableMonths);

// Client billing calculations for without_capital projects
$isWithoutCapital = ($project['project_type'] ?? '') === 'without_capital';
$clientFeePct = (float)($project['client_fee_percentage'] ?? 0);
$clientFeeAmount = round($totalExpenses * ($clientFeePct / 100), 2);
$totalBillable = $totalExpenses + $clientFeeAmount;

$recStmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM receipts WHERE company_id = :cid AND project_id = :pid");
$recStmt->execute([':cid' => $companyId, ':pid' => $projectId]);
$clientPayments = (float)$recStmt->fetchColumn();

$depSumStmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM safe_transactions WHERE company_id = :cid AND safe_id = :sid AND type = 'deposit'");
$depSumStmt->execute([':cid' => $companyId, ':sid' => $safe['id']]);
$totalDeposits = (float)$depSumStmt->fetchColumn();

$effectiveClientPaid = max($clientPayments, $totalDeposits);
$clientDue = $totalBillable - $effectiveClientPaid;
$clientDueWords = tafqeetNumber(abs($clientDue), $projCurrency);

// Fetch Project Daily Reports & Site Media
$repStmt = $db->prepare("
    SELECT r.*, u.name as engineer_name 
    FROM project_daily_reports r 
    LEFT JOIN users u ON r.user_id = u.id 
    WHERE r.company_id = :cid AND r.project_id = :pid 
    ORDER BY r.report_date DESC, r.id DESC
");
$repStmt->execute([':cid' => $companyId, ':pid' => $projectId]);
$dailyReports = $repStmt->fetchAll();

$repIds = array_column($dailyReports, 'id');
$reportMediaGrouped = [];
$allProjectPhotos = [];
$allProjectVideos = [];
if (!empty($repIds)) {
    $inPlaceholders = implode(',', array_fill(0, count($repIds), '?'));
    $mStmt = $db->prepare("
        SELECT * FROM project_report_media 
        WHERE report_id IN ($inPlaceholders) 
        ORDER BY id DESC
    ");
    $mStmt->execute($repIds);
    $allMedia = $mStmt->fetchAll();
    foreach ($allMedia as $m) {
        $reportMediaGrouped[$m['report_id']][] = $m;
        if ($m['file_type'] === 'image') {
            $allProjectPhotos[] = $m;
        } elseif ($m['file_type'] === 'video') {
            $allProjectVideos[] = $m;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تقرير مصروفات مشروع <?= htmlspecialchars($project['name']) ?> - <?= htmlspecialchars($project['company_name']) ?></title>
    
    <!-- Tailwind CSS & Fonts & Icons -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

    <style>
        body { font-family: 'Cairo', sans-serif; background-color: #f8fafc; color: #1e293b; }
        @media print {
            .no-print { display: none !important; }
            body { background: #fff !important; }
            .print-shadow-none { box-shadow: none !important; border: 1px solid #cbd5e1 !important; }
        }
    </style>
</head>
<body class="p-3 sm:p-6 lg:p-8">

<div class="max-w-6xl mx-auto space-y-6">

    <!-- Top Action Bar (No-Print) -->
    <div class="no-print flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-white p-4 rounded-2xl border border-slate-200 shadow-xs">
        <div class="flex items-center space-x-2 space-x-reverse text-xs text-slate-500 font-bold">
            <span class="w-3 h-3 rounded-full bg-emerald-500 animate-pulse"></span>
            <span>بوابة العميل المباشرة | تقرير مالي وتدقيق معتمد ومحدث لحظياً</span>
        </div>
        <div class="flex items-center space-x-2 space-x-reverse">
            <?php if ($isWithoutCapital): ?>
                <a href="project_client_statement.php?token=<?= urlencode($token) ?>" target="_blank" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-4 py-2 rounded-xl text-xs transition shadow-md flex items-center">
                    <i class="fa-solid fa-file-invoice-dollar ml-1.5"></i>
                    كشف حساب ومطالبة العميل A4
                </a>
            <?php endif; ?>
            <a href="project_report_pdf.php?token=<?= urlencode($token) ?><?= !empty($filterMonth) ? '&month=' . urlencode($filterMonth) : '' ?>&autoprint=1" target="_blank" class="bg-rose-600 hover:bg-rose-700 text-white font-bold px-4 py-2 rounded-xl text-xs transition shadow-md flex items-center">
                <i class="fa-solid fa-file-pdf ml-1.5"></i>
                تحميل / طباعة PDF رسمي
            </a>
            <button onclick="window.print()" class="bg-slate-800 hover:bg-slate-900 text-white font-bold px-4 py-2 rounded-xl text-xs transition flex items-center">
                <i class="fa-solid fa-print ml-1.5"></i>
                طباعة التقرير
            </button>
        </div>
    </div>

    <!-- Official Header Card -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/90 shadow-sm print-shadow-none">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-6 pb-6 border-b border-slate-200">
            <!-- Company Info & Logo -->
            <div class="flex items-center space-x-4 space-x-reverse">
                <div class="w-16 h-16 sm:w-20 sm:h-20 rounded-2xl bg-slate-50 border border-slate-200 flex items-center justify-center p-2 shrink-0">
                    <?php if (!empty($project['company_logo']) && file_exists(APP_PATH . '/' . $project['company_logo'])): ?>
                        <img src="<?= htmlspecialchars($project['company_logo']) ?>" alt="شعار الشركة" class="max-h-full max-w-full object-contain">
                    <?php else: ?>
                        <i class="fa-solid fa-building text-3xl text-slate-400"></i>
                    <?php endif; ?>
                </div>
                <div>
                    <span class="text-xs font-bold text-amber-600 uppercase tracking-widest block">نظام إدارة المشاريع والعقارات</span>
                    <h1 class="text-xl sm:text-2xl font-black text-slate-900"><?= htmlspecialchars($project['company_name']) ?></h1>
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
                    </div>
                </div>
            </div>

            <!-- Project Identity Badge -->
            <div class="text-right md:text-left bg-amber-50/70 border border-amber-200 rounded-2xl p-4">
                <span class="text-[11px] font-bold text-amber-800 uppercase block">المشروع العقاري المستهدف</span>
                <h2 class="text-xl font-black text-amber-950 mt-0.5"><?= htmlspecialchars($project['name']) ?></h2>
                <div class="text-xs text-amber-900 font-medium mt-1 space-y-0.5">
                    <?php if (!empty($project['code'])): ?>
                        <span>كود المشروع: <strong><?= htmlspecialchars($project['code']) ?></strong></span> |
                    <?php endif; ?>
                    <span>رأس المال: <strong><?= formatMoney($project['capital'], $projCurrency) ?></strong></span>
                </div>
            </div>
        </div>

        <!-- Report Title & Period Title -->
        <div class="pt-6 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <h3 class="text-lg font-black text-slate-900 flex items-center">
                    <i class="fa-solid fa-clipboard-list ml-2 text-rose-600"></i>
                    تقرير المصروفات والتدقيق المالي للخزنة
                </h3>
                <p class="text-xs text-slate-500 mt-0.5">
                    <?php if (!empty($filterMonth)): ?>
                        بيان تفصيلي بجميع فواتير ومصروفات وحركة الخزنة لـ <strong>شهر <?= htmlspecialchars($filterMonth) ?></strong>
                    <?php else: ?>
                        بيان تفصيلي شامل لكافة مصروفات وفواتير المشروع منذ انطلاقه وحتى تاريخ اليوم
                    <?php endif; ?>
                </p>
            </div>

            <!-- Month Quick Switcher (No-Print) -->
            <div class="no-print flex flex-wrap items-center gap-1.5 text-xs">
                <span class="text-slate-400 font-bold ml-1">تصفية حسب الشهر:</span>
                <a href="project_report_share.php?token=<?= htmlspecialchars($token) ?>&month=all" class="px-3 py-1.5 rounded-xl font-bold transition <?= empty($filterMonth) ? 'bg-slate-900 text-white shadow-xs' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
                    التقرير الكامل
                </a>
                <?php foreach (array_slice($availableMonths, 0, 5) as $mOption): ?>
                    <a href="project_report_share.php?token=<?= htmlspecialchars($token) ?>&month=<?= $mOption ?>" class="px-3 py-1.5 rounded-xl font-bold transition <?= $filterMonth === $mOption ? 'bg-rose-600 text-white shadow-xs' : 'bg-rose-50 text-rose-800 hover:bg-rose-100' ?>">
                        <?= date('m / Y', strtotime($mOption . '-01')) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- CLIENT NAVIGATION TABS (No-Print) -->
    <div class="no-print bg-white rounded-2xl p-2 border border-slate-200/90 shadow-xs flex flex-wrap items-center gap-2 text-xs font-bold" id="client-nav-tabs">
        <button onclick="switchClientTab('client-tab-financial')" id="btn-client-tab-financial" class="flex-1 min-w-[140px] py-2.5 px-3 rounded-xl bg-slate-900 text-white flex items-center justify-center transition shadow-xs">
            <i class="fa-solid fa-receipt ml-1.5 text-amber-400"></i>
            فواتير ومصروفات المشروع (المالية)
        </button>
        <button onclick="switchClientTab('client-tab-reports')" id="btn-client-tab-reports" class="flex-1 min-w-[140px] py-2.5 px-3 rounded-xl bg-slate-100 text-slate-700 hover:bg-slate-200 flex items-center justify-center transition">
            <i class="fa-solid fa-camera-retro ml-1.5 text-blue-600"></i>
            تقارير الموقع والإنجاز الميداني (<?= count($dailyReports) ?>)
        </button>
        <button onclick="switchClientTab('client-tab-gallery')" id="btn-client-tab-gallery" class="flex-1 min-w-[140px] py-2.5 px-3 rounded-xl bg-slate-100 text-slate-700 hover:bg-slate-200 flex items-center justify-center transition">
            <i class="fa-solid fa-images ml-1.5 text-indigo-600"></i>
            معرض صور وفيديوهات الموقع (<?= count($allProjectPhotos) ?> صورة)
        </button>
    </div>

    <!-- TAB 1: FINANCIAL EXPENSES & AUDIT -->
    <div id="client-tab-financial" class="client-tab-pane space-y-6">

    <!-- Client Billing Summary Hero (When without_capital) -->
    <?php if ($isWithoutCapital): ?>
        <div class="bg-gradient-to-br from-indigo-950 via-slate-900 to-indigo-900 rounded-3xl p-6 sm:p-7 border border-indigo-800 text-white shadow-md space-y-5">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 pb-5 border-b border-white/10">
                <div>
                    <span class="text-xs font-bold text-indigo-300 block mb-1">مطالبة حساب العميل المالية (مشروع بدون رأس مال)</span>
                    <h3 class="text-xl sm:text-2xl font-black flex items-center">
                        <i class="fa-solid fa-file-invoice-dollar ml-2 text-indigo-400"></i>
                        المطلوب سداده من العميل (المتبقي):
                    </h3>
                    <p class="text-xs text-slate-300 mt-1">
                        العميل: <strong class="text-white"><?= htmlspecialchars($project['client_name'] ?: 'العميل') ?></strong>
                        <?php if (!empty($project['client_phone'])): ?>
                            <span class="font-mono text-slate-300 mr-1">(<?= htmlspecialchars($project['client_phone']) ?>)</span>
                        <?php endif; ?>
                        | فقط وقدره: <strong class="text-amber-300"><?= htmlspecialchars($clientDueWords) ?></strong>
                    </p>
                </div>
                <div class="text-left">
                    <span class="text-3xl sm:text-4xl font-black font-mono <?= $clientDue > 0 ? 'text-amber-400' : 'text-emerald-400' ?>">
                        <?= formatMoney(abs($clientDue), $projCurrency) ?>
                    </span>
                    <span class="block text-xs font-bold text-slate-400 mt-1">
                        <?= $clientDue > 0 ? 'رصيد مطلوب سداده' : ($clientDue < 0 ? 'رصيد دائن فائض' : 'مسدد بالكامل (خالص)') ?>
                    </span>
                </div>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
                <div class="bg-white/5 rounded-2xl p-3 border border-white/10">
                    <span class="text-[10px] text-slate-400 block mb-0.5">إجمالي المصروفات المباشرة</span>
                    <strong class="text-base font-black font-mono text-rose-300"><?= formatMoney($totalExpenses, $projCurrency) ?></strong>
                </div>
                <div class="bg-white/5 rounded-2xl p-3 border border-white/10">
                    <span class="text-[10px] text-slate-400 block mb-0.5">أتعاب الإشراف (<?= $clientFeePct ?>%)</span>
                    <strong class="text-base font-black font-mono text-amber-300">+<?= formatMoney($clientFeeAmount, $projCurrency) ?></strong>
                </div>
                <div class="bg-white/5 rounded-2xl p-3 border border-white/10">
                    <span class="text-[10px] text-slate-400 block mb-0.5">إجمالي المطالبة الشاملة</span>
                    <strong class="text-base font-black font-mono text-white"><?= formatMoney($totalBillable, $projCurrency) ?></strong>
                </div>
                <div class="bg-white/5 rounded-2xl p-3 border border-white/10">
                    <span class="text-[10px] text-emerald-300 block mb-0.5">إجمالي المسدد من العميل</span>
                    <strong class="text-base font-black font-mono text-emerald-400">-<?= formatMoney($effectiveClientPaid, $projCurrency) ?></strong>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- KPI Summary Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white rounded-2xl p-4 border border-slate-200/90 shadow-xs">
            <span class="text-xs font-bold text-slate-400 block mb-1">إجمالي المنصرف بالفترة</span>
            <h4 class="text-xl sm:text-2xl font-black text-rose-600 font-mono"><?= formatMoney($totalExpenses, $projCurrency) ?></h4>
            <span class="text-[11px] text-slate-400 mt-1 block">خصومات معتمدة من الخزنة</span>
        </div>

        <div class="bg-white rounded-2xl p-4 border border-slate-200/90 shadow-xs">
            <span class="text-xs font-bold text-slate-400 block mb-1">إجمالي ضريبة 15%</span>
            <h4 class="text-xl sm:text-2xl font-black text-amber-600 font-mono"><?= formatMoney($totalTax, $projCurrency) ?></h4>
            <span class="text-[11px] text-slate-400 mt-1 block">ضريبة القيمة المضافة</span>
        </div>

        <div class="bg-white rounded-2xl p-4 border border-slate-200/90 shadow-xs">
            <span class="text-xs font-bold text-slate-400 block mb-1">رصيد خزنة المشروع الحالي</span>
            <h4 class="text-xl sm:text-2xl font-black text-emerald-600 font-mono"><?= formatMoney($safe['balance'], $projCurrency) ?></h4>
            <span class="text-[11px] text-slate-400 mt-1 block">السيولة المتاحة بخزنة المشروع</span>
        </div>

        <div class="bg-white rounded-2xl p-4 border border-slate-200/90 shadow-xs">
            <span class="text-xs font-bold text-slate-400 block mb-1">عدد الفواتير والعمليات</span>
            <h4 class="text-xl sm:text-2xl font-black text-slate-800 font-mono"><?= count($expenses) ?> <span class="text-xs font-normal text-slate-400">فاتورة</span></h4>
            <span class="text-[11px] text-slate-400 mt-1 block"><?= $pendingCount > 0 ? "يوجد {$pendingCount} معلق" : "جميعها معتمدة ومؤكدة" ?></span>
        </div>
    </div>

    <!-- Detailed Audit Table -->
    <div class="bg-white rounded-3xl border border-slate-200/90 shadow-sm overflow-hidden print-shadow-none">
        <div class="p-4 sm:p-5 border-b border-slate-100 flex items-center justify-between">
            <h4 class="font-black text-slate-900 text-sm flex items-center">
                <i class="fa-solid fa-list-check ml-2 text-indigo-600"></i>
                جدول التدقيق المالي وحركة الخزنة الميدانية
            </h4>
            <span class="text-xs text-slate-400">عرض تفصيلي: البند، الوقت، المبلغ، حركة رصيد الخزنة، وصورة الفاتورة</span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="bg-slate-900 text-white font-bold">
                    <tr>
                        <th class="p-3.5"># السند</th>
                        <th class="p-3.5">التاريخ والوقت</th>
                        <th class="p-3.5">بند / بيان المصروف</th>
                        <th class="p-3.5">المستفيد / المورد</th>
                        <th class="p-3.5">المبلغ والضريبة</th>
                        <th class="p-3.5 bg-slate-800 text-center">حركة الخزنة (كانت إيه وبقت إيه)</th>
                        <th class="p-3.5 text-center">صورة الإيصال / الفاتورة</th>
                        <th class="p-3.5 text-center">حالة الاعتماد</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">
                    <?php if (empty($expenses)): ?>
                        <tr>
                            <td colspan="8" class="p-12 text-center text-slate-400">
                                <i class="fa-solid fa-receipt text-3xl mb-2 text-slate-300 block"></i>
                                لا توجد فواتير أو مصروفات مسجلة لهذا المشروع في الفترة المختارة.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($expenses as $idx => $row): ?>
                            <?php 
                            $baseAmt = (float)$row['amount'] - (float)$row['tax_amount'];
                            $hasVat = (float)$row['tax_rate'] > 0;
                            $isPending = ($row['status'] ?? '') === 'pending_receipt';
                            $exactTime = !empty($row['transaction_exact_time']) ? date('H:i', strtotime($row['transaction_exact_time'])) : date('H:i', strtotime($row['created_at']));
                            ?>
                            <tr class="hover:bg-slate-50/80 transition <?= $isPending ? 'bg-amber-50/30' : '' ?>">
                                <!-- 1: Voucher Number -->
                                <td class="p-3.5 whitespace-nowrap font-mono font-bold text-slate-900">
                                    <?= htmlspecialchars($row['voucher_number'] ?: ('VCH-' . $row['id'])) ?>
                                </td>

                                <!-- 2: Date & Time -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <strong class="text-slate-800 block"><?= date('Y-m-d', strtotime($row['expense_date'])) ?></strong>
                                    <span class="text-[10px] text-slate-400 font-mono"><?= $exactTime ?></span>
                                </td>

                                <!-- 3: Title & Item & Description -->
                                <td class="p-3.5">
                                    <div class="flex items-center space-x-1.5 space-x-reverse font-bold text-slate-900">
                                        <span class="w-2 h-2 rounded-full <?= $isPending ? 'bg-amber-500' : 'bg-rose-500' ?>"></span>
                                        <span><?= htmlspecialchars($row['title']) ?></span>
                                    </div>
                                    <?php if (!empty($row['description'])): ?>
                                        <p class="text-[11px] text-slate-500 mt-0.5 truncate max-w-xs"><?= htmlspecialchars($row['description']) ?></p>
                                    <?php endif; ?>
                                </td>

                                <!-- 4: Beneficiary -->
                                <td class="p-3.5 text-slate-800">
                                    <strong class="block text-slate-900"><?= htmlspecialchars($row['beneficiary_name'] ?: 'غير محدد') ?></strong>
                                    <?php if (!empty($row['beneficiary_id_number'])): ?>
                                        <span class="text-[10px] text-slate-400 font-mono">سجل/هوية: <?= htmlspecialchars($row['beneficiary_id_number']) ?></span>
                                    <?php endif; ?>
                                </td>

                                <!-- 5: Amount & VAT Breakdown -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <strong class="text-sm font-black text-rose-600 block font-mono">
                                        -<?= formatMoney($row['amount'], $row['currency']) ?>
                                    </strong>
                                    <div class="text-[10px] text-slate-400 space-x-1 space-x-reverse font-medium">
                                        <span>الأساسي: <?= formatMoney($baseAmt, $row['currency']) ?></span>
                                        <?php if ($hasVat): ?>
                                            <span>| ضريبة 15%: <?= formatMoney($row['tax_amount'], $row['currency']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- 6: Safe Audit (الخزنة كانت إيه وبقت إيه) -->
                                <td class="p-3.5 whitespace-nowrap bg-slate-50/70 border-x border-slate-100 text-center font-mono">
                                    <?php if ($isPending): ?>
                                        <span class="text-amber-700 bg-amber-100/80 px-2.5 py-1 rounded-lg text-[11px] font-bold inline-block">
                                            لم تُخصم بعد (معلق)
                                        </span>
                                    <?php elseif (isset($row['balance_before']) && $row['balance_before'] !== null): ?>
                                        <div class="inline-flex flex-col text-right text-[11px]">
                                            <span class="text-slate-500">قبل: <strong class="text-slate-700"><?= formatMoney($row['balance_before'], $row['currency']) ?></strong></span>
                                            <span class="text-slate-900 font-bold">بعد: <strong class="text-emerald-700"><?= formatMoney($row['balance_after'], $row['currency']) ?></strong></span>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-slate-400 text-[11px]">مخصوم بالخزنة</span>
                                    <?php endif; ?>
                                </td>

                                <!-- 7: Receipt Attachment Picture -->
                                <td class="p-3.5 whitespace-nowrap text-center">
                                    <?php if (!empty($row['invoice_attachment']) && file_exists(APP_PATH . '/' . $row['invoice_attachment'])): ?>
                                        <?php $ext = strtolower(pathinfo($row['invoice_attachment'], PATHINFO_EXTENSION)); ?>
                                        <?php if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])): ?>
                                            <button type="button" onclick="showReceiptImage('<?= htmlspecialchars($row['invoice_attachment']) ?>', '<?= htmlspecialchars(addslashes($row['title'])) ?>')" class="group relative inline-block">
                                                <img src="<?= htmlspecialchars($row['invoice_attachment']) ?>" alt="صورة الإيصال" class="w-12 h-12 object-cover rounded-xl border border-slate-300 group-hover:scale-105 transition shadow-xs">
                                                <span class="absolute inset-0 bg-slate-900/30 rounded-xl opacity-0 group-hover:opacity-100 flex items-center justify-center text-white text-xs transition">
                                                    <i class="fa-solid fa-magnifying-glass-plus"></i>
                                                </span>
                                            </button>
                                        <?php else: ?>
                                            <a href="<?= htmlspecialchars($row['invoice_attachment']) ?>" target="_blank" class="inline-flex items-center px-2.5 py-1.5 rounded-xl text-xs font-bold bg-rose-50 text-rose-700 border border-rose-200 hover:bg-rose-100 transition shadow-xs">
                                                <i class="fa-solid fa-file-pdf ml-1 text-sm"></i>
                                                عرض PDF
                                            </a>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-slate-300 text-[11px]">بدون مرفق</span>
                                    <?php endif; ?>
                                </td>

                                <!-- 8: Status Badge -->
                                <td class="p-3.5 whitespace-nowrap text-center">
                                    <?php if ($isPending): ?>
                                        <span class="px-2.5 py-1 rounded-full text-[10px] font-black bg-amber-100 text-amber-900 border border-amber-300 inline-flex items-center">
                                            <i class="fa-solid fa-clock ml-1 text-amber-600"></i> معلق بانتظار الإيصال
                                        </span>
                                    <?php else: ?>
                                        <span class="px-2.5 py-1 rounded-full text-[10px] font-black bg-emerald-100 text-emerald-900 border border-emerald-300 inline-flex items-center">
                                            <i class="fa-solid fa-circle-check ml-1 text-emerald-600"></i> مؤكد ومخصوم
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    </div> <!-- /#client-tab-financial -->

    <!-- TAB 2: SITE DAILY REPORTS & WORK TIMELINE (Exact user requirement) -->
    <div id="client-tab-reports" class="client-tab-pane hidden space-y-6">
        <!-- Site Timeline Hero -->
        <div class="bg-gradient-to-r from-blue-900 via-slate-900 to-indigo-950 text-white rounded-3xl p-6 sm:p-7 border border-blue-800 shadow-md flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div class="space-y-1.5">
                <div class="flex items-center space-x-2 space-x-reverse text-blue-300 text-xs font-black uppercase">
                    <i class="fa-solid fa-tower-observation text-sm"></i>
                    <span>سجل الإنجاز الميداني والمتابعة الدورية</span>
                </div>
                <h3 class="text-xl sm:text-2xl font-black">تقارير أعمال الموقع ونسب التنفيذ</h3>
                <p class="text-xs text-slate-300">
                    توثيق مباشر لمراحل وتفاصيل العمل في الموقع بالفترات الزمنية (من السبت للسبت...) مع الصور الحية والفيديوهات التوثيقية.
                </p>
            </div>
            <div class="flex items-center space-x-3 space-x-reverse">
                <div class="bg-white/10 rounded-2xl p-3 border border-white/10 text-center min-w-[100px]">
                    <span class="text-[10px] text-blue-200 block">إجمالي التقارير</span>
                    <strong class="text-xl font-black font-mono text-white"><?= count($dailyReports) ?></strong>
                </div>
                <div class="bg-white/10 rounded-2xl p-3 border border-white/10 text-center min-w-[100px]">
                    <span class="text-[10px] text-blue-200 block">صور الإنجاز</span>
                    <strong class="text-xl font-black font-mono text-emerald-300"><?= count($allProjectPhotos) ?></strong>
                </div>
            </div>
        </div>

        <?php if (empty($dailyReports)): ?>
            <div class="bg-white rounded-3xl p-12 text-center border border-slate-200 shadow-xs">
                <div class="w-16 h-16 rounded-full bg-blue-50 text-blue-600 mx-auto flex items-center justify-center text-2xl mb-3">
                    <i class="fa-solid fa-camera-retro"></i>
                </div>
                <h4 class="text-base font-black text-slate-800">لا توجد تقارير موقع منشورة بعد</h4>
                <p class="text-xs text-slate-400 mt-1">سيتم إدراج تقارير وصور وفيديوهات الإنجاز هنا فور تسجيلها من قبل الفريق الهندسي.</p>
            </div>
        <?php else: ?>
            <div class="space-y-6">
                <?php foreach ($dailyReports as $rep): ?>
                    <?php 
                    $repMedia = $reportMediaGrouped[$rep['id']] ?? [];
                    $repPhotos = array_filter($repMedia, fn($m) => $m['file_type'] === 'image');
                    $pct = max(0, min(100, (int)$rep['completion_percentage']));
                    ?>
                    <div class="bg-white rounded-3xl border border-slate-200/90 shadow-sm overflow-hidden print-shadow-none">
                        <!-- Report Header -->
                        <div class="p-5 sm:p-6 pb-4 border-b border-slate-100 flex flex-col md:flex-row md:items-center md:justify-between gap-4 bg-slate-50/70">
                            <div class="space-y-1.5">
                                <div class="flex flex-wrap items-center gap-2">
                                    <!-- Period Range Badge (e.g. من السبت للسبت) -->
                                    <?php if (!empty($rep['period_range'])): ?>
                                        <span class="inline-flex items-center px-3 py-1 rounded-xl text-xs font-black bg-indigo-100 text-indigo-950 border border-indigo-200">
                                            <i class="fa-solid fa-calendar-days ml-1.5 text-indigo-600"></i>
                                            <?= htmlspecialchars($rep['period_range']) ?>
                                        </span>
                                    <?php endif; ?>

                                    <!-- Date Badge -->
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-xl text-xs font-bold bg-slate-100 text-slate-700 border border-slate-200">
                                        <i class="fa-regular fa-clock ml-1 text-slate-400"></i>
                                        <?= date('Y-m-d', strtotime($rep['report_date'])) ?>
                                    </span>

                                    <!-- Stage Badge -->
                                    <?php if (!empty($rep['stage'])): ?>
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-xl text-xs font-black bg-amber-100 text-amber-900 border border-amber-200">
                                            <i class="fa-solid fa-trowel-bricks ml-1 text-amber-600"></i>
                                            <?= htmlspecialchars($rep['stage']) ?>
                                        </span>
                                    <?php endif; ?>

                                    <!-- Engineer Badge -->
                                    <?php if (!empty($rep['engineer_name'])): ?>
                                        <span class="text-xs text-slate-400">
                                            إشراف: <strong class="text-slate-600"><?= htmlspecialchars($rep['engineer_name']) ?></strong>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <h4 class="text-lg font-black text-slate-900"><?= htmlspecialchars($rep['title']) ?></h4>
                            </div>

                            <!-- Completion Progress Bar -->
                            <div class="w-40 sm:w-48 space-y-1 text-right">
                                <div class="flex items-center justify-between text-[11px] font-bold">
                                    <span class="text-slate-400">نسبة إنجاز المرحلة:</span>
                                    <span class="font-mono <?= $pct >= 100 ? 'text-emerald-600' : ($pct >= 50 ? 'text-blue-600' : 'text-amber-600') ?>"><?= $pct ?>%</span>
                                </div>
                                <div class="w-full bg-slate-200 h-2.5 rounded-full overflow-hidden">
                                    <div class="h-full rounded-full transition-all duration-500 <?= $pct >= 100 ? 'bg-emerald-500' : ($pct >= 50 ? 'bg-blue-600' : 'bg-amber-500') ?>" style="width: <?= $pct ?>%"></div>
                                </div>
                            </div>
                        </div>

                        <!-- Report Body -->
                        <div class="p-5 sm:p-6 space-y-4">
                            <?php if (!empty($rep['description'])): ?>
                                <p class="text-slate-700 text-sm leading-relaxed whitespace-pre-line bg-slate-50/80 p-4 rounded-2xl border border-slate-100">
                                    <?= nl2br(htmlspecialchars($rep['description'])) ?>
                                </p>
                            <?php endif; ?>

                            <!-- Photos -->
                            <?php if (!empty($repPhotos)): ?>
                                <div>
                                    <div class="flex items-center space-x-2 space-x-reverse text-xs font-bold text-slate-700 mb-2">
                                        <i class="fa-solid fa-images text-blue-600"></i>
                                        <span>صور الموقع (<?= count($repPhotos) ?> صورة) - اضغط لتكبير الصورة</span>
                                    </div>
                                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
                                        <?php foreach ($repPhotos as $img): ?>
                                            <div class="group relative aspect-4/3 rounded-2xl overflow-hidden bg-slate-100 border border-slate-200 cursor-pointer shadow-2xs hover:shadow-md transition" onclick="showReceiptImage('<?= htmlspecialchars($img['file_path']) ?>', '<?= htmlspecialchars(addslashes($rep['title'])) ?> (<?= htmlspecialchars($rep['period_range']) ?>)')">
                                                <img src="<?= htmlspecialchars($img['file_path']) ?>" alt="صورة إنجاز" class="w-full h-full object-cover group-hover:scale-105 transition duration-300">
                                                <div class="absolute inset-0 bg-slate-900/30 opacity-0 group-hover:opacity-100 transition flex items-center justify-center text-white text-base">
                                                    <i class="fa-solid fa-magnifying-glass-plus"></i>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Video -->
                            <?php 
                            $videoHtml = renderProjectVideoPlayer($rep['video_file'], $rep['video_url']);
                            if (!empty($videoHtml)): 
                            ?>
                                <div>
                                    <div class="flex items-center space-x-2 space-x-reverse text-xs font-bold text-slate-700 mb-1">
                                        <i class="fa-solid fa-video text-rose-600"></i>
                                        <span>فيديو توثيق إنجاز المرحلة</span>
                                    </div>
                                    <?= $videoHtml ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div> <!-- /#client-tab-reports -->

    <!-- TAB 3: MEDIA GALLERY (PHOTOS & VIDEOS) -->
    <div id="client-tab-gallery" class="client-tab-pane hidden space-y-6">
        <div class="bg-white rounded-3xl p-6 border border-slate-200/90 shadow-sm flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <h3 class="text-lg font-black text-slate-900 flex items-center">
                    <i class="fa-solid fa-images ml-2 text-indigo-600"></i>
                    معرض الصور والأفلام التوثيقية للمشروع
                </h3>
                <p class="text-xs text-slate-500 mt-0.5">كافة صور وفيديوهات الموقع المرفقة بتقارير الإنجاز الميداني لمشروع (<?= htmlspecialchars($project['name']) ?>).</p>
            </div>
            <span class="px-3 py-1.5 rounded-xl bg-indigo-50 text-indigo-800 text-xs font-black self-start sm:self-auto border border-indigo-200">
                إجمالي الصور: <?= count($allProjectPhotos) ?> صورة
            </span>
        </div>

        <?php if (empty($allProjectPhotos) && empty($allProjectVideos)): ?>
            <div class="bg-white rounded-3xl p-12 text-center border border-slate-200 shadow-xs">
                <i class="fa-solid fa-images text-3xl text-slate-300 block mb-2"></i>
                <p class="text-xs text-slate-400">لا توجد صور أو فيديوهات مرفوعة لهذا المشروع بعد.</p>
            </div>
        <?php else: ?>
            <!-- Photos Grid -->
            <?php if (!empty($allProjectPhotos)): ?>
                <div class="bg-white rounded-3xl p-6 border border-slate-200/90 shadow-sm space-y-4">
                    <h4 class="font-black text-slate-800 text-sm flex items-center">
                        <i class="fa-solid fa-camera ml-2 text-blue-600"></i>
                        ألبوم صور الموقع
                    </h4>
                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3">
                        <?php foreach ($allProjectPhotos as $photo): ?>
                            <div class="group relative aspect-square rounded-2xl overflow-hidden bg-slate-100 border border-slate-200 cursor-pointer hover:shadow-lg transition" onclick="showReceiptImage('<?= htmlspecialchars($photo['file_path']) ?>', '<?= htmlspecialchars(addslashes($photo['caption'] ?: 'صورة موقع')) ?>')">
                                <img src="<?= htmlspecialchars($photo['file_path']) ?>" alt="صورة الموقع" class="w-full h-full object-cover group-hover:scale-105 transition duration-300">
                                <div class="absolute inset-0 bg-slate-900/40 opacity-0 group-hover:opacity-100 transition flex items-center justify-center text-white">
                                    <i class="fa-solid fa-magnifying-glass-plus text-lg"></i>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Videos Grid -->
            <?php if (!empty($allProjectVideos)): ?>
                <div class="bg-white rounded-3xl p-6 border border-slate-200/90 shadow-sm space-y-4">
                    <h4 class="font-black text-slate-800 text-sm flex items-center">
                        <i class="fa-solid fa-video ml-2 text-rose-600"></i>
                        فيديوهات الموقع المسجلة
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <?php foreach ($allProjectVideos as $vid): ?>
                            <div class="rounded-2xl overflow-hidden bg-slate-900 border border-slate-800 shadow-sm">
                                <video controls playsinline class="w-full max-h-72 object-contain bg-black" preload="metadata">
                                    <source src="<?= htmlspecialchars($vid['file_path']) ?>">
                                </video>
                                <div class="p-3 bg-slate-900 text-white text-xs flex items-center justify-between">
                                    <span><?= htmlspecialchars($vid['caption'] ?: 'فيديو توثيقي للموقع') ?></span>
                                    <span class="text-[10px] text-slate-400 font-mono"><?= date('Y-m-d', strtotime($vid['created_at'])) ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div> <!-- /#client-tab-gallery -->

    <!-- Official Stamp & Verification Footer -->
    <div class="bg-white rounded-3xl p-6 border border-slate-200/90 shadow-sm flex flex-col md:flex-row md:items-center md:justify-between gap-4 text-xs text-slate-500">
        <div class="space-y-1">
            <span class="font-bold text-slate-800 block">التدقيق المالي والاعتماد الإلكتروني:</span>
            <p class="text-[11px]">تم استخراج هذا التقرير المالي آلياً ومطابق لكافة حركات الخزن وعمليات الصرف المعتمدة لمشروع (<?= htmlspecialchars($project['name']) ?>).</p>
        </div>
        <div class="flex items-center space-x-4 space-x-reverse font-mono text-[11px]">
            <span>تاريخ ووقت الاستخراج: <?= date('Y-m-d H:i') ?></span>
            <span class="px-2 py-0.5 rounded bg-slate-100 text-slate-700 font-bold">معتمد رسمياً</span>
        </div>
    </div>

</div>

<!-- Lightbox Modal for Receipt Images -->
<div id="image-lightbox-modal" class="fixed inset-0 z-50 bg-slate-900/80 backdrop-blur-sm hidden items-center justify-center p-4" onclick="closeReceiptImage()">
    <div class="relative bg-white rounded-3xl max-w-3xl w-full p-4 shadow-2xl overflow-hidden" onclick="event.stopPropagation()">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-3">
            <h4 class="font-bold text-slate-800 text-sm" id="lightbox-title">معاينة إيصال / فاتورة الصرف</h4>
            <button onclick="closeReceiptImage()" class="w-8 h-8 rounded-full bg-slate-100 text-slate-500 hover:bg-slate-200 flex items-center justify-center">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="max-h-[75vh] overflow-auto flex items-center justify-center bg-slate-50 rounded-2xl p-2">
            <img id="lightbox-img" src="" alt="الفاتورة" class="max-h-[70vh] max-w-full object-contain rounded-xl shadow-md">
        </div>
        <div class="pt-3 text-center">
            <a id="lightbox-download" href="" target="_blank" download class="inline-flex items-center bg-brand-600 hover:bg-brand-700 text-white font-bold px-4 py-2 rounded-xl text-xs transition">
                <i class="fa-solid fa-download ml-1.5"></i> فتح بالمتصفح أو تحميل الصورة
            </a>
        </div>
    </div>
</div>

<script>
function switchClientTab(tabId) {
    document.querySelectorAll('.client-tab-pane').forEach(el => el.classList.add('hidden'));
    const target = document.getElementById(tabId);
    if (target) target.classList.remove('hidden');

    document.querySelectorAll('#client-nav-tabs button').forEach(btn => {
        btn.classList.remove('bg-slate-900', 'text-white', 'shadow-xs');
        btn.classList.add('bg-slate-100', 'text-slate-700');
    });

    const activeBtn = document.getElementById('btn-' + tabId);
    if (activeBtn) {
        activeBtn.classList.remove('bg-slate-100', 'text-slate-700');
        activeBtn.classList.add('bg-slate-900', 'text-white', 'shadow-xs');
    }
}

function showReceiptImage(src, title) {
    document.getElementById('lightbox-img').src = src;
    document.getElementById('lightbox-download').href = src;
    document.getElementById('lightbox-title').innerText = title || 'معاينة الصورة';
    var modal = document.getElementById('image-lightbox-modal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeReceiptImage() {
    var modal = document.getElementById('image-lightbox-modal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

document.addEventListener('DOMContentLoaded', function() {
    const params = new URLSearchParams(window.location.search);
    const tab = params.get('tab');
    if (tab === 'reports' || tab === 'client-tab-reports') {
        switchClientTab('client-tab-reports');
    } else if (tab === 'gallery' || tab === 'client-tab-gallery') {
        switchClientTab('client-tab-gallery');
    }
});
</script>

</body>
</html>
