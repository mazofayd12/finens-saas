<?php
require_once __DIR__ . '/config/app.php';
requireLogin();
requireModule('treasury');

$currentUser = getCurrentUser();
$activeCompanyId = (int)$currentUser['active_company_id'];
$currency = $currentUser['currency'] ?? 'ج.م';
$db = getDBConnection();

$safe = getCompanyMainSafe($activeCompanyId);

// Handle POST: Deposit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('خطأ في التحقق من صحة الطلب');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'deposit') {
        $amount = (float)($_POST['amount'] ?? 0);
        $category = trim($_POST['category'] ?? 'إيداع نقدي');
        $description = trim($_POST['description'] ?? '');
        $txDate = trim($_POST['transaction_date'] ?? date('Y-m-d'));

        if ($amount <= 0) {
            setFlash('error', 'يرجى إدخال مبلغ إيداع صحيح أكبر من صفر.');
        } else {
            try {
                recordSafeTransaction(
                    $activeCompanyId,
                    $safe['id'],
                    $currentUser['id'],
                    'deposit',
                    $category,
                    $amount,
                    $description,
                    $txDate
                );
                setFlash('success', "تم إيداع مبلغ " . formatMoney($amount, $currency) . " في الخزنة بنجاح!");
                header('Location: treasury.php');
                exit;
            } catch (Exception $e) {
                setFlash('error', $e->getMessage());
            }
        }
    }
}

// Fetch Safe Ledger with filters
$filterType = $_GET['type'] ?? '';
$filterFrom = $_GET['from'] ?? date('Y-m-01');
$filterTo = $_GET['to'] ?? date('Y-m-d');

$query = "
    SELECT st.*, u.name as user_name
    FROM safe_transactions st
    LEFT JOIN users u ON st.user_id = u.id
    WHERE st.company_id = :cid
";
$params = [':cid' => $activeCompanyId];

if (!empty($filterType)) {
    $query .= " AND st.type = :type";
    $params[':type'] = $filterType;
}
if (!empty($filterFrom)) {
    $query .= " AND st.transaction_date >= :from";
    $params[':from'] = $filterFrom;
}
if (!empty($filterTo)) {
    $query .= " AND st.transaction_date <= :to";
    $params[':to'] = $filterTo;
}
$query .= " ORDER BY st.id DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

// Period Totals
$periodDeposits = 0;
$periodWithdrawals = 0;
foreach ($transactions as $t) {
    if ($t['type'] === 'deposit') {
        $periodDeposits += (float)$t['amount'];
    } else {
        $periodWithdrawals += (float)$t['amount'];
    }
}

$pageTitle = 'الخزنة والإيداعات';
require_once __DIR__ . '/includes/header.php';
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-slate-900">الخزنة والحركات النقدية</h1>
            <p class="text-xs text-slate-500 mt-1">
                متابعة رصيد الخزنة الفعلي، تسجيل الإيداعات النقدية، وكشف حساب تفصيلي للحركات
            </p>
        </div>
        <div class="flex items-center space-x-2 space-x-reverse">
            <button onclick="openModal('quick-deposit-modal')" class="inline-flex items-center bg-emerald-600 hover:bg-emerald-700 text-white font-bold px-4 py-2.5 rounded-xl shadow-md transition text-xs sm:text-sm">
                <i class="fa-solid fa-circle-plus ml-2"></i>
                إيداع نقدية في الخزنة
            </button>
            <button onclick="window.print()" class="no-print inline-flex items-center bg-white hover:bg-slate-100 border border-slate-300 text-slate-700 font-bold px-3 py-2 rounded-xl text-xs transition">
                <i class="fa-solid fa-print ml-1.5"></i> طباعة كشف الخزنة
            </button>
        </div>
    </div>

    <!-- Safe Card & Period Stats -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
        <!-- Main Safe Balance -->
        <div class="gradient-safe rounded-3xl p-6 text-white shadow-xl shadow-emerald-500/10 flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-black uppercase tracking-wider bg-white/20 px-3 py-1 rounded-full">
                        <i class="fa-solid fa-vault ml-1"></i> <?= htmlspecialchars($safe['name']) ?>
                    </span>
                    <i class="fa-solid fa-coins text-white/50 text-2xl"></i>
                </div>
                <p class="text-xs text-emerald-100 font-bold mt-2">الرصيد الفعلي الحالي بالخزنة</p>
                <h2 class="text-3xl sm:text-4xl font-black mt-2 tracking-tight">
                    <?= formatMoney($safe['balance'], $currency) ?>
                </h2>
            </div>
            <div class="pt-4 mt-3 border-t border-white/20 text-[11px] text-emerald-100 flex items-center justify-between">
                <span>الشركة: <?= htmlspecialchars($currentUser['company_name'] ?? '') ?></span>
                <span class="font-bold">جاهز للمعاملات</span>
            </div>
        </div>

        <!-- Period Deposits -->
        <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs flex flex-col justify-between">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-slate-500">إجمالي إيداعات الفترة المحددة</span>
                <div class="w-9 h-9 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-sm">
                    <i class="fa-solid fa-arrow-down"></i>
                </div>
            </div>
            <div class="mt-4">
                <h3 class="text-2xl font-black text-emerald-600"><?= formatMoney($periodDeposits, $currency) ?></h3>
                <p class="text-[11px] text-slate-400 mt-1">خلال الفترة من <?= htmlspecialchars($filterFrom) ?> إلى <?= htmlspecialchars($filterTo) ?></p>
            </div>
        </div>

        <!-- Period Withdrawals -->
        <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs flex flex-col justify-between">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-slate-500">إجمالي مسحوبات ومصروفات الفترة</span>
                <div class="w-9 h-9 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center text-sm">
                    <i class="fa-solid fa-arrow-up"></i>
                </div>
            </div>
            <div class="mt-4">
                <h3 class="text-2xl font-black text-rose-600"><?= formatMoney($periodWithdrawals, $currency) ?></h3>
                <p class="text-[11px] text-slate-400 mt-1">مصروفات + رواتب مسحوبة من الخزنة</p>
            </div>
        </div>
    </div>

    <!-- Filter Form -->
    <div class="no-print bg-white rounded-2xl p-4 border border-slate-200/80 shadow-xs">
        <form method="GET" action="treasury.php" class="grid grid-cols-1 sm:grid-cols-4 gap-3 items-end text-xs">
            <div>
                <label class="block font-bold text-slate-700 mb-1">نوع الحركة</label>
                <select name="type" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 focus:bg-white focus:outline-none">
                    <option value="">-- كل الحركات --</option>
                    <option value="deposit" <?= $filterType === 'deposit' ? 'selected' : '' ?>>إيداعات نقدية فقط</option>
                    <option value="expense" <?= $filterType === 'expense' ? 'selected' : '' ?>>مصروفات فقط</option>
                    <option value="salary" <?= $filterType === 'salary' ? 'selected' : '' ?>>رواتب فقط</option>
                </select>
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
                <a href="treasury.php" class="bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold py-2 px-3 rounded-xl transition text-center" title="إعادة ضبط">
                    <i class="fa-solid fa-rotate-right"></i>
                </a>
            </div>
        </form>
    </div>

    <!-- Ledger Table -->
    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
        <div class="p-4 sm:p-5 border-b border-slate-100 flex items-center justify-between">
            <h3 class="font-black text-slate-800 text-base">كشف حساب الخزنة التفصيلي (Ledger)</h3>
            <span class="text-xs text-slate-400 font-medium"><?= count($transactions) ?> حركة مسجلة</span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="bg-slate-50 text-slate-500 font-bold border-b border-slate-200">
                    <tr>
                        <th class="p-3.5">#</th>
                        <th class="p-3.5">النوع</th>
                        <th class="p-3.5">البند / البيان</th>
                        <th class="p-3.5">المبلغ</th>
                        <th class="p-3.5">الرصيد قبل</th>
                        <th class="p-3.5">الرصيد بعد</th>
                        <th class="p-3.5">التاريخ</th>
                        <th class="p-3.5">المسؤول</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">
                    <?php if (empty($transactions)): ?>
                        <tr>
                            <td colspan="8" class="p-8 text-center text-slate-400">لا توجد حركات مسجلة خلال الفترة المحددة.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($transactions as $tx): ?>
                            <tr class="hover:bg-slate-50/70 transition">
                                <td class="p-3.5 font-mono text-slate-400 text-[11px]">
                                    #<?= $tx['id'] ?>
                                </td>
                                <td class="p-3.5 whitespace-nowrap">
                                    <?php if ($tx['type'] === 'deposit'): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full font-bold text-[10px] bg-emerald-100 text-emerald-800">
                                            <i class="fa-solid fa-arrow-down ml-1"></i> إيداع
                                        </span>
                                    <?php elseif ($tx['type'] === 'salary'): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full font-bold text-[10px] bg-purple-100 text-purple-800">
                                            <i class="fa-solid fa-money-check-dollar ml-1"></i> راتب
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full font-bold text-[10px] bg-rose-100 text-rose-800">
                                            <i class="fa-solid fa-arrow-up ml-1"></i> مصروف
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3.5 font-bold text-slate-800">
                                    <?= htmlspecialchars($tx['category']) ?>
                                    <?php if (!empty($tx['description'])): ?>
                                        <p class="text-[11px] font-normal text-slate-500 mt-0.5"><?= htmlspecialchars($tx['description']) ?></p>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3.5 whitespace-nowrap font-black <?= $tx['type'] === 'deposit' ? 'text-emerald-600' : 'text-rose-600' ?>">
                                    <?= $tx['type'] === 'deposit' ? '+' : '-' ?><?= formatMoney($tx['amount'], $currency) ?>
                                </td>
                                <td class="p-3.5 whitespace-nowrap text-slate-500">
                                    <?= formatMoney($tx['balance_before'], $currency) ?>
                                </td>
                                <td class="p-3.5 whitespace-nowrap font-bold text-slate-800">
                                    <?= formatMoney($tx['balance_after'], $currency) ?>
                                </td>
                                <td class="p-3.5 whitespace-nowrap text-slate-600">
                                    <?= htmlspecialchars($tx['transaction_date']) ?>
                                </td>
                                <td class="p-3.5 whitespace-nowrap text-slate-500">
                                    <?= htmlspecialchars($tx['user_name'] ?? 'مستخدم') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include_once __DIR__ . '/includes/footer.php'; ?>
