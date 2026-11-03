<?php
require_once __DIR__ . '/config/app.php';
requireLogin();
requireModule('hr');

$currentUser = getCurrentUser();
$activeCompanyId = (int)$currentUser['active_company_id'];
$currency = $currentUser['currency'] ?? 'ج.م';
$db = getDBConnection();
ensurePayrollCurrencyColumn();
$safe = getCompanyMainSafe($activeCompanyId);

$selectedMonth = $_GET['month'] ?? date('Y-m');

// Handle POST: Disburse Salary
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('خطأ في التحقق من صحة الطلب');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'pay_salary') {
        $employeeId = (int)($_POST['employee_id'] ?? 0);
        $monthYear = trim($_POST['month_year'] ?? date('Y-m'));
        $baseSalary = (float)($_POST['base_salary'] ?? 0);
        $bonus = (float)($_POST['bonus'] ?? 0);
        $deductions = (float)($_POST['deductions'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        $deductFromSafe = isset($_POST['deduct_from_safe']) ? 1 : 0;
        $payCurrency = trim($_POST['currency'] ?? $currency);

        $netSalary = $baseSalary + $bonus - $deductions;

        if ($employeeId <= 0 || $netSalary <= 0) {
            setFlash('error', 'يرجى اختيار الموظف والتأكد من أن صافي الراتب أكبر من صفر.');
        } else {
            // Check if already paid for this month
            $chk = $db->prepare("SELECT id FROM payrolls WHERE company_id = :cid AND employee_id = :eid AND month_year = :my");
            $chk->execute([':cid' => $activeCompanyId, ':eid' => $employeeId, ':my' => $monthYear]);
            if ($chk->fetch()) {
                setFlash('error', 'تم صرف راتب هذا الموظف بالفعل لشهر ' . $monthYear);
            } else {
                // Get employee name
                $empStmt = $db->prepare("SELECT name FROM employees WHERE id = :id AND company_id = :cid");
                $empStmt->execute([':id' => $employeeId, ':cid' => $activeCompanyId]);
                $emp = $empStmt->fetch();
                $empName = $emp ? $emp['name'] : 'الموظف';

                $safeTxId = null;
                $db->beginTransaction();

                try {
                    if ($deductFromSafe) {
                        $txResult = recordSafeTransaction(
                            $activeCompanyId,
                            $safe['id'],
                            $currentUser['id'],
                            'salary',
                            'رواتب موظفين',
                            $netSalary,
                            "صرف راتب شهر {$monthYear} للموظف: {$empName}" . ($notes ? " ({$notes})" : ""),
                            date('Y-m-d'),
                            $payCurrency
                        );
                        $safeTxId = $txResult['transaction_id'];
                    }

                    $insPay = $db->prepare("
                        INSERT INTO payrolls (company_id, employee_id, user_id, month_year, base_salary, bonus, deductions, net_salary, currency, status, paid_date, safe_transaction_id, notes, created_at)
                        VALUES (:cid, :eid, :uid, :my, :base, :bon, :ded, :net, :curr, 'paid', :pdate, :txid, :notes, :now)
                    ");
                    $insPay->execute([
                        ':cid' => $activeCompanyId,
                        ':eid' => $employeeId,
                        ':uid' => $currentUser['id'],
                        ':my' => $monthYear,
                        ':base' => $baseSalary,
                        ':bon' => $bonus,
                        ':ded' => $deductions,
                        ':net' => $netSalary,
                        ':curr' => $payCurrency,
                        ':pdate' => date('Y-m-d'),
                        ':txid' => $safeTxId,
                        ':notes' => $notes,
                        ':now' => date('Y-m-d H:i:s')
                    ]);

                    $db->commit();
                    setFlash('success', "تم صرف راتب الموظف {$empName} بمبلغ " . formatMoney($netSalary, $payCurrency) . " بنجاح!");
                    header('Location: payroll.php?month=' . urlencode($monthYear));
                    exit;
                } catch (Exception $e) {
                    $db->rollBack();
                    setFlash('error', $e->getMessage());
                }
            }
        }
    }
}

// Fetch active employees
$empStmt = $db->prepare("SELECT * FROM employees WHERE company_id = :cid AND status = 'active' ORDER BY name ASC");
$empStmt->execute([':cid' => $activeCompanyId]);
$activeEmployees = $empStmt->fetchAll();

// Fetch payrolls for the selected month
$payStmt = $db->prepare("
    SELECT p.*, e.name as employee_name, e.job_title, u.name as payer_name
    FROM payrolls p
    JOIN employees e ON p.employee_id = e.id
    LEFT JOIN users u ON p.user_id = u.id
    WHERE p.company_id = :cid AND p.month_year = :my
    ORDER BY p.id DESC
");
$payStmt->execute([':cid' => $activeCompanyId, ':my' => $selectedMonth]);
$payrolls = $payStmt->fetchAll();

$totalMonthPayroll = array_sum(array_column($payrolls, 'net_salary'));

$pageTitle = 'مسيرات الرواتب';
require_once __DIR__ . '/includes/header.php';
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-slate-900">مسيرات وصرف الرواتب</h1>
            <p class="text-xs text-slate-500 mt-1">احتساب مرتبات الموظفين، الحوافز والخصومات والخصم المباشر من الخزنة</p>
        </div>
        <div class="flex items-center space-x-2 space-x-reverse">
            <button onclick="openModal('disburse-salary-modal')" class="inline-flex items-center bg-teal-600 hover:bg-teal-700 text-white font-bold px-4 py-2.5 rounded-xl shadow-md transition text-xs sm:text-sm">
                <i class="fa-solid fa-hand-holding-dollar ml-2"></i>
                صرف راتب موظف
            </button>
            <a href="employees.php" class="inline-flex items-center bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold px-3 py-2 rounded-xl text-xs transition border border-slate-200">
                <i class="fa-solid fa-users ml-1.5"></i> الموظفين
            </a>
        </div>
    </div>

    <!-- Month Selector & Stats -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <!-- Month Picker -->
        <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs flex flex-col justify-between">
            <span class="text-xs font-bold text-slate-500">الشهر الحالي المحدد</span>
            <form method="GET" action="payroll.php" class="mt-2 flex items-center space-x-2 space-x-reverse">
                <input type="month" name="month" value="<?= htmlspecialchars($selectedMonth) ?>" onchange="this.form.submit()" class="flex-1 bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm font-bold text-slate-800 focus:outline-none">
            </form>
        </div>

        <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-xs font-bold text-slate-500">إجمالي الرواتب المصروفة</p>
                <?php
                $payrollTotalsByCurr = [];
                foreach ($payrolls as $p) {
                    $c = !empty($p['currency']) ? $p['currency'] : $currency;
                    $payrollTotalsByCurr[$c] = ($payrollTotalsByCurr[$c] ?? 0.0) + (float)$p['net_salary'];
                }
                ?>
                <?php if (empty($payrollTotalsByCurr)): ?>
                    <p class="text-2xl font-black text-teal-600 mt-1"><?= formatMoney(0, $currency) ?></p>
                <?php else: ?>
                    <div class="mt-1 flex flex-wrap gap-2">
                        <?php foreach ($payrollTotalsByCurr as $c => $total): ?>
                            <span class="text-base sm:text-lg font-black text-teal-700 bg-teal-50 px-2.5 py-0.5 rounded-xl border border-teal-200">
                                <?= formatMoney($total, $c) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <span class="text-[11px] text-slate-400 mt-1 block">لشهر <?= htmlspecialchars($selectedMonth) ?></span>
            </div>
            <div class="w-12 h-12 rounded-xl bg-teal-50 text-teal-600 flex items-center justify-center text-xl shrink-0">
                <i class="fa-solid fa-money-check-dollar"></i>
            </div>
        </div>

        <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-xs font-bold text-slate-500">رصيد الخزنة المتاح</p>
                <?php
                $safeBals = $safe['balances'] ?? [$currency => $safe['balance']];
                if (empty($safeBals)) {
                    $safeBals = [$currency => 0.00];
                }
                ?>
                <div class="mt-1 flex flex-wrap gap-2">
                    <?php foreach ($safeBals as $sbCurr => $sbAmt): ?>
                        <span class="text-base sm:text-lg font-black text-emerald-700 bg-emerald-50 px-2.5 py-0.5 rounded-xl border border-emerald-200">
                            <?= formatMoney($sbAmt, $sbCurr) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
                <span class="text-[11px] text-emerald-600 font-semibold mt-1 block">جاهز لصرف الرواتب</span>
            </div>
            <div class="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-xl shrink-0">
                <i class="fa-solid fa-vault"></i>
            </div>
        </div>
    </div>

    <!-- Payroll Table -->
    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
        <div class="p-4 sm:p-5 border-b border-slate-100 flex items-center justify-between">
            <h3 class="font-black text-slate-800 text-base">سجل الرواتب المصروفة لشهر <?= htmlspecialchars($selectedMonth) ?></h3>
            <span class="text-xs text-slate-400"><?= count($payrolls) ?> مسير مسجل</span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="bg-slate-50 text-slate-500 font-bold border-b border-slate-200">
                    <tr>
                        <th class="p-4">الموظف</th>
                        <th class="p-4">الأساسي</th>
                        <th class="p-4">المكافأة (+)</th>
                        <th class="p-4">الخصومات (-)</th>
                        <th class="p-4">صافي الراتب المستلم</th>
                        <th class="p-4">تاريخ الصرف</th>
                        <th class="p-4">حالة الخزنة</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">
                    <?php if (empty($payrolls)): ?>
                        <tr>
                            <td colspan="7" class="p-8 text-center text-slate-400">
                                لم يتم صرف رواتب لهذا الشهر حتى الآن. انقر على "صرف راتب موظف" لبدء الصرف.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($payrolls as $pay): 
                            $pCurr = !empty($pay['currency']) ? $pay['currency'] : $currency;
                        ?>
                            <tr class="hover:bg-slate-50/70 transition">
                                <td class="p-4">
                                    <span class="font-extrabold text-slate-900 block"><?= htmlspecialchars($pay['employee_name']) ?></span>
                                    <span class="text-[11px] text-slate-400"><?= htmlspecialchars($pay['job_title'] ?: '') ?></span>
                                </td>
                                <td class="p-4 whitespace-nowrap font-medium text-slate-600">
                                    <?= formatMoney($pay['base_salary'], $pCurr) ?>
                                </td>
                                <td class="p-4 whitespace-nowrap text-emerald-600 font-bold">
                                    +<?= formatMoney($pay['bonus'], $pCurr) ?>
                                </td>
                                <td class="p-4 whitespace-nowrap text-rose-600 font-bold">
                                    -<?= formatMoney($pay['deductions'], $pCurr) ?>
                                </td>
                                <td class="p-4 whitespace-nowrap font-black text-slate-900 text-sm">
                                    <?= formatMoney($pay['net_salary'], $pCurr) ?>
                                </td>
                                <td class="p-4 whitespace-nowrap text-slate-500">
                                    <?= htmlspecialchars($pay['paid_date']) ?>
                                </td>
                                <td class="p-4 whitespace-nowrap">
                                    <?php if (!empty($pay['safe_transaction_id'])): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800">
                                            <i class="fa-solid fa-check ml-1"></i> خصم من الخزنة (<?= htmlspecialchars($pCurr) ?>)
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-600">
                                            صرف خارجي
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
</div>

<!-- DISBURSE SALARY MODAL -->
<div id="disburse-salary-modal" class="modal-container fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4 overflow-y-auto">
    <div class="modal-backdrop fixed inset-0"></div>
    <div class="relative bg-white rounded-3xl shadow-2xl max-w-md w-full p-6 modal-animate-enter z-10 border border-slate-100">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
            <h3 class="font-black text-slate-800 text-lg">صرف راتب موظف</h3>
            <button onclick="closeModal('disburse-salary-modal')" class="text-slate-400 hover:text-slate-600 text-lg">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form action="payroll.php" method="POST" class="space-y-4">
            <?= getCSRFInput() ?>
            <input type="hidden" name="action" value="pay_salary">

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">اختر الموظف <span class="text-red-500">*</span></label>
                <select id="emp_select" name="employee_id" required onchange="onSelectEmployee(this)" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-teal-500 focus:outline-none">
                    <option value="">-- اختر الموظف --</option>
                    <?php foreach ($activeEmployees as $e): ?>
                        <option value="<?= $e['id'] ?>" data-salary="<?= $e['base_salary'] ?>"><?= htmlspecialchars($e['name']) ?> (<?= htmlspecialchars($e['job_title'] ?: 'موظف') ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">عن شهر</label>
                    <input type="month" name="month_year" value="<?= htmlspecialchars($selectedMonth) ?>" required class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-teal-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">العملة</label>
                    <select name="currency" id="calc_currency" onchange="calculateNet()" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs font-bold text-slate-800 focus:bg-white focus:ring-2 focus:ring-teal-500 focus:outline-none">
                        <?php foreach (getSupportedCurrencies() as $cCode => $cLabel): ?>
                            <option value="<?= $cCode ?>" <?= $currency === $cCode ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cLabel) ?> (<?= htmlspecialchars($cCode) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-3 gap-2">
                <div>
                    <label class="block text-[11px] font-bold text-slate-700 mb-1">الراتب الأساسي</label>
                    <input type="number" step="0.01" min="0" id="calc_base" name="base_salary" value="0.00" oninput="calculateNet()" class="w-full bg-slate-50 border border-slate-300 rounded-lg px-2 py-1.5 text-xs font-bold focus:bg-white focus:outline-none">
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-slate-700 mb-1">مكافآت (+)</label>
                    <input type="number" step="0.01" min="0" id="calc_bonus" name="bonus" value="0.00" oninput="calculateNet()" class="w-full bg-slate-50 border border-slate-300 rounded-lg px-2 py-1.5 text-xs font-bold text-emerald-600 focus:bg-white focus:outline-none">
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-slate-700 mb-1">خصومات (-)</label>
                    <input type="number" step="0.01" min="0" id="calc_deduct" name="deductions" value="0.00" oninput="calculateNet()" class="w-full bg-slate-50 border border-slate-300 rounded-lg px-2 py-1.5 text-xs font-bold text-rose-600 focus:bg-white focus:outline-none">
                </div>
            </div>

            <div class="p-3 bg-teal-50 rounded-xl border border-teal-200 flex items-center justify-between">
                <span class="text-xs font-bold text-teal-800">صافي المبلغ المصروف:</span>
                <span id="calc_net_display" class="text-lg font-black text-teal-900">0.00 <?= $currency ?></span>
            </div>

            <div class="flex items-center space-x-2 space-x-reverse">
                <input type="checkbox" id="deduct_from_safe" name="deduct_from_safe" value="1" checked class="rounded text-teal-600 focus:ring-teal-500 w-4 h-4">
                <label for="deduct_from_safe" class="text-xs font-bold text-slate-700 cursor-pointer">خصم الراتب تلقائياً من الخزنة الرئيسية</label>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">ملاحظات</label>
                <input type="text" name="notes" placeholder="اختياري..." class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-teal-500 focus:outline-none">
            </div>

            <div class="flex items-center space-x-2 space-x-reverse pt-2">
                <button type="submit" class="flex-1 bg-teal-600 hover:bg-teal-700 text-white font-bold py-2.5 px-4 rounded-xl shadow-md transition text-xs">
                    تأكيد صرف الراتب
                </button>
                <button type="button" onclick="closeModal('disburse-salary-modal')" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold py-2.5 px-4 rounded-xl transition text-xs">
                    إلغاء
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function onSelectEmployee(sel) {
    const opt = sel.options[sel.selectedIndex];
    const sal = opt.getAttribute('data-salary') || '0';
    document.getElementById('calc_base').value = parseFloat(sal).toFixed(2);
    calculateNet();
}

function calculateNet() {
    const b = parseFloat(document.getElementById('calc_base').value) || 0;
    const bon = parseFloat(document.getElementById('calc_bonus').value) || 0;
    const d = parseFloat(document.getElementById('calc_deduct').value) || 0;
    const net = Math.max(0, b + bon - d);
    const currSelect = document.getElementById('calc_currency');
    const curr = currSelect ? currSelect.value : '<?= $currency ?>';
    document.getElementById('calc_net_display').innerText = net.toLocaleString('en-US', {minimumFractionDigits: 2}) + ' ' + curr;
}
</script>

<?php include_once __DIR__ . '/includes/footer.php'; ?>
