<?php
require_once __DIR__ . '/config/app.php';
requireLogin();
requireModule('hr');

$currentUser = getCurrentUser();
$activeCompanyId = (int)$currentUser['active_company_id'];
$currency = $currentUser['currency'] ?? 'ج.م';
$db = getDBConnection();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('خطأ في التحقق من صحة الطلب');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create_employee') {
        $name = trim($_POST['name'] ?? '');
        $jobTitle = trim($_POST['job_title'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $salary = (float)($_POST['base_salary'] ?? 0);
        $hireDate = trim($_POST['hire_date'] ?? date('Y-m-d'));
        $notes = trim($_POST['notes'] ?? '');

        if (empty($name)) {
            setFlash('error', 'اسم الموظف مطلوب.');
        } else {
            $ins = $db->prepare("
                INSERT INTO employees (company_id, name, job_title, phone, base_salary, hire_date, status, notes, created_at)
                VALUES (:cid, :name, :title, :phone, :sal, :hdate, 'active', :notes, :now)
            ");
            $ins->execute([
                ':cid' => $activeCompanyId,
                ':name' => $name,
                ':title' => $jobTitle,
                ':phone' => $phone,
                ':sal' => $salary,
                ':hdate' => $hireDate,
                ':notes' => $notes,
                ':now' => date('Y-m-d H:i:s')
            ]);
            setFlash('success', "تم إضافة الموظف '{$name}' بنجاح.");
            header('Location: employees.php');
            exit;
        }
    } elseif ($action === 'update_employee') {
        $empId = (int)($_POST['employee_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $jobTitle = trim($_POST['job_title'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $salary = (float)($_POST['base_salary'] ?? 0);
        $status = trim($_POST['status'] ?? 'active');
        $notes = trim($_POST['notes'] ?? '');

        if (empty($name)) {
            setFlash('error', 'اسم الموظف مطلوب.');
        } else {
            $upd = $db->prepare("
                UPDATE employees 
                SET name = :name, job_title = :title, phone = :phone, base_salary = :sal, status = :st, notes = :notes
                WHERE id = :id AND company_id = :cid
            ");
            $upd->execute([
                ':name' => $name,
                ':title' => $jobTitle,
                ':phone' => $phone,
                ':sal' => $salary,
                ':st' => $status,
                ':notes' => $notes,
                ':id' => $empId,
                ':cid' => $activeCompanyId
            ]);
            setFlash('success', 'تم تحديث بيانات الموظف بنجاح.');
            header('Location: employees.php');
            exit;
        }
    }
}

// Fetch employees
$stmt = $db->prepare("SELECT * FROM employees WHERE company_id = :cid ORDER BY id DESC");
$stmt->execute([':cid' => $activeCompanyId]);
$employees = $stmt->fetchAll();

$totalSalaries = array_sum(array_column($employees, 'base_salary'));

$pageTitle = 'دليل الموظفين';
require_once __DIR__ . '/includes/header.php';
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-slate-900">إدارة الموظفين والكوادر</h1>
            <p class="text-xs text-slate-500 mt-1">سجل الموظفين والوظائف والرواتب الأساسية وتاريخ التعيين</p>
        </div>
        <div class="flex items-center space-x-2 space-x-reverse">
            <button onclick="openModal('add-emp-modal')" class="inline-flex items-center bg-purple-600 hover:bg-purple-700 text-white font-bold px-4 py-2.5 rounded-xl shadow-md transition text-xs sm:text-sm">
                <i class="fa-solid fa-user-plus ml-2"></i>
                إضافة موظف جديد
            </button>
            <a href="payroll.php" class="inline-flex items-center bg-teal-600 hover:bg-teal-700 text-white font-bold px-4 py-2.5 rounded-xl shadow-md transition text-xs sm:text-sm">
                <i class="fa-solid fa-money-check-dollar ml-2"></i>
                مسير الرواتب
            </a>
        </div>
    </div>

    <!-- Summary stats -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-xs font-bold text-slate-500">إجمالي الموظفين</p>
                <p class="text-2xl font-black text-slate-800 mt-1"><?= count($employees) ?> موظف</p>
            </div>
            <div class="w-12 h-12 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center text-xl">
                <i class="fa-solid fa-users"></i>
            </div>
        </div>

        <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-xs font-bold text-slate-500">إجمالي مسير الرواتب الشهرية</p>
                <p class="text-2xl font-black text-teal-600 mt-1"><?= formatMoney($totalSalaries, $currency) ?></p>
            </div>
            <div class="w-12 h-12 rounded-xl bg-teal-50 text-teal-600 flex items-center justify-center text-xl">
                <i class="fa-solid fa-wallet"></i>
            </div>
        </div>

        <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-xs font-bold text-slate-500">حالة موديول الـ HR</p>
                <p class="text-lg font-black text-emerald-600 mt-1">مفعل للشركة</p>
            </div>
            <div class="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-xl">
                <i class="fa-solid fa-circle-check"></i>
            </div>
        </div>
    </div>

    <!-- Employees Table -->
    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="bg-slate-50 text-slate-500 font-bold border-b border-slate-200">
                    <tr>
                        <th class="p-4">الموظف</th>
                        <th class="p-4">المسمى الوظيفي</th>
                        <th class="p-4">الهاتف</th>
                        <th class="p-4">الراتب الأساسي</th>
                        <th class="p-4">تاريخ التعيين</th>
                        <th class="p-4">الحالة</th>
                        <th class="p-4 text-center">إجراءات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">
                    <?php if (empty($employees)): ?>
                        <tr>
                            <td colspan="7" class="p-8 text-center text-slate-400">لا يوجد موظفون مسجلون حالياً.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($employees as $emp): ?>
                            <tr class="hover:bg-slate-50/70 transition">
                                <td class="p-4">
                                    <div class="flex items-center space-x-2.5 space-x-reverse">
                                        <div class="w-8 h-8 rounded-full bg-purple-100 text-purple-700 flex items-center justify-center font-bold text-xs">
                                            <?= mb_substr($emp['name'], 0, 1) ?>
                                        </div>
                                        <span class="font-extrabold text-slate-900"><?= htmlspecialchars($emp['name']) ?></span>
                                    </div>
                                </td>
                                <td class="p-4 font-medium text-slate-700">
                                    <?= htmlspecialchars($emp['job_title'] ?: 'غير محدد') ?>
                                </td>
                                <td class="p-4 font-mono text-slate-500">
                                    <?= htmlspecialchars($emp['phone'] ?: '-') ?>
                                </td>
                                <td class="p-4 font-black text-slate-900">
                                    <?= formatMoney($emp['base_salary'], $currency) ?>
                                </td>
                                <td class="p-4 text-slate-500">
                                    <?= htmlspecialchars($emp['hire_date'] ?: '-') ?>
                                </td>
                                <td class="p-4">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?= $emp['status'] === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600' ?>">
                                        <?= $emp['status'] === 'active' ? 'على رأس العمل' : 'متوقف / مستقيل' ?>
                                    </span>
                                </td>
                                <td class="p-4 text-center">
                                    <button onclick='editEmployee(<?= json_encode($emp) ?>)' class="bg-slate-100 hover:bg-slate-200 text-slate-700 px-2.5 py-1 rounded-lg text-xs font-bold transition">
                                        <i class="fa-solid fa-pen ml-1"></i> تعديل
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

<!-- ADD EMPLOYEE MODAL -->
<div id="add-emp-modal" class="modal-container fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4 overflow-y-auto">
    <div class="modal-backdrop fixed inset-0"></div>
    <div class="relative bg-white rounded-3xl shadow-2xl max-w-md w-full p-6 modal-animate-enter z-10 border border-slate-100">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
            <h3 class="font-black text-slate-800 text-lg">إضافة موظف جديد</h3>
            <button onclick="closeModal('add-emp-modal')" class="text-slate-400 hover:text-slate-600 text-lg">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form action="employees.php" method="POST" class="space-y-4">
            <?= getCSRFInput() ?>
            <input type="hidden" name="action" value="create_employee">

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">اسم الموظف <span class="text-red-500">*</span></label>
                <input type="text" name="name" required placeholder="مثال: حسام إبراهيم" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">المسمى الوظيفي</label>
                    <input type="text" name="job_title" placeholder="أمين مخزن، مبيعات..." class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">رقم الهاتف</label>
                    <input type="text" name="phone" placeholder="010xxxxxxxx" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">الراتب الأساسي (<?= $currency ?>)</label>
                    <input type="number" step="0.01" min="0" name="base_salary" value="0.00" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs font-bold focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">تاريخ التعيين</label>
                    <input type="date" name="hire_date" value="<?= date('Y-m-d') ?>" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">ملاحظات إضافية</label>
                <textarea name="notes" rows="2" placeholder="اختياري..." class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none"></textarea>
            </div>

            <div class="flex items-center space-x-2 space-x-reverse pt-2">
                <button type="submit" class="flex-1 bg-purple-600 hover:bg-purple-700 text-white font-bold py-2.5 px-4 rounded-xl shadow-md transition text-xs">
                    حفظ الموظف
                </button>
                <button type="button" onclick="closeModal('add-emp-modal')" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold py-2.5 px-4 rounded-xl transition text-xs">
                    إلغاء
                </button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT EMPLOYEE MODAL -->
<div id="edit-emp-modal" class="modal-container fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4 overflow-y-auto">
    <div class="modal-backdrop fixed inset-0"></div>
    <div class="relative bg-white rounded-3xl shadow-2xl max-w-md w-full p-6 modal-animate-enter z-10 border border-slate-100">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
            <h3 class="font-black text-slate-800 text-lg">تعديل بيانات الموظف</h3>
            <button onclick="closeModal('edit-emp-modal')" class="text-slate-400 hover:text-slate-600 text-lg">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form action="employees.php" method="POST" class="space-y-4">
            <?= getCSRFInput() ?>
            <input type="hidden" name="action" value="update_employee">
            <input type="hidden" id="edit_emp_id" name="employee_id">

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">اسم الموظف</label>
                <input type="text" id="edit_emp_name" name="name" required class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">المسمى الوظيفي</label>
                    <input type="text" id="edit_emp_job" name="job_title" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">الهاتف</label>
                    <input type="text" id="edit_emp_phone" name="phone" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">الراتب الأساسي (<?= $currency ?>)</label>
                    <input type="number" step="0.01" min="0" id="edit_emp_salary" name="base_salary" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs font-bold focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">حالة العمل</label>
                    <select id="edit_emp_status" name="status" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                        <option value="active">على رأس العمل (نشط)</option>
                        <option value="inactive">متوقف / مستقيل</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">ملاحظات</label>
                <textarea id="edit_emp_notes" name="notes" rows="2" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none"></textarea>
            </div>

            <div class="flex items-center space-x-2 space-x-reverse pt-2">
                <button type="submit" class="flex-1 bg-purple-600 hover:bg-purple-700 text-white font-bold py-2.5 px-4 rounded-xl shadow-md transition text-xs">
                    تحديث البيانات
                </button>
                <button type="button" onclick="closeModal('edit-emp-modal')" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold py-2.5 px-4 rounded-xl transition text-xs">
                    إلغاء
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function editEmployee(emp) {
    document.getElementById('edit_emp_id').value = emp.id;
    document.getElementById('edit_emp_name').value = emp.name;
    document.getElementById('edit_emp_job').value = emp.job_title || '';
    document.getElementById('edit_emp_phone').value = emp.phone || '';
    document.getElementById('edit_emp_salary').value = emp.base_salary || '0';
    document.getElementById('edit_emp_status').value = emp.status || 'active';
    document.getElementById('edit_emp_notes').value = emp.notes || '';
    openModal('edit-emp-modal');
}
</script>

<?php include_once __DIR__ . '/includes/footer.php'; ?>
