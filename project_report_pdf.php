<?php
require_once __DIR__ . '/config/app.php';

$projectId = (int)($_GET['id'] ?? 0);
$token = trim($_GET['token'] ?? '');
$db = getDBConnection();

// Allow access either by logged in company user OR by valid share_token
$project = null;
if (!empty($token)) {
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
} elseif ($projectId > 0) {
    requireLogin();
    $currentUser = getCurrentUser();
    $activeCompanyId = (int)$currentUser['active_company_id'];

    $stmt = $db->prepare("
        SELECT p.*, 
               c.name as company_name, c.logo as company_logo, c.phone as company_phone, 
               c.tax_number as company_tax_number, c.cr_number as company_cr_number, c.address as company_address,
               c.currency as company_currency
        FROM projects p
        JOIN companies c ON p.company_id = c.id
        WHERE p.id = :id AND p.company_id = :cid
        LIMIT 1
    ");
    $stmt->execute([':id' => $projectId, ':cid' => $activeCompanyId]);
    $project = $stmt->fetch();
}

if (!$project) {
    die('<div style="text-align:center;padding:50px;font-family:sans-serif;direction:rtl;"><h2>المشروع غير موجود أو ليس لديك صلاحية لعرض التقرير.</h2></div>');
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

// Batch fetch attachments for PDF export
$allExpIds = array_column($expenses, 'id');
$expenseAttachmentsGrouped = getBatchExpenseAttachments($allExpIds);

// Calculations
$totalExpenses = 0;
$totalTax = 0;
$baseTotal = 0;
foreach ($expenses as $ex) {
    $totalExpenses += (float)$ex['amount'];
    $totalTax += (float)$ex['tax_amount'];
    $baseTotal += ((float)$ex['amount'] - (float)$ex['tax_amount']);
}

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

$autoPrint = isset($_GET['autoprint']) && $_GET['autoprint'] === '1';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تقرير مصروفات مشروع <?= htmlspecialchars($project['name']) ?> - PDF</title>
    
    <!-- Fonts & Icons -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

    <style>
        body { font-family: 'Cairo', sans-serif; background-color: #f1f5f9; color: #0f172a; margin: 0; padding: 20px 0; }
        .page-sheet {
            width: 297mm;
            min-height: 210mm;
            padding: 15mm;
            margin: 0 auto;
            background: #fff;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            border-radius: 8px;
            box-sizing: border-box;
            position: relative;
        }
        @media print {
            body { background: #fff !important; padding: 0 !important; }
            .no-print { display: none !important; }
            .page-sheet {
                width: 100% !important;
                box-shadow: none !important;
                border-radius: 0 !important;
                padding: 5mm !important;
                margin: 0 !important;
            }
            @page {
                size: A4 landscape;
                margin: 8mm;
            }
            table { page-break-inside: auto; }
            tr { page-break-inside: avoid; page-break-after: auto; }
            thead { display: table-header-group; }
            tfoot { display: table-footer-group; }
        }
    </style>
</head>
<body>

<!-- Floating Print Controls (No Print) -->
<div class="no-print max-w-7xl mx-auto mb-4 px-4 flex items-center justify-between">
    <div class="flex items-center space-x-2 space-x-reverse text-xs font-bold text-slate-600">
        <a href="project_view.php?id=<?= $projectId ?>" class="bg-slate-200 hover:bg-slate-300 text-slate-700 px-3 py-1.5 rounded-lg transition flex items-center">
            <i class="fa-solid fa-arrow-right ml-1"></i> العودة للمشروع
        </a>
        <span>معاينة تقرير المصروفات المعتمد A4 (تنسيق أفقي منظم)</span>
    </div>
    <div class="flex items-center space-x-2 space-x-reverse">
        <button onclick="window.print()" class="bg-rose-600 hover:bg-rose-700 text-white font-bold px-5 py-2 rounded-xl text-xs transition shadow-md flex items-center">
            <i class="fa-solid fa-print ml-1.5"></i> طباعة / حفظ كـ PDF
        </button>
    </div>
</div>

<!-- A4 Landscape Printable Sheet -->
<div class="page-sheet flex flex-col justify-between">

    <div>
        <!-- Official Document Header -->
        <div class="flex items-center justify-between pb-4 border-b-2 border-slate-900 mb-4">
            <!-- Company Info & Logo -->
            <div class="flex items-center space-x-3 space-x-reverse">
                <div class="w-16 h-16 rounded-xl bg-slate-50 border border-slate-300 flex items-center justify-center p-1.5 shrink-0">
                    <?php if (!empty($project['company_logo']) && file_exists(APP_PATH . '/' . $project['company_logo'])): ?>
                        <img src="<?= htmlspecialchars($project['company_logo']) ?>" alt="الشعار" class="max-h-full max-w-full object-contain">
                    <?php else: ?>
                        <i class="fa-solid fa-building text-2xl text-slate-400"></i>
                    <?php endif; ?>
                </div>
                <div>
                    <h2 class="text-lg font-black text-slate-900"><?= htmlspecialchars($project['company_name']) ?></h2>
                    <div class="text-[10px] text-slate-600 space-x-3 space-x-reverse font-medium">
                        <?php if (!empty($project['company_cr_number'])): ?>
                            <span>س.ت: <strong><?= htmlspecialchars($project['company_cr_number']) ?></strong></span>
                        <?php endif; ?>
                        <?php if (!empty($project['company_tax_number'])): ?>
                            <span>الرقم الضريبي: <strong><?= htmlspecialchars($project['company_tax_number']) ?></strong></span>
                        <?php endif; ?>
                        <?php if (!empty($project['company_phone'])): ?>
                            <span>هاتف: <strong><?= htmlspecialchars($project['company_phone']) ?></strong></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Report Center Title -->
            <div class="text-center px-5 py-2 bg-slate-900 text-white rounded-xl">
                <h1 class="text-sm font-black tracking-wide">تقرير المصروفات والتدقيق المالي</h1>
                <span class="text-[9px] text-slate-300 font-mono tracking-widest block uppercase">PROJECT FINANCIAL AUDIT REPORT</span>
            </div>

            <!-- Project Details -->
            <div class="text-left bg-slate-50 border border-slate-200 rounded-xl p-2.5 text-xs text-right">
                <span class="text-[10px] text-slate-500 font-bold block">المشروع العقاري:</span>
                <strong class="text-sm font-black text-amber-700 block"><?= htmlspecialchars($project['name']) ?></strong>
                <?php if ($isWithoutCapital): ?>
                    <span class="text-[10px] text-indigo-700 font-bold block">بدون رأس مال (مصروفات عميل: <?= htmlspecialchars($project['client_name'] ?: 'العميل') ?>)</span>
                <?php else: ?>
                    <span class="text-[10px] text-slate-600 block">رأس المال: <?= formatMoney($project['capital'], $projCurrency) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Period & Meta Bar -->
        <?php if ($isWithoutCapital): ?>
            <div class="grid grid-cols-4 gap-2 bg-indigo-50/60 border border-indigo-200 rounded-xl p-2 mb-4 text-xs">
                <div>
                    <span class="text-[10px] text-indigo-800 block font-bold">1. إجمالي المصروفات المباشرة:</span>
                    <strong class="text-rose-600 font-black font-mono"><?= formatMoney($totalExpenses, $projCurrency) ?></strong>
                </div>
                <div>
                    <span class="text-[10px] text-indigo-800 block font-bold">2. أتعاب الإشراف (<?= $clientFeePct ?>%):</span>
                    <strong class="text-indigo-900 font-black font-mono">+<?= formatMoney($clientFeeAmount, $projCurrency) ?></strong>
                </div>
                <div>
                    <span class="text-[10px] text-indigo-800 block font-bold">3. المسدد من العميل:</span>
                    <strong class="text-emerald-700 font-black font-mono"><?= formatMoney($effectiveClientPaid, $projCurrency) ?></strong>
                </div>
                <div class="bg-indigo-900 text-white rounded-lg p-1 text-center">
                    <span class="text-[9px] text-indigo-200 block font-bold">المطلوب سداده من العميل:</span>
                    <strong class="text-amber-300 font-black font-mono text-sm"><?= formatMoney(abs($clientDue), $projCurrency) ?></strong>
                </div>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-4 gap-2 bg-slate-50 border border-slate-300 rounded-xl p-2 mb-4 text-xs">
                <div>
                    <span class="text-[10px] text-slate-500 block font-bold">فترة التقرير:</span>
                    <strong class="text-slate-800"><?= !empty($filterMonth) ? 'شهر ' . htmlspecialchars($filterMonth) : 'تقرير المصروفات الكاملة' ?></strong>
                </div>
                <div>
                    <span class="text-[10px] text-slate-500 block font-bold">إجمالي المصروفات المنفذة:</span>
                    <strong class="text-rose-600 font-black font-mono"><?= formatMoney($totalExpenses, $projCurrency) ?></strong>
                </div>
                <div>
                    <span class="text-[10px] text-slate-500 block font-bold">إجمالي ضريبة 15%:</span>
                    <strong class="text-amber-700 font-black font-mono"><?= formatMoney($totalTax, $projCurrency) ?></strong>
                </div>
                <div>
                    <span class="text-[10px] text-slate-500 block font-bold">رصيد خزنة المشروع الحالي:</span>
                    <strong class="text-emerald-700 font-black font-mono"><?= formatMoney($safe['balance'], $projCurrency) ?></strong>
                </div>
            </div>
        <?php endif; ?>

        <!-- Audit Table -->
        <div class="border-2 border-slate-900 rounded-xl overflow-hidden mb-4">
            <table class="w-full text-right text-[11px]">
                <thead class="bg-slate-900 text-white font-bold">
                    <tr>
                        <th class="p-2"># السند</th>
                        <th class="p-2">التاريخ والوقت</th>
                        <th class="p-2">بند / بيان المصروف</th>
                        <th class="p-2">المستفيد / رقم الهوية</th>
                        <th class="p-2 text-center">المبلغ الأساسي</th>
                        <th class="p-2 text-center">الضريبة 15%</th>
                        <th class="p-2 text-center">الإجمالي</th>
                        <th class="p-2 text-center bg-slate-800">حركة الخزنة (قبل / بعد)</th>
                        <th class="p-2 text-center">الإيصال المرفق</th>
                        <th class="p-2 text-center">حالة الاعتماد</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 text-slate-800">
                    <?php if (empty($expenses)): ?>
                        <tr>
                            <td colspan="10" class="p-8 text-center text-slate-400 font-bold">
                                لا توجد مصروفات مسجلة للمشروع في الفترة المحددة.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($expenses as $row): ?>
                            <?php 
                            $bAmt = (float)$row['amount'] - (float)$row['tax_amount'];
                            $hasV = (float)$row['tax_rate'] > 0;
                            $isPend = ($row['status'] ?? '') === 'pending_receipt';
                            $timeStr = !empty($row['transaction_exact_time']) ? date('H:i', strtotime($row['transaction_exact_time'])) : date('H:i', strtotime($row['created_at']));
                            ?>
                            <tr class="hover:bg-slate-50 <?= $isPend ? 'bg-amber-50/40' : '' ?>">
                                <td class="p-2 whitespace-nowrap font-mono font-bold text-slate-900">
                                    <?= htmlspecialchars($row['voucher_number'] ?: ('VCH-' . $row['id'])) ?>
                                </td>
                                <td class="p-2 whitespace-nowrap">
                                    <span><?= date('Y-m-d', strtotime($row['expense_date'])) ?></span>
                                    <span class="text-[9px] text-slate-400 block font-mono"><?= $timeStr ?></span>
                                </td>
                                <td class="p-2 font-bold text-slate-900">
                                    <?= htmlspecialchars($row['title']) ?>
                                    <?php if (!empty($row['description'])): ?>
                                        <span class="text-[9px] text-slate-500 font-normal block"><?= htmlspecialchars($row['description']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-2">
                                    <strong><?= htmlspecialchars($row['beneficiary_name'] ?: '-') ?></strong>
                                    <?php if (!empty($row['beneficiary_id_number'])): ?>
                                        <span class="text-[9px] text-slate-500 font-mono block">هوية: <?= htmlspecialchars($row['beneficiary_id_number']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-2 text-center whitespace-nowrap font-mono">
                                    <?= formatMoney($bAmt, $row['currency']) ?>
                                </td>
                                <td class="p-2 text-center whitespace-nowrap font-mono">
                                    <?= $hasV ? formatMoney($row['tax_amount'], $row['currency']) : '<span class="text-slate-400">0.00</span>' ?>
                                </td>
                                <td class="p-2 text-center whitespace-nowrap font-black text-rose-600 font-mono">
                                    <?= formatMoney($row['amount'], $row['currency']) ?>
                                </td>
                                <td class="p-2 text-center whitespace-nowrap bg-slate-50 font-mono text-[10px] border-x border-slate-200">
                                    <?php if ($isPend): ?>
                                        <span class="text-amber-700 font-bold">معلق (لم يُخصم)</span>
                                    <?php elseif (isset($row['balance_before']) && $row['balance_before'] !== null): ?>
                                        <span class="text-slate-500">قبل: <?= formatMoney($row['balance_before'], $row['currency']) ?></span><br>
                                        <span class="text-slate-900 font-bold">بعد: <?= formatMoney($row['balance_after'], $row['currency']) ?></span>
                                    <?php else: ?>
                                        <span>مخصوم</span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-2 text-center whitespace-nowrap">
                                    <?php 
                                    $rowAtts = $expenseAttachmentsGrouped[$row['id']] ?? [];
                                    if (empty($rowAtts) && !empty($row['invoice_attachment']) && file_exists(APP_PATH . '/' . $row['invoice_attachment'])) {
                                        $rowAtts = [
                                            [
                                                'id' => 0,
                                                'file_path' => $row['invoice_attachment'],
                                                'file_ext' => strtolower(pathinfo($row['invoice_attachment'], PATHINFO_EXTENSION))
                                            ]
                                        ];
                                    }
                                    $attCount = count($rowAtts);
                                    ?>
                                    <?php if ($attCount === 0): ?>
                                        <span class="text-slate-300">-</span>
                                    <?php elseif ($attCount === 1): ?>
                                        <?php $singleAtt = $rowAtts[0]; ?>
                                        <?php if (in_array($singleAtt['file_ext'], ['jpg', 'jpeg', 'png', 'webp'])): ?>
                                            <img src="<?= htmlspecialchars($singleAtt['file_path']) ?>" alt="إيصال" class="w-8 h-8 object-cover rounded mx-auto border border-slate-300">
                                        <?php else: ?>
                                            <span class="text-[9px] font-bold text-indigo-700 bg-indigo-50 border border-indigo-200 px-1 py-0.5 rounded">PDF</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-[9px] font-black text-indigo-800 bg-indigo-50 border border-indigo-200 px-1.5 py-0.5 rounded">
                                            <?= $attCount ?> فواتير
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-2 text-center whitespace-nowrap">
                                    <?php if ($isPend): ?>
                                        <span class="text-amber-800 bg-amber-100 px-1.5 py-0.5 rounded text-[9px] font-bold">معلق</span>
                                    <?php else: ?>
                                        <span class="text-emerald-800 bg-emerald-100 px-1.5 py-0.5 rounded text-[9px] font-bold">مؤكد ومخصوم</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot class="bg-slate-100 font-bold border-t-2 border-slate-900">
                    <tr>
                        <td colspan="4" class="p-2 font-black text-slate-800">المجموع الكلي:</td>
                        <td class="p-2 text-center font-mono font-bold"><?= formatMoney($baseTotal, $projCurrency) ?></td>
                        <td class="p-2 text-center font-mono font-bold"><?= formatMoney($totalTax, $projCurrency) ?></td>
                        <td class="p-2 text-center font-mono font-black text-rose-600"><?= formatMoney($totalExpenses, $projCurrency) ?></td>
                        <td colspan="3" class="p-2 text-left font-normal text-[10px] text-slate-500">
                            رصيد الخزنة المتبقي: <strong class="text-slate-900 font-mono"><?= formatMoney($safe['balance'], $projCurrency) ?></strong>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- Official Signatures & Seal (Print Footer) -->
    <div class="pt-4 border-t-2 border-slate-900 mt-4">
        <div class="grid grid-cols-4 gap-3 text-center text-xs">
            <div class="p-2 border border-slate-300 rounded-lg bg-slate-50 flex flex-col justify-between h-20">
                <span class="text-[9px] font-bold text-slate-500 uppercase">إعداد المحاسب المسؤول</span>
                <span class="text-slate-400 text-[8px] border-t border-slate-200 pt-1">التوقيع والاعتماد</span>
            </div>
            <div class="p-2 border border-slate-300 rounded-lg bg-slate-50 flex flex-col justify-between h-20">
                <span class="text-[9px] font-bold text-slate-500 uppercase">مدير المشروع العقاري</span>
                <strong class="text-[10px] text-slate-800"><?= htmlspecialchars($project['name']) ?></strong>
                <span class="text-slate-400 text-[8px] border-t border-slate-200 pt-1">المطابقة الميدانية</span>
            </div>
            <div class="p-2 border border-slate-300 rounded-lg bg-slate-50 flex flex-col justify-between h-20">
                <span class="text-[9px] font-bold text-slate-500 uppercase">اعتماد المدير المالي</span>
                <span class="text-slate-400 text-[8px] border-t border-slate-200 pt-1">المراجعة والتدقيق</span>
            </div>
            <div class="p-2 border-2 border-dashed border-slate-400 rounded-lg bg-white flex flex-col justify-between h-20">
                <span class="text-[9px] font-bold text-slate-700 uppercase">الختم الرسمي للمؤسسة</span>
                <span class="text-slate-400 text-[8px] border-t border-slate-200 pt-1">خاتم الاعتماد</span>
            </div>
        </div>

        <div class="pt-2 mt-2 border-t border-slate-200 flex items-center justify-between text-[8px] text-slate-400">
            <span>تم استخراج التقرير آلياً عبر نظام فينانس ساس - تاريخ ووقت الاستخراج: <?= date('Y-m-d H:i:s') ?></span>
            <span>صفحة 1 من 1</span>
        </div>
    </div>

</div>

<?php if ($autoPrint): ?>
<script>
window.addEventListener('load', function() {
    setTimeout(function() {
        window.print();
    }, 500);
});
</script>
<?php endif; ?>

</body>
</html>
