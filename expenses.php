<?php
require_once __DIR__ . '/config/app.php';
requireLogin();
requireModule('expenses');

$currentUser = getCurrentUser();
$activeCompanyId = (int)$currentUser['active_company_id'];
$currency = $currentUser['currency'] ?? 'ج.م';
$db = getDBConnection();
$safe = getCompanyMainSafe($activeCompanyId);

// Handle POST: Create Expense
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('خطأ في التحقق من صحة الطلب');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create_expense') {
        $amount = (float)($_POST['amount'] ?? 0);
        $expCurrency = trim($_POST['currency'] ?? $currency);
        $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $expenseDate = trim($_POST['expense_date'] ?? date('Y-m-d'));

        if ($amount <= 0 || empty($title)) {
            setFlash('error', 'يرجى إدخال المبلغ وعنوان/سبب المصروف.');
        } else {
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

            try {
                // 1. Deduct from safe atomically
                $finalTitle = $title . ($description ? " - " . $description : "");
                if ($expCurrency !== $currency && strpos($finalTitle, $expCurrency) === false) {
                    $finalTitle .= " (العملة: " . $expCurrency . ")";
                }

                $txResult = recordSafeTransaction(
                    $activeCompanyId,
                    $safe['id'],
                    $currentUser['id'],
                    'expense',
                    $catName,
                    $amount,
                    $finalTitle,
                    $expenseDate,
                    $expCurrency
                );

                // 2. Insert into expenses table
                $insExp = $db->prepare("
                    INSERT INTO expenses (company_id, category_id, safe_id, safe_transaction_id, user_id, amount, currency, title, description, expense_date, created_at)
                    VALUES (:cid, :catid, :sid, :txid, :uid, :amt, :curr, :title, :desc, :edate, :now)
                ");
                $insExp->execute([
                    ':cid' => $activeCompanyId,
                    ':catid' => $categoryId,
                    ':sid' => $safe['id'],
                    ':txid' => $txResult['transaction_id'],
                    ':uid' => $currentUser['id'],
                    ':amt' => $amount,
                    ':curr' => $expCurrency,
                    ':title' => $title,
                    ':desc' => $description,
                    ':edate' => $expenseDate,
                    ':now' => date('Y-m-d H:i:s')
                ]);

                setFlash('success', "تم تسجيل صرف مبلغ " . formatMoney($amount, $expCurrency) . " وخصمه من الخزنة بنجاح!");
                header('Location: expenses.php');
                exit;
            } catch (Exception $e) {
                setFlash('error', $e->getMessage());
            }
        }
    }
}

// Fetch categories for filtering
$catStmt = $db->prepare("SELECT * FROM expense_categories WHERE company_id = :cid AND is_active = 1 ORDER BY name ASC");
$catStmt->execute([':cid' => $activeCompanyId]);
$categories = $catStmt->fetchAll();

// Filters
$filterCat = !empty($_GET['category_id']) ? (int)$_GET['category_id'] : null;
$filterFrom = $_GET['from'] ?? date('Y-m-01');
$filterTo = $_GET['to'] ?? date('Y-m-d');
$search = trim($_GET['search'] ?? '');

$query = "
    SELECT e.*, ec.name as category_name, u.name as user_name
    FROM expenses e
    LEFT JOIN expense_categories ec ON e.category_id = ec.id
    LEFT JOIN users u ON e.user_id = u.id
    WHERE e.company_id = :cid
";
$params = [':cid' => $activeCompanyId];

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
    $query .= " AND (e.title LIKE :s OR e.description LIKE :s)";
    $params[':s'] = "%{$search}%";
}
$query .= " ORDER BY e.id DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$expensesList = $stmt->fetchAll();

$totalExpensesAmount = array_sum(array_column($expensesList, 'amount'));

$pageTitle = 'سجل المصروفات';
require_once __DIR__ . '/includes/header.php';
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-slate-900">سجل المصروفات والبنود</h1>
            <p class="text-xs text-slate-500 mt-1">
                تسجيل ومتابعة جميع النفقات، تصنيفها حسب البنود وخصمها المباشر من الخزنة
            </p>
        </div>
        <div class="flex items-center space-x-2 space-x-reverse">
            <button onclick="openModal('quick-expense-modal')" class="inline-flex items-center bg-rose-600 hover:bg-rose-700 text-white font-bold px-4 py-2.5 rounded-xl shadow-md transition text-xs sm:text-sm">
                <i class="fa-solid fa-circle-minus ml-2"></i>
                تسجيل مصروف جديد
            </button>
            <?php if (isCompanyAdmin()): ?>
                <a href="expense_categories.php" class="inline-flex items-center bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold px-3 py-2 rounded-xl text-xs transition border border-slate-200">
                    <i class="fa-solid fa-tags ml-1.5"></i> إدارة البنود
                </a>
            <?php endif; ?>
        </div>
    </div>

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
            <div>
                <p class="text-xs font-bold text-slate-500">الرصيد المتبقي بالخزنة</p>
                <p class="text-2xl font-black text-emerald-600 mt-1"><?= formatMoney($safe['balance'], $currency) ?></p>
                <span class="text-[11px] text-emerald-600 font-semibold">متاح للصرف</span>
            </div>
            <div class="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-xl">
                <i class="fa-solid fa-vault"></i>
            </div>
        </div>
    </div>

    <!-- Filter Form -->
    <div class="no-print bg-white rounded-2xl p-4 border border-slate-200/80 shadow-xs">
        <form method="GET" action="expenses.php" class="grid grid-cols-1 sm:grid-cols-5 gap-3 items-end text-xs">
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

    <!-- Expenses Table -->
    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
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
                                    <span class="font-extrabold text-slate-900 block"><?= htmlspecialchars($exp['title']) ?></span>
                                    <?php if (!empty($exp['description'])): ?>
                                        <p class="text-[11px] text-slate-500 mt-0.5"><?= htmlspecialchars($exp['description']) ?></p>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3.5 whitespace-nowrap font-black text-rose-600 text-sm">
                                    -<?= formatMoney($exp['amount'], !empty($exp['currency']) ? $exp['currency'] : $currency) ?>
                                </td>
                                <td class="p-3.5 whitespace-nowrap text-slate-500">
                                    <?= htmlspecialchars($exp['user_name'] ?? 'مستخدم') ?>
                                </td>
                                <td class="p-3.5 whitespace-nowrap text-center no-print">
                                    <button onclick='printVoucher(<?= json_encode($exp) ?>, "<?= htmlspecialchars($currentUser['company_name'] ?? 'الشركة') ?>", "<?= !empty($exp['currency']) ? $exp['currency'] : $currency ?>")' class="bg-slate-100 hover:bg-slate-200 text-slate-700 px-2 py-1 rounded-lg text-[11px] font-bold transition">
                                        <i class="fa-solid fa-receipt ml-1"></i> طباعة سند
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
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
