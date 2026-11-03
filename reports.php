<?php
require_once __DIR__ . '/config/app.php';
requireLogin();
requireModule('reports');

$currentUser = getCurrentUser();
$activeCompanyId = (int)$currentUser['active_company_id'];
$currency = $currentUser['currency'] ?? 'ج.م';
$db = getDBConnection();
$safe = getCompanyMainSafe($activeCompanyId);

// Filter by Month, custom date, or currency
$filterMonth = $_GET['month'] ?? date('Y-m');
$filterCurrency = $_GET['currency'] ?? '';
$filterFrom = $_GET['from'] ?? date('Y-m-01', strtotime($filterMonth . '-01'));
$filterTo = $_GET['to'] ?? date('Y-m-t', strtotime($filterMonth . '-01'));

$currSql = "";
$currParams = [':cid' => $activeCompanyId, ':from' => $filterFrom, ':to' => $filterTo];
if (!empty($filterCurrency)) {
    $currSql = " AND currency = :curr";
    $currParams[':curr'] = $filterCurrency;
}

// Total deposits in period
$depStmt = $db->prepare("
    SELECT COALESCE(SUM(amount), 0) FROM safe_transactions
    WHERE company_id = :cid AND type = 'deposit' AND transaction_date BETWEEN :from AND :to {$currSql}
");
$depStmt->execute($currParams);
$periodDeposits = (float)$depStmt->fetchColumn();

// Total general expenses in period
$expStmt = $db->prepare("
    SELECT COALESCE(SUM(amount), 0) FROM safe_transactions
    WHERE company_id = :cid AND type = 'expense' AND transaction_date BETWEEN :from AND :to {$currSql}
");
$expStmt->execute($currParams);
$periodExpenses = (float)$expStmt->fetchColumn();

// Total salaries in period
$salStmt = $db->prepare("
    SELECT COALESCE(SUM(amount), 0) FROM safe_transactions
    WHERE company_id = :cid AND type = 'salary' AND transaction_date BETWEEN :from AND :to {$currSql}
");
$salStmt->execute($currParams);
$periodSalaries = (float)$salStmt->fetchColumn();

$totalOutflow = $periodExpenses + $periodSalaries;
$netCashFlow = $periodDeposits - $totalOutflow;
$reportCurrency = !empty($filterCurrency) ? $filterCurrency : $currency;

// Expenses by category for Chart.js
$catStmt = $db->prepare("
    SELECT ec.name, COALESCE(SUM(e.amount), 0) as total_amount
    FROM expenses e
    JOIN expense_categories ec ON e.category_id = ec.id
    WHERE e.company_id = :cid AND e.expense_date BETWEEN :from AND :to
    GROUP BY ec.id
    ORDER BY total_amount DESC
");
$catStmt->execute([':cid' => $activeCompanyId, ':from' => $filterFrom, ':to' => $filterTo]);
$categoryBreakdown = $catStmt->fetchAll();

// Monthly Comparison (Last 6 Months)
$monthlyStats = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-{$i} month"));
    
    // Inflow
    $mInStmt = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) FROM safe_transactions
        WHERE company_id = :cid AND type = 'deposit' AND strftime('%Y-%m', transaction_date) = :m
    ");
    $mInStmt->execute([':cid' => $activeCompanyId, ':m' => $m]);
    $inVal = (float)$mInStmt->fetchColumn();

    // Outflow
    $mOutStmt = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) FROM safe_transactions
        WHERE company_id = :cid AND (type = 'expense' OR type = 'salary') AND strftime('%Y-%m', transaction_date) = :m
    ");
    $mOutStmt->execute([':cid' => $activeCompanyId, ':m' => $m]);
    $outVal = (float)$mOutStmt->fetchColumn();

    $monthlyStats[] = [
        'month' => $m,
        'deposits' => $inVal,
        'expenses' => $outVal,
        'net' => $inVal - $outVal
    ];
}

// Chart arrays
$catLabels = [];
$catValues = [];
foreach ($categoryBreakdown as $cb) {
    $catLabels[] = $cb['name'];
    $catValues[] = (float)$cb['total_amount'];
}

// If salaries exist, add to chart
if ($periodSalaries > 0) {
    $catLabels[] = 'مرتبات وأجور';
    $catValues[] = $periodSalaries;
}

$pageTitle = 'التقارير المالية الشاملة';
require_once __DIR__ . '/includes/header.php';
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-slate-900">التقارير المالية وتحليل النفقات</h1>
            <p class="text-xs text-slate-500 mt-1">
                تقرير شامل للإيداعات والمصروفات ومقارنة الشهور ورسوم بيانية تفاعلية
            </p>
        </div>
        <div class="flex items-center space-x-2 space-x-reverse no-print">
            <button onclick="window.print()" class="inline-flex items-center bg-slate-800 hover:bg-slate-900 text-white font-bold px-4 py-2.5 rounded-xl shadow-md transition text-xs sm:text-sm">
                <i class="fa-solid fa-print ml-2"></i>
                طباعة التقرير المالي
            </button>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="no-print bg-white rounded-2xl p-4 border border-slate-200/80 shadow-xs">
        <form method="GET" action="reports.php" class="grid grid-cols-1 sm:grid-cols-5 gap-3 items-end text-xs">
            <div>
                <label class="block font-bold text-slate-700 mb-1">تحديد الشهر</label>
                <input type="month" name="month" value="<?= htmlspecialchars($filterMonth) ?>" onchange="this.form.submit()" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 font-bold focus:outline-none">
            </div>
            <div>
                <label class="block font-bold text-slate-700 mb-1">العملة</label>
                <select name="currency" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 font-bold focus:outline-none">
                    <option value="">-- كل العملات --</option>
                    <?php foreach (getSupportedCurrencies() as $cCode => $cLabel): ?>
                        <option value="<?= $cCode ?>" <?= $filterCurrency === $cCode ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cLabel) ?> (<?= htmlspecialchars($cCode) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block font-bold text-slate-700 mb-1">من تاريخ</label>
                <input type="date" name="from" value="<?= htmlspecialchars($filterFrom) ?>" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 focus:outline-none">
            </div>
            <div>
                <label class="block font-bold text-slate-700 mb-1">إلى تاريخ</label>
                <input type="date" name="to" value="<?= htmlspecialchars($filterTo) ?>" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 focus:outline-none">
            </div>
            <div class="flex items-center space-x-2 space-x-reverse">
                <button type="submit" class="flex-1 bg-brand-600 hover:bg-brand-700 text-white font-bold py-2 px-3 rounded-xl transition text-center">
                    <i class="fa-solid fa-chart-line ml-1"></i> عرض التقرير
                </button>
                <a href="reports.php" class="bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold py-2 px-3 rounded-xl transition text-center" title="الشهر الحالي">
                    <i class="fa-solid fa-rotate-right"></i>
                </a>
            </div>
        </form>
    </div>

    <!-- Printable Header Banner -->
    <div class="bg-white rounded-3xl p-5 sm:p-6 border border-slate-200/80 shadow-xs">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between pb-4 border-b border-slate-100 gap-2">
            <div>
                <span class="text-xs font-bold text-brand-600">تقرير الأداء المالي</span>
                <h2 class="text-xl font-black text-slate-800"><?= htmlspecialchars($currentUser['company_name'] ?? 'الشركة') ?></h2>
                <?php if (!empty($filterCurrency)): ?>
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold bg-brand-50 text-brand-700 mt-1 border border-brand-200">
                        محدد بعملة: <?= htmlspecialchars($filterCurrency) ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="text-right sm:text-left text-xs text-slate-500 font-medium">
                الفترة من: <span class="font-bold text-slate-800"><?= htmlspecialchars($filterFrom) ?></span> إلى: <span class="font-bold text-slate-800"><?= htmlspecialchars($filterTo) ?></span>
            </div>
        </div>

        <!-- 4 Metric Cards (Clean Stack on Mobile) -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 mt-5">
            <div class="bg-emerald-50/70 border border-emerald-200/80 rounded-2xl p-4 flex items-center justify-between">
                <div>
                    <span class="text-xs font-bold text-emerald-700 block">إجمالي الإيداعات</span>
                    <p class="text-xl sm:text-2xl font-black text-emerald-800 mt-1"><?= formatMoney($periodDeposits, $reportCurrency) ?></p>
                    <span class="text-[11px] text-emerald-600 font-medium block mt-0.5">داخل الخزنة</span>
                </div>
                <div class="w-11 h-11 rounded-xl bg-emerald-100 text-emerald-600 flex items-center justify-center text-lg shrink-0">
                    <i class="fa-solid fa-arrow-down-long"></i>
                </div>
            </div>

            <div class="bg-rose-50/70 border border-rose-200/80 rounded-2xl p-4 flex items-center justify-between">
                <div>
                    <span class="text-xs font-bold text-rose-700 block">إجمالي المصروفات</span>
                    <p class="text-xl sm:text-2xl font-black text-rose-800 mt-1"><?= formatMoney($periodExpenses, $reportCurrency) ?></p>
                    <span class="text-[11px] text-rose-600 font-medium block mt-0.5">نفقات تشغيلية وبنود</span>
                </div>
                <div class="w-11 h-11 rounded-xl bg-rose-100 text-rose-600 flex items-center justify-center text-lg shrink-0">
                    <i class="fa-solid fa-arrow-up-long"></i>
                </div>
            </div>

            <div class="bg-purple-50/70 border border-purple-200/80 rounded-2xl p-4 flex items-center justify-between">
                <div>
                    <span class="text-xs font-bold text-purple-700 block">رواتب وأجور الموظفين</span>
                    <p class="text-xl sm:text-2xl font-black text-purple-800 mt-1"><?= formatMoney($periodSalaries, $reportCurrency) ?></p>
                    <span class="text-[11px] text-purple-600 font-medium block mt-0.5">مسيرات الرواتب</span>
                </div>
                <div class="w-11 h-11 rounded-xl bg-purple-100 text-purple-600 flex items-center justify-center text-lg shrink-0">
                    <i class="fa-solid fa-users"></i>
                </div>
            </div>

            <div class="<?= $netCashFlow >= 0 ? 'bg-indigo-50/70 border-indigo-200/80 text-brand-800' : 'bg-amber-50/70 border-amber-200/80 text-amber-800' ?> border rounded-2xl p-4 flex items-center justify-between">
                <div>
                    <span class="text-xs font-bold block">صافي التدفق النقدي</span>
                    <p class="text-xl sm:text-2xl font-black mt-1"><?= formatMoney($netCashFlow, $reportCurrency) ?></p>
                    <span class="text-[11px] font-bold block mt-0.5"><?= $netCashFlow >= 0 ? 'فائض سيولة إيجابي' : 'عجز سيولة' ?></span>
                </div>
                <div class="w-11 h-11 rounded-xl <?= $netCashFlow >= 0 ? 'bg-indigo-100 text-brand-600' : 'bg-amber-100 text-amber-600' ?> flex items-center justify-center text-lg shrink-0">
                    <i class="fa-solid <?= $netCashFlow >= 0 ? 'fa-scale-balanced' : 'fa-triangle-exclamation' ?>"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Section -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 no-print">
        <!-- Monthly Inflow vs Outflow Comparison Bar Chart -->
        <div class="bg-white rounded-3xl p-5 sm:p-6 border border-slate-200/80 shadow-xs">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="font-black text-slate-800 text-base">مقارنة الشهور (آخر 6 أشهر)</h3>
                    <p class="text-xs text-slate-400">مقارنة حركة الإيداعات مقابل المصروفات شهرياً</p>
                </div>
                <div class="w-8 h-8 rounded-lg bg-indigo-50 text-brand-600 flex items-center justify-center text-sm">
                    <i class="fa-solid fa-chart-column"></i>
                </div>
            </div>
            <div class="relative h-56 sm:h-64 w-full">
                <canvas id="monthlyChart"></canvas>
            </div>
        </div>

        <!-- Expense Category Breakdown Doughnut Chart -->
        <div class="bg-white rounded-3xl p-5 sm:p-6 border border-slate-200/80 shadow-xs">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="font-black text-slate-800 text-base">توزيع المصروفات حسب البند</h3>
                    <p class="text-xs text-slate-400">نسبة إنفاق كل بند خلال الفترة المحددة</p>
                </div>
                <div class="w-8 h-8 rounded-lg bg-rose-50 text-rose-600 flex items-center justify-center text-sm">
                    <i class="fa-solid fa-chart-pie"></i>
                </div>
            </div>
            <div class="relative h-56 sm:h-64 w-full flex items-center justify-center">
                <?php if (empty($catValues) || array_sum($catValues) <= 0): ?>
                    <p class="text-slate-400 text-xs font-medium">لا توجد مصروفات مسجلة لهذه الفترة لعرض الرسم البياني.</p>
                <?php else: ?>
                    <canvas id="categoryChart"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Monthly Comparison Section (Desktop Table + Mobile Cards Feed) -->
    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
        <div class="p-4 sm:p-5 border-b border-slate-100 flex items-center justify-between">
            <h3 class="font-black text-slate-800 text-sm sm:text-base">جدول المقارنة الشهرية للأشهر الستة الأخيرة</h3>
            <span class="text-[11px] text-slate-400 font-medium">حسب حركات الخزنة</span>
        </div>

        <!-- Desktop Table View -->
        <div class="hidden md:block overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="bg-slate-50 text-slate-500 font-bold border-b border-slate-200">
                    <tr>
                        <th class="p-3.5">الشهر</th>
                        <th class="p-3.5">إجمالي الإيداعات</th>
                        <th class="p-3.5">إجمالي المصروفات</th>
                        <th class="p-3.5">صافي الشهر</th>
                        <th class="p-3.5">التقييم</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">
                    <?php foreach ($monthlyStats as $ms): ?>
                        <tr class="hover:bg-slate-50/70 transition">
                            <td class="p-3.5 font-bold text-slate-900 font-mono">
                                <?= htmlspecialchars($ms['month']) ?>
                            </td>
                            <td class="p-3.5 font-black text-emerald-600">
                                <?= formatMoney($ms['deposits'], $currency) ?>
                            </td>
                            <td class="p-3.5 font-black text-rose-600">
                                <?= formatMoney($ms['expenses'], $currency) ?>
                            </td>
                            <td class="p-3.5 font-black <?= $ms['net'] >= 0 ? 'text-indigo-600' : 'text-amber-600' ?>">
                                <?= formatMoney($ms['net'], $currency) ?>
                            </td>
                            <td class="p-3.5">
                                <?php if ($ms['net'] > 0): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800">
                                        <i class="fa-solid fa-arrow-trend-up ml-1"></i> فائض
                                    </span>
                                <?php elseif ($ms['net'] < 0): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-100 text-rose-800">
                                        <i class="fa-solid fa-arrow-trend-down ml-1"></i> عجز
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-600">
                                        متوازن
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Mobile Monthly Cards View (Shown on small screens) -->
        <div class="block md:hidden divide-y divide-slate-100">
            <?php foreach ($monthlyStats as $ms): ?>
                <div class="p-4 bg-white hover:bg-slate-50/60 transition">
                    <div class="flex items-center justify-between mb-2.5">
                        <span class="font-mono font-black text-slate-900 text-sm"><?= htmlspecialchars($ms['month']) ?></span>
                        <?php if ($ms['net'] > 0): ?>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800">
                                <i class="fa-solid fa-arrow-trend-up ml-1"></i> فائض
                            </span>
                        <?php elseif ($ms['net'] < 0): ?>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-100 text-rose-800">
                                <i class="fa-solid fa-arrow-trend-down ml-1"></i> عجز
                            </span>
                        <?php else: ?>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-600">
                                متوازن
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="grid grid-cols-3 gap-2 text-center pt-1">
                        <div class="bg-emerald-50/60 rounded-xl p-2 border border-emerald-100">
                            <span class="text-[10px] text-emerald-700 font-bold block">إيداعات</span>
                            <span class="text-xs font-black text-emerald-800 block mt-0.5"><?= formatMoney($ms['deposits'], $currency) ?></span>
                        </div>
                        <div class="bg-rose-50/60 rounded-xl p-2 border border-rose-100">
                            <span class="text-[10px] text-rose-700 font-bold block">مصروفات</span>
                            <span class="text-xs font-black text-rose-800 block mt-0.5"><?= formatMoney($ms['expenses'], $currency) ?></span>
                        </div>
                        <div class="<?= $ms['net'] >= 0 ? 'bg-indigo-50/60 border-indigo-100' : 'bg-amber-50/60 border-amber-100' ?> rounded-xl p-2 border">
                            <span class="text-[10px] <?= $ms['net'] >= 0 ? 'text-indigo-700' : 'text-amber-700' ?> font-bold block">الصافي</span>
                            <span class="text-xs font-black <?= $ms['net'] >= 0 ? 'text-indigo-800' : 'text-amber-800' ?> block mt-0.5"><?= formatMoney($ms['net'], $currency) ?></span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // 1. Monthly Comparison Chart
    const monthlyCtx = document.getElementById('monthlyChart')?.getContext('2d');
    if (monthlyCtx) {
        new Chart(monthlyCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_column($monthlyStats, 'month')) ?>,
                datasets: [
                    {
                        label: 'الإيداعات',
                        data: <?= json_encode(array_column($monthlyStats, 'deposits')) ?>,
                        backgroundColor: '#10b981',
                        borderRadius: 6
                    },
                    {
                        label: 'المصروفات',
                        data: <?= json_encode(array_column($monthlyStats, 'expenses')) ?>,
                        backgroundColor: '#ef4444',
                        borderRadius: 6
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top', rtl: true, labels: { font: { family: 'Cairo' } } }
                },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true }
                }
            }
        });
    }

    // 2. Categories Doughnut Chart
    const catCtx = document.getElementById('categoryChart')?.getContext('2d');
    if (catCtx) {
        new Chart(catCtx, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($catLabels) ?>,
                datasets: [{
                    data: <?= json_encode($catValues) ?>,
                    backgroundColor: [
                        '#ef4444', '#f59e0b', '#3b82f6', '#10b981', '#8b5cf6', '#ec4899', '#64748b'
                    ],
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', rtl: true, labels: { font: { family: 'Cairo', size: 11 } } }
                }
            }
        });
    }
});
</script>

<?php include_once __DIR__ . '/includes/footer.php'; ?>
