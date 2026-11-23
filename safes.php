<?php
require_once __DIR__ . '/config/app.php';
requireLogin();
requireModule('treasury');

if (!hasModule('projects')) {
    header('Location: treasury.php');
    exit;
}

$currentUser = getCurrentUser();
$activeCompanyId = (int)$currentUser['active_company_id'];
$currency = $currentUser['currency'] ?? 'ج.م';
$db = getDBConnection();

// Handle POST actions: Inter-safe Transfer, Direct Deposit, Direct Withdrawal
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('خطأ في التحقق من صحة الطلب');
    }

    $action = $_POST['action'] ?? '';

    // 1. Inter-safe Transfer
    if ($action === 'transfer_safes') {
        $fromSafeId = (int)($_POST['from_safe_id'] ?? 0);
        $toSafeId = (int)($_POST['to_safe_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $txCurrency = trim($_POST['currency'] ?? $currency);
        $description = trim($_POST['description'] ?? '');
        $txDate = trim($_POST['transaction_date'] ?? date('Y-m-d'));

        if ($fromSafeId <= 0 || $toSafeId <= 0 || $fromSafeId === $toSafeId || $amount <= 0) {
            setFlash('error', 'يرجى اختيار الخزنة المصدر والخزنة الوجهة ومبلغ صحيح أكبر من صفر.');
        } else {
            try {
                recordInterSafeTransfer(
                    $activeCompanyId,
                    $fromSafeId,
                    $toSafeId,
                    $currentUser['id'],
                    $amount,
                    $description,
                    $txDate,
                    $txCurrency
                );

                setFlash('success', "تم تحويل مبلغ " . formatMoney($amount, $txCurrency) . " بين الخزن بنجاح وتحديث الأرصدة لحظياً!");
                header('Location: safes.php');
                exit;
            } catch (Exception $e) {
                setFlash('error', $e->getMessage());
            }
        }
    }

    // 2. Deposit into specific safe
    elseif ($action === 'deposit_safe') {
        $safeId = (int)($_POST['safe_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $depCurrency = trim($_POST['currency'] ?? $currency);
        $category = trim($_POST['category'] ?? 'إيداع نقدي');
        $description = trim($_POST['description'] ?? '');
        $txDate = trim($_POST['transaction_date'] ?? date('Y-m-d'));

        if ($safeId <= 0 || $amount <= 0) {
            setFlash('error', 'يرجى اختيار الخزنة ومبلغ صحيح.');
        } else {
            try {
                // Check if safe is linked to a project
                $sStmt = $db->prepare("SELECT project_id, name FROM safes WHERE id = :id AND company_id = :cid");
                $sStmt->execute([':id' => $safeId, ':cid' => $activeCompanyId]);
                $sRow = $sStmt->fetch();
                $pid = !empty($sRow['project_id']) ? (int)$sRow['project_id'] : null;

                recordSafeTransaction(
                    $activeCompanyId,
                    $safeId,
                    $currentUser['id'],
                    'deposit',
                    $category,
                    $amount,
                    $description,
                    $txDate,
                    $depCurrency,
                    $pid
                );

                setFlash('success', "تم إيداع مبلغ " . formatMoney($amount, $depCurrency) . " في ({$sRow['name']}) بنجاح!");
                header('Location: safes.php');
                exit;
            } catch (Exception $e) {
                setFlash('error', $e->getMessage());
            }
        }
    }
}

// Fetch all safes
$safes = getCompanySafes($activeCompanyId);

// Filter transactions by safe
$filterSafeId = !empty($_GET['safe_id']) ? (int)$_GET['safe_id'] : null;
$filterType = trim($_GET['type'] ?? '');
$filterFrom = trim($_GET['from'] ?? date('Y-m-01'));
$filterTo = trim($_GET['to'] ?? date('Y-m-d'));

$txQuery = "
    SELECT st.*, s.name as safe_name, u.name as user_name, p.name as project_name
    FROM safe_transactions st
    JOIN safes s ON st.safe_id = s.id
    LEFT JOIN users u ON st.user_id = u.id
    LEFT JOIN projects p ON st.project_id = p.id
    WHERE st.company_id = :cid
";
$txParams = [':cid' => $activeCompanyId];

if ($filterSafeId) {
    $txQuery .= " AND st.safe_id = :sid";
    $txParams[':sid'] = $filterSafeId;
}
if (!empty($filterType)) {
    $txQuery .= " AND st.type = :type";
    $txParams[':type'] = $filterType;
}
if (!empty($filterFrom)) {
    $txQuery .= " AND st.transaction_date >= :from";
    $txParams[':from'] = $filterFrom;
}
if (!empty($filterTo)) {
    $txQuery .= " AND st.transaction_date <= :to";
    $txParams[':to'] = $filterTo;
}
$txQuery .= " ORDER BY st.id DESC LIMIT 100";

$txStmt = $db->prepare($txQuery);
$txStmt->execute($txParams);
$transactions = $txStmt->fetchAll();

$pageTitle = 'الخزن المتعددة وحسابات المشاريع';
require_once __DIR__ . '/includes/header.php';
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center space-x-2 space-x-reverse">
                <span class="w-10 h-10 rounded-xl bg-emerald-600 text-white flex items-center justify-center font-bold text-lg shadow-md">
                    <i class="fa-solid fa-vault"></i>
                </span>
                <div>
                    <h1 class="text-2xl font-black text-slate-900">الخزن المتعددة وحسابات المشاريع</h1>
                    <p class="text-xs text-slate-500">متابعة أرصدة الخزنة العامة للشركة وخزن المشاريع والتحويلات البينية</p>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <button onclick="openModal('inter-transfer-modal')" class="inline-flex items-center bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-4 py-2.5 rounded-xl shadow-md transition text-xs sm:text-sm">
                <i class="fa-solid fa-money-bill-transfer ml-1.5"></i>
                تحويل مالي بين الخزن
            </button>
            <button onclick="openModal('deposit-any-safe-modal')" class="inline-flex items-center bg-emerald-600 hover:bg-emerald-700 text-white font-bold px-4 py-2.5 rounded-xl shadow-md transition text-xs sm:text-sm">
                <i class="fa-solid fa-circle-plus ml-1.5"></i>
                إيداع في خزنة
            </button>
        </div>
    </div>

    <!-- SAFES CARDS GRID -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
        <?php foreach ($safes as $sf): ?>
            <?php
            $isMain = $sf['type'] === 'main';
            $sfBals = $sf['balances'] ?? [$currency => $sf['balance']];
            ?>
            <div class="bg-white rounded-3xl p-5 border <?= $isMain ? 'border-indigo-300 shadow-sm ring-1 ring-indigo-200' : 'border-slate-200' ?> flex flex-col justify-between space-y-4">
                <div>
                    <!-- Top Badge -->
                    <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-bold <?= $isMain ? 'bg-indigo-100 text-indigo-800' : 'bg-amber-100 text-amber-800' ?>">
                            <i class="fa-solid <?= $isMain ? 'fa-building-columns' : 'fa-helmet-safety' ?> ml-1 text-[10px]"></i>
                            <?= $isMain ? 'الخزنة العامة للشركة' : 'خزنة مشروع عقاري' ?>
                        </span>
                        <span class="text-[11px] text-slate-400 font-mono">ID: #<?= $sf['id'] ?></span>
                    </div>

                    <!-- Safe Name & Linked Project -->
                    <div class="pt-3">
                        <h3 class="font-black text-slate-900 text-base leading-tight">
                            <?= htmlspecialchars($sf['name']) ?>
                        </h3>
                        <?php if (!empty($sf['project_name'])): ?>
                            <a href="project_view.php?id=<?= $sf['project_id'] ?>" class="text-xs font-bold text-amber-600 hover:underline flex items-center mt-1">
                                <i class="fa-solid fa-city ml-1"></i>
                                مشروع: <?= htmlspecialchars($sf['project_name']) ?>
                            </a>
                        <?php else: ?>
                            <span class="text-xs text-slate-400 block mt-1">الخزنة المالية المركزية</span>
                        <?php endif; ?>
                    </div>

                    <!-- Balances Box -->
                    <div class="bg-slate-50 rounded-2xl p-3.5 border border-slate-200/80 mt-4 space-y-1.5">
                        <span class="text-[10px] font-bold text-slate-500 uppercase tracking-wider block">الأرصدة المتوفرة:</span>
                        <?php foreach ($sfBals as $bCurr => $bVal): ?>
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-bold text-slate-600"><?= $bCurr ?>:</span>
                                <span class="font-black <?= (float)$bVal > 0 ? 'text-emerald-600' : 'text-slate-500' ?> text-base dir-ltr">
                                    <?= formatMoney($bVal, $bCurr) ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Footer Quick Actions -->
                <div class="flex items-center gap-2 pt-2 border-t border-slate-100">
                    <a href="safes.php?safe_id=<?= $sf['id'] ?>" class="flex-1 text-center bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold py-2 px-3 rounded-xl text-xs transition">
                        <i class="fa-solid fa-list-check ml-1"></i> كشف الحركات
                    </a>
                    <?php if (!empty($sf['project_id'])): ?>
                        <a href="project_view.php?id=<?= $sf['project_id'] ?>" class="bg-amber-50 hover:bg-amber-100 text-amber-700 font-bold p-2 rounded-xl text-xs transition" title="فتح ملف المشروع">
                            <i class="fa-solid fa-arrow-up-right-from-square"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- TRANSACTIONS LEDGER WITH FILTERS -->
    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden space-y-4 p-5">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pb-3 border-b border-slate-100">
            <div class="flex items-center space-x-2 space-x-reverse">
                <i class="fa-solid fa-clock-rotate-left text-slate-500"></i>
                <h3 class="font-black text-slate-800 text-sm">كشف حركات الخزن والتحويلات</h3>
            </div>
            
            <!-- Filters -->
            <form action="safes.php" method="GET" class="flex flex-wrap items-center gap-2 text-xs">
                <select name="safe_id" class="bg-slate-50 border border-slate-300 rounded-xl px-2.5 py-1.5 font-bold focus:outline-none">
                    <option value="">جميع الخزن</option>
                    <?php foreach ($safes as $sf): ?>
                        <option value="<?= $sf['id'] ?>" <?= $filterSafeId === (int)$sf['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($sf['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="type" class="bg-slate-50 border border-slate-300 rounded-xl px-2.5 py-1.5 font-bold focus:outline-none">
                    <option value="">كافة العمليات</option>
                    <option value="deposit" <?= $filterType === 'deposit' ? 'selected' : '' ?>>إيداعات</option>
                    <option value="expense" <?= $filterType === 'expense' ? 'selected' : '' ?>>مصروفات وسحوبات</option>
                </select>

                <button type="submit" class="bg-brand-600 text-white font-bold px-3 py-1.5 rounded-xl hover:bg-brand-700 transition">
                    تصفية
                </button>
                <a href="safes.php" class="bg-slate-100 text-slate-600 font-bold px-2.5 py-1.5 rounded-xl hover:bg-slate-200 transition">
                    إلغاء
                </a>
            </form>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="bg-slate-50 text-slate-500 font-bold border-b border-slate-200">
                    <tr>
                        <th class="p-3.5">الرقم</th>
                        <th class="p-3.5">التاريخ</th>
                        <th class="p-3.5">الخزنة</th>
                        <th class="p-3.5">المشروع المرتبط</th>
                        <th class="p-3.5">النوع</th>
                        <th class="p-3.5">البند والبيان</th>
                        <th class="p-3.5">المبلغ</th>
                        <th class="p-3.5">الرصيد بعد</th>
                        <th class="p-3.5">المسؤول</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">
                    <?php if (empty($transactions)): ?>
                        <tr>
                            <td colspan="9" class="p-8 text-center text-slate-400">لا توجد حركات مطابقة للفلتر المحدد.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($transactions as $t): ?>
                            <tr class="hover:bg-slate-50/70 transition">
                                <td class="p-3.5 font-mono text-slate-400">#<?= $t['id'] ?></td>
                                <td class="p-3.5 whitespace-nowrap text-slate-500"><?= date('Y-m-d', strtotime($t['transaction_date'])) ?></td>
                                <td class="p-3.5 font-bold text-slate-800"><?= htmlspecialchars($t['safe_name']) ?></td>
                                <td class="p-3.5">
                                    <?php if (!empty($t['project_name'])): ?>
                                        <span class="font-bold text-amber-700"><?= htmlspecialchars($t['project_name']) ?></span>
                                    <?php else: ?>
                                        <span class="text-slate-400">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3.5 whitespace-nowrap">
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold <?= $t['type'] === 'deposit' ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800' ?>">
                                        <?= $t['type'] === 'deposit' ? 'إيداع وارد' : 'صرف / خصم' ?>
                                    </span>
                                </td>
                                <td class="p-3.5">
                                    <strong class="text-slate-800 block"><?= htmlspecialchars($t['category']) ?></strong>
                                    <span class="text-[11px] text-slate-400 block"><?= htmlspecialchars($t['description'] ?: '-') ?></span>
                                </td>
                                <td class="p-3.5 whitespace-nowrap font-black <?= $t['type'] === 'deposit' ? 'text-emerald-600' : 'text-rose-600' ?> text-sm">
                                    <?= ($t['type'] === 'deposit' ? '+' : '-') . formatMoney($t['amount'], $t['currency']) ?>
                                </td>
                                <td class="p-3.5 whitespace-nowrap font-bold text-slate-800"><?= formatMoney($t['balance_after'], $t['currency']) ?></td>
                                <td class="p-3.5 whitespace-nowrap text-slate-500 text-[11px]"><?= htmlspecialchars($t['user_name'] ?? '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- INTER-SAFE TRANSFER MODAL -->
<div id="inter-transfer-modal" class="modal-container fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4 overflow-y-auto">
    <div class="modal-backdrop fixed inset-0" onclick="closeModal('inter-transfer-modal')"></div>
    <div class="relative bg-white rounded-3xl shadow-2xl max-w-lg w-full p-6 sm:p-8 modal-animate-enter z-10 border border-slate-100">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-5">
            <div class="flex items-center space-x-2 space-x-reverse">
                <div class="w-10 h-10 rounded-xl bg-indigo-100 text-indigo-600 flex items-center justify-center font-bold text-lg">
                    <i class="fa-solid fa-money-bill-transfer"></i>
                </div>
                <div>
                    <h3 class="font-black text-slate-800 text-lg">تحويل مالي بين الخزن</h3>
                    <p class="text-xs text-slate-400">تغذية خزائن المشاريع من الخزنة العامة أو العكس</p>
                </div>
            </div>
            <button onclick="closeModal('inter-transfer-modal')" class="text-slate-400 hover:text-slate-600 text-lg">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form action="safes.php" method="POST" class="space-y-4">
            <?= getCSRFInput() ?>
            <input type="hidden" name="action" value="transfer_safes">

            <!-- From Safe & To Safe -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">من الخزنة (المصدر) <span class="text-red-500">*</span></label>
                    <select name="from_safe_id" required class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs font-bold focus:bg-white focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                        <option value="">-- اختر المصدر --</option>
                        <?php foreach ($safes as $sf): ?>
                            <option value="<?= $sf['id'] ?>">
                                <?= htmlspecialchars($sf['name']) ?> (الرصيد: <?= formatMoney($sf['balance'], $currency) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">إلى الخزنة (الوجهة) <span class="text-red-500">*</span></label>
                    <select name="to_safe_id" required class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs font-bold focus:bg-white focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                        <option value="">-- اختر الوجهة --</option>
                        <?php foreach ($safes as $sf): ?>
                            <option value="<?= $sf['id'] ?>"><?= htmlspecialchars($sf['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Amount & Currency -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold text-slate-700 mb-1">المبلغ المحول <span class="text-red-500">*</span></label>
                    <input type="number" step="0.01" min="0.01" name="amount" required placeholder="0.00" class="w-full text-xl font-bold bg-slate-50 border border-slate-300 rounded-xl px-4 py-2 text-indigo-600 focus:bg-white focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">العملة</label>
                    <select name="currency" class="w-full h-[42px] bg-slate-50 border border-slate-300 rounded-xl px-2.5 text-xs font-bold focus:bg-white focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                        <option value="ر.س">ر.س (ريال)</option>
                        <option value="ج.م">ج.م (جنيه)</option>
                        <option value="$">$ (دولار)</option>
                        <option value="د.إ">د.إ (درهم)</option>
                        <option value="د.ك">د.ك (دينار)</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">تاريخ التحويل</label>
                <input type="date" name="transaction_date" value="<?= date('Y-m-d') ?>" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-indigo-500 focus:outline-none">
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">ملاحظات التحويل</label>
                <textarea name="description" rows="2" placeholder="سبب التحويل، رقم الإشعار البنكي..." class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-indigo-500 focus:outline-none"></textarea>
            </div>

            <div class="flex items-center space-x-2 space-x-reverse pt-2">
                <button type="submit" class="flex-1 bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2.5 px-4 rounded-xl shadow-md transition text-sm">
                    تأكيد التحويل الآن
                </button>
                <button type="button" onclick="closeModal('inter-transfer-modal')" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold py-2.5 px-4 rounded-xl transition text-sm">
                    إلغاء
                </button>
            </div>
        </form>
    </div>
</div>

<!-- DEPOSIT INTO ANY SAFE MODAL -->
<div id="deposit-any-safe-modal" class="modal-container fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4 overflow-y-auto">
    <div class="modal-backdrop fixed inset-0" onclick="closeModal('deposit-any-safe-modal')"></div>
    <div class="relative bg-white rounded-3xl shadow-2xl max-w-md w-full p-6 modal-animate-enter z-10 border border-slate-100">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
            <div class="flex items-center space-x-2 space-x-reverse">
                <div class="w-9 h-9 rounded-xl bg-emerald-100 text-emerald-600 flex items-center justify-center font-bold">
                    <i class="fa-solid fa-circle-plus"></i>
                </div>
                <h3 class="font-bold text-slate-800 text-lg">إيداع نقدية في خزنة</h3>
            </div>
            <button onclick="closeModal('deposit-any-safe-modal')" class="text-slate-400 hover:text-slate-600 text-lg">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form action="safes.php" method="POST" class="space-y-4">
            <?= getCSRFInput() ?>
            <input type="hidden" name="action" value="deposit_safe">

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">الخزنة المستلمة <span class="text-red-500">*</span></label>
                <select name="safe_id" required class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs font-bold focus:bg-white focus:ring-2 focus:ring-emerald-500 focus:outline-none">
                    <option value="">-- اختر الخزنة --</option>
                    <?php foreach ($safes as $sf): ?>
                        <option value="<?= $sf['id'] ?>"><?= htmlspecialchars($sf['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">المبلغ المودع والعملة <span class="text-red-500">*</span></label>
                <div class="flex gap-2">
                    <div class="relative flex-1">
                        <input type="number" step="0.01" min="0.01" name="amount" required placeholder="0.00" class="w-full text-xl font-bold bg-slate-50 border border-slate-300 rounded-xl px-4 py-2 text-emerald-600 focus:bg-white focus:ring-2 focus:ring-emerald-500 focus:outline-none">
                    </div>
                    <div class="w-32 shrink-0">
                        <select name="currency" class="w-full h-full text-xs font-bold bg-slate-50 border border-slate-300 rounded-xl px-2.5 py-2 focus:outline-none">
                            <option value="ر.س">ر.س (ريال)</option>
                            <option value="ج.م">ج.م (جنيه)</option>
                            <option value="$">$ (دولار)</option>
                            <option value="د.إ">د.إ (درهم)</option>
                            <option value="د.ك">د.ك (دينار)</option>
                        </select>
                    </div>
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">مصدر الإيداع / البند <span class="text-red-500">*</span></label>
                <input type="text" name="category" required placeholder="مثال: تمويل، إيداع كاش، تحويل بنكي" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-emerald-500 focus:outline-none">
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">تاريخ الإيداع</label>
                <input type="date" name="transaction_date" value="<?= date('Y-m-d') ?>" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-emerald-500 focus:outline-none">
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">ملاحظات</label>
                <textarea name="description" rows="2" placeholder="أي تفاصيل إضافية..." class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-emerald-500 focus:outline-none"></textarea>
            </div>

            <div class="flex items-center space-x-2 space-x-reverse pt-2">
                <button type="submit" class="flex-1 bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2.5 px-4 rounded-xl shadow-md transition text-sm">
                    تأكيد الإيداع
                </button>
                <button type="button" onclick="closeModal('deposit-any-safe-modal')" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold py-2.5 px-4 rounded-xl transition text-sm">
                    إلغاء
                </button>
            </div>
        </form>
    </div>
</div>

<?php include_once __DIR__ . '/includes/footer.php'; ?>
