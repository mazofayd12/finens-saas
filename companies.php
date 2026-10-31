<?php
require_once __DIR__ . '/config/app.php';
requireLogin();

// Impersonation handlers
if (isSuperAdmin()) {
    if (isset($_GET['impersonate'])) {
        $impId = (int)$_GET['impersonate'];
        $_SESSION['impersonate_company_id'] = $impId;
        setFlash('success', 'تم الدخول إلى لوحة تحكم الشركة بنجاح.');
        header('Location: dashboard.php');
        exit;
    }

    if (isset($_GET['exit_impersonate'])) {
        unset($_SESSION['impersonate_company_id']);
        setFlash('info', 'تمت العودة إلى لوحة السوبر أدمن.');
        header('Location: companies.php');
        exit;
    }
}

requireSuperAdmin();

$pageTitle = 'إدارة الشركات والموديولات';
$db = getDBConnection();
$error = '';
$success = '';

// Handle POST actions (Create / Edit company)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('خطأ في التحقق من صحة الطلب (CSRF)');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create_company') {
        $name = trim($_POST['name'] ?? '');
        $code = trim($_POST['code'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $currency = trim($_POST['currency'] ?? 'ج.م');
        $initialBalance = (float)($_POST['initial_balance'] ?? 0);

        $modTreasury = isset($_POST['module_treasury']) ? 1 : 0;
        $modExpenses = isset($_POST['module_expenses']) ? 1 : 0;
        $modHr = isset($_POST['module_hr']) ? 1 : 0;
        $modReports = isset($_POST['module_reports']) ? 1 : 0;

        // Admin account info
        $adminName = trim($_POST['admin_name'] ?? '');
        $adminUsername = trim($_POST['admin_username'] ?? '');
        $adminPassword = trim($_POST['admin_password'] ?? '');

        if (empty($name) || empty($code) || empty($adminUsername) || empty($adminPassword)) {
            setFlash('error', 'يرجى ملء جميع الحقول المطلوبة (اسم الشركة، الكود، وحساب المدير).');
        } else {
            // Check if code or username exists
            $chkCode = $db->prepare("SELECT id FROM companies WHERE code = :code");
            $chkCode->execute([':code' => $code]);
            if ($chkCode->fetch()) {
                setFlash('error', 'كود الشركة مستخدم بالفعل، يرجى اختيار كود آخر.');
            } else {
                $chkUser = $db->prepare("SELECT id FROM users WHERE username = :u");
                $chkUser->execute([':u' => $adminUsername]);
                if ($chkUser->fetch()) {
                    setFlash('error', 'اسم مستخدم المدير موجود بالفعل في النظام، اختر اسماً آخر.');
                } else {
                    $now = date('Y-m-d H:i:s');
                    $today = date('Y-m-d');

                    $db->beginTransaction();
                    try {
                        // 1. Insert Company
                        $insCo = $db->prepare("
                            INSERT INTO companies (name, code, phone, currency, is_active, module_treasury, module_expenses, module_hr, module_reports, created_at)
                            VALUES (:name, :code, :phone, :cur, 1, :mt, :me, :mhr, :mr, :now)
                        ");
                        $insCo->execute([
                            ':name' => $name,
                            ':code' => $code,
                            ':phone' => $phone,
                            ':cur' => $currency ?: 'ج.م',
                            ':mt' => $modTreasury,
                            ':me' => $modExpenses,
                            ':mhr' => $modHr,
                            ':mr' => $modReports,
                            ':now' => $now
                        ]);
                        $companyId = (int)$db->lastInsertId();

                        // 2. Insert Main Safe
                        $insSafe = $db->prepare("
                            INSERT INTO safes (company_id, name, balance, created_at)
                            VALUES (:cid, 'الخزنة الرئيسية', :bal, :now)
                        ");
                        $insSafe->execute([
                            ':cid' => $companyId,
                            ':bal' => $initialBalance,
                            ':now' => $now
                        ]);
                        $safeId = (int)$db->lastInsertId();

                        // 3. Insert Company Admin
                        $insAdmin = $db->prepare("
                            INSERT INTO users (company_id, name, username, password, role, phone, is_active, created_at)
                            VALUES (:cid, :aname, :auser, :apass, 'company_admin', :phone, 1, :now)
                        ");
                        $insAdmin->execute([
                            ':cid' => $companyId,
                            ':aname' => $adminName ?: ('مدير ' . $name),
                            ':auser' => $adminUsername,
                            ':apass' => password_hash($adminPassword, PASSWORD_DEFAULT),
                            ':phone' => $phone,
                            ':now' => $now
                        ]);
                        $adminId = (int)$db->lastInsertId();

                        // 4. Record Initial Balance Transaction if > 0
                        if ($initialBalance > 0) {
                            $insTx = $db->prepare("
                                INSERT INTO safe_transactions (company_id, safe_id, user_id, type, category, amount, balance_before, balance_after, description, transaction_date, created_at)
                                VALUES (:cid, :sid, :uid, 'deposit', 'رصيد افتتاحي', :amt, 0.00, :amt, 'إيداع رصيد البداية عند إنشاء الشركة', :tdate, :now)
                            ");
                            $insTx->execute([
                                ':cid' => $companyId,
                                ':sid' => $safeId,
                                ':uid' => $adminId,
                                ':amt' => $initialBalance,
                                ':tdate' => $today,
                                ':now' => $now
                            ]);
                        }

                        // 5. Seed default expense categories for the company
                        $catStmt = $db->prepare("
                            INSERT INTO expense_categories (company_id, name, icon, is_active, created_at)
                            VALUES (:cid, :name, 'fa-folder', 1, :now)
                        ");
                        $defaultCategories = ['إيجار المقر', 'فواتير ومرافق', 'بضاعة وشحن', 'ضيافة ونثريات', 'صيانة ومعدات'];
                        foreach ($defaultCategories as $defCat) {
                            $catStmt->execute([':cid' => $companyId, ':name' => $defCat, ':now' => $now]);
                        }

                        $db->commit();
                        setFlash('success', "تم إنشاء الشركة {$name} وحساب المدير بنجاح!");
                        header('Location: companies.php');
                        exit;
                    } catch (Exception $e) {
                        $db->rollBack();
                        setFlash('error', 'حدث خطأ أثناء إنشاء الشركة: ' . $e->getMessage());
                    }
                }
            }
        }
    } elseif ($action === 'update_company') {
        $id = (int)($_POST['company_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $currency = trim($_POST['currency'] ?? 'ج.م');
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        $modTreasury = isset($_POST['module_treasury']) ? 1 : 0;
        $modExpenses = isset($_POST['module_expenses']) ? 1 : 0;
        $modHr = isset($_POST['module_hr']) ? 1 : 0;
        $modReports = isset($_POST['module_reports']) ? 1 : 0;

        if (empty($name)) {
            setFlash('error', 'اسم الشركة مطلوب');
        } else {
            $upd = $db->prepare("
                UPDATE companies 
                SET name = :name, phone = :phone, currency = :cur, is_active = :act,
                    module_treasury = :mt, module_expenses = :me, module_hr = :mhr, module_reports = :mr
                WHERE id = :id
            ");
            $upd->execute([
                ':name' => $name,
                ':phone' => $phone,
                ':cur' => $currency,
                ':act' => $isActive,
                ':mt' => $modTreasury,
                ':me' => $modExpenses,
                ':mhr' => $modHr,
                ':mr' => $modReports,
                ':id' => $id
            ]);
            setFlash('success', 'تم تحديث بيانات الشركة والموديولات بنجاح.');
            header('Location: companies.php');
            exit;
        }
    }
}

// Fetch all companies with stats
$companies = $db->query("
    SELECT c.*, 
           (SELECT balance FROM safes WHERE company_id = c.id ORDER BY id ASC LIMIT 1) as safe_balance,
           (SELECT COUNT(*) FROM users WHERE company_id = c.id) as user_count,
           (SELECT COUNT(*) FROM expenses WHERE company_id = c.id) as expense_count
    FROM companies c 
    ORDER BY c.id DESC
")->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-slate-900">إدارة الشركات والموديولات (SaaS)</h1>
            <p class="text-xs text-slate-500 mt-1">إنشاء الشركات، تحديد اشتراك الموديولات، والتحكم بالحسابات</p>
        </div>
        <button onclick="openModal('add-company-modal')" class="inline-flex items-center bg-brand-600 hover:bg-brand-700 text-white font-bold px-4 py-2.5 rounded-xl shadow-md transition text-sm">
            <i class="fa-solid fa-plus-circle ml-2"></i>
            إضافة شركة جديدة
        </button>
    </div>

    <!-- Companies Table / Cards -->
    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="bg-slate-50 text-slate-500 font-bold border-b border-slate-200">
                    <tr>
                        <th class="p-4">الشركة</th>
                        <th class="p-4">رصيد الخزنة</th>
                        <th class="p-4">الموديولات المفعلة</th>
                        <th class="p-4">المستخدمين</th>
                        <th class="p-4">الحالة</th>
                        <th class="p-4 text-center">إجراءات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">
                    <?php foreach ($companies as $comp): ?>
                        <tr class="hover:bg-slate-50/70 transition">
                            <td class="p-4">
                                <div class="flex items-center space-x-3 space-x-reverse">
                                    <div class="w-9 h-9 rounded-xl bg-indigo-50 text-brand-600 flex items-center justify-center font-bold text-sm">
                                        <i class="fa-solid fa-building"></i>
                                    </div>
                                    <div>
                                        <span class="font-extrabold text-slate-900 text-sm block"><?= htmlspecialchars($comp['name']) ?></span>
                                        <span class="text-[11px] text-slate-400 font-mono">#<?= htmlspecialchars($comp['code']) ?> | هاتف: <?= htmlspecialchars($comp['phone'] ?: 'غير محدد') ?></span>
                                    </div>
                                </div>
                            </td>
                            <td class="p-4 whitespace-nowrap">
                                <span class="text-sm font-black text-emerald-600"><?= formatMoney($comp['safe_balance'] ?? 0, $comp['currency']) ?></span>
                            </td>
                            <td class="p-4">
                                <div class="flex flex-wrap gap-1 max-w-xs">
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold <?= (int)$comp['module_treasury'] === 1 ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-400 line-through' ?>">
                                        الخزنة
                                    </span>
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold <?= (int)$comp['module_expenses'] === 1 ? 'bg-rose-100 text-rose-800' : 'bg-slate-100 text-slate-400 line-through' ?>">
                                        المصروفات
                                    </span>
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold <?= (int)$comp['module_hr'] === 1 ? 'bg-purple-100 text-purple-800' : 'bg-slate-200 text-slate-500 line-through' ?>">
                                        HR / المرتبات
                                    </span>
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold <?= (int)$comp['module_reports'] === 1 ? 'bg-blue-100 text-blue-800' : 'bg-slate-100 text-slate-400 line-through' ?>">
                                        التقارير
                                    </span>
                                </div>
                            </td>
                            <td class="p-4 whitespace-nowrap">
                                <a href="users.php?company_id=<?= $comp['id'] ?>" class="text-brand-600 hover:underline font-bold">
                                    <i class="fa-solid fa-users ml-1 text-slate-400"></i>
                                    <?= $comp['user_count'] ?> مستخدم
                                </a>
                            </td>
                            <td class="p-4 whitespace-nowrap">
                                <span class="px-2.5 py-1 rounded-full text-[10px] font-bold <?= (int)$comp['is_active'] === 1 ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800' ?>">
                                    <?= (int)$comp['is_active'] === 1 ? 'نشط' : 'معطل' ?>
                                </span>
                            </td>
                            <td class="p-4 whitespace-nowrap text-center">
                                <div class="inline-flex items-center space-x-1.5 space-x-reverse">
                                    <a href="companies.php?impersonate=<?= $comp['id'] ?>" class="bg-brand-50 hover:bg-brand-100 text-brand-700 font-bold px-2.5 py-1.5 rounded-lg text-xs transition flex items-center">
                                        <i class="fa-solid fa-arrow-right-to-bracket ml-1"></i>
                                        دخول كمدير
                                    </a>
                                    <button onclick='editCompany(<?= json_encode($comp) ?>)' class="bg-slate-100 hover:bg-slate-200 text-slate-700 p-1.5 rounded-lg text-xs transition" title="تعديل">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ADD COMPANY MODAL -->
<div id="add-company-modal" class="modal-container fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4 overflow-y-auto">
    <div class="modal-backdrop fixed inset-0"></div>
    <div class="relative bg-white rounded-3xl shadow-2xl max-w-xl w-full p-6 sm:p-8 modal-animate-enter z-10 border border-slate-100">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-5">
            <div class="flex items-center space-x-2 space-x-reverse">
                <div class="w-9 h-9 rounded-xl bg-brand-100 text-brand-600 flex items-center justify-center font-bold">
                    <i class="fa-solid fa-building-circle-plus"></i>
                </div>
                <div>
                    <h3 class="font-black text-slate-800 text-lg">إضافة شركة جديدة للمنصة</h3>
                    <p class="text-xs text-slate-400">تحديد بيانات الشركة، الموديولات وحساب المدير المسؤول</p>
                </div>
            </div>
            <button onclick="closeModal('add-company-modal')" class="text-slate-400 hover:text-slate-600 text-lg">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form action="companies.php" method="POST" class="space-y-4">
            <?= getCSRFInput() ?>
            <input type="hidden" name="action" value="create_company">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">اسم الشركة <span class="text-red-500">*</span></label>
                    <input type="text" name="name" required placeholder="مثال: شركة كوكب الصين" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">كود الشركة (معرّف فريد) <span class="text-red-500">*</span></label>
                    <input type="text" name="code" required placeholder="مثال: planet-china" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm font-mono focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">رقم الهاتف للتواصل</label>
                    <input type="text" name="phone" placeholder="010xxxxxxxx" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">العملة الأساسية</label>
                    <input type="text" name="currency" value="ج.م" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">الرصيد الافتتاحي للخزنة (ج.م)</label>
                <input type="number" step="0.01" min="0" name="initial_balance" value="0.00" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm font-bold text-emerald-600 focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                <span class="text-[11px] text-slate-400">سيتم إنشاء الخزنة الرئيسية وإيداع هذا المبلغ كافتتاحي.</span>
            </div>

            <!-- Modules Selection (Feature Toggling) -->
            <div class="p-4 bg-slate-50 rounded-2xl border border-slate-200">
                <p class="text-xs font-black text-slate-800 mb-2">تفعيل الموديولات المتاحة للشركة:</p>
                <div class="grid grid-cols-2 gap-3 text-xs">
                    <label class="flex items-center space-x-2 space-x-reverse cursor-pointer">
                        <input type="checkbox" name="module_treasury" value="1" checked class="rounded text-brand-600 focus:ring-brand-500 w-4 h-4">
                        <span class="font-bold text-slate-700">الخزنة والإيداعات</span>
                    </label>
                    <label class="flex items-center space-x-2 space-x-reverse cursor-pointer">
                        <input type="checkbox" name="module_expenses" value="1" checked class="rounded text-brand-600 focus:ring-brand-500 w-4 h-4">
                        <span class="font-bold text-slate-700">المصروفات والبنود</span>
                    </label>
                    <label class="flex items-center space-x-2 space-x-reverse cursor-pointer">
                        <input type="checkbox" name="module_reports" value="1" checked class="rounded text-brand-600 focus:ring-brand-500 w-4 h-4">
                        <span class="font-bold text-slate-700">التقارير المالية</span>
                    </label>
                    <label class="flex items-center space-x-2 space-x-reverse cursor-pointer">
                        <input type="checkbox" name="module_hr" value="1" class="rounded text-purple-600 focus:ring-purple-500 w-4 h-4">
                        <span class="font-bold text-purple-800">الموارد البشرية والرواتب (HR)</span>
                    </label>
                </div>
            </div>

            <!-- Admin Credentials -->
            <div class="p-4 bg-indigo-50/60 rounded-2xl border border-indigo-100 space-y-3">
                <p class="text-xs font-black text-indigo-900">بيانات حساب مدير الشركة (Company Admin):</p>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 mb-1">اسم المدير</label>
                        <input type="text" name="admin_name" placeholder="محمد علي" class="w-full bg-white border border-slate-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-brand-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 mb-1">اسم المستخدم للدخول <span class="text-red-500">*</span></label>
                        <input type="text" name="admin_username" required placeholder="china_admin" class="w-full bg-white border border-slate-300 rounded-lg px-2.5 py-1.5 text-xs font-mono focus:ring-2 focus:ring-brand-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 mb-1">كلمة المرور <span class="text-red-500">*</span></label>
                        <input type="password" name="admin_password" required placeholder="••••••" class="w-full bg-white border border-slate-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-brand-500 focus:outline-none">
                    </div>
                </div>
            </div>

            <div class="flex items-center space-x-2 space-x-reverse pt-2">
                <button type="submit" class="flex-1 bg-brand-600 hover:bg-brand-700 text-white font-bold py-2.5 px-4 rounded-xl shadow-md transition text-sm">
                    إنشاء الشركة الآن
                </button>
                <button type="button" onclick="closeModal('add-company-modal')" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold py-2.5 px-4 rounded-xl transition text-sm">
                    إلغاء
                </button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT COMPANY MODAL -->
<div id="edit-company-modal" class="modal-container fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4 overflow-y-auto">
    <div class="modal-backdrop fixed inset-0"></div>
    <div class="relative bg-white rounded-3xl shadow-2xl max-w-lg w-full p-6 sm:p-8 modal-animate-enter z-10 border border-slate-100">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-5">
            <h3 class="font-black text-slate-800 text-lg">تعديل بيانات الشركة والموديولات</h3>
            <button onclick="closeModal('edit-company-modal')" class="text-slate-400 hover:text-slate-600 text-lg">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form action="companies.php" method="POST" class="space-y-4">
            <?= getCSRFInput() ?>
            <input type="hidden" name="action" value="update_company">
            <input type="hidden" id="edit_company_id" name="company_id">

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">اسم الشركة</label>
                <input type="text" id="edit_name" name="name" required class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">رقم الهاتف</label>
                    <input type="text" id="edit_phone" name="phone" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">العملة</label>
                    <input type="text" id="edit_currency" name="currency" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                </div>
            </div>

            <!-- Modules Selection -->
            <div class="p-4 bg-slate-50 rounded-2xl border border-slate-200">
                <p class="text-xs font-black text-slate-800 mb-2">الموديولات المتاحة للشركة:</p>
                <div class="grid grid-cols-2 gap-3 text-xs">
                    <label class="flex items-center space-x-2 space-x-reverse cursor-pointer">
                        <input type="checkbox" id="edit_mod_treasury" name="module_treasury" value="1" class="rounded text-brand-600 focus:ring-brand-500 w-4 h-4">
                        <span class="font-bold text-slate-700">الخزنة والإيداعات</span>
                    </label>
                    <label class="flex items-center space-x-2 space-x-reverse cursor-pointer">
                        <input type="checkbox" id="edit_mod_expenses" name="module_expenses" value="1" class="rounded text-brand-600 focus:ring-brand-500 w-4 h-4">
                        <span class="font-bold text-slate-700">المصروفات والبنود</span>
                    </label>
                    <label class="flex items-center space-x-2 space-x-reverse cursor-pointer">
                        <input type="checkbox" id="edit_mod_reports" name="module_reports" value="1" class="rounded text-brand-600 focus:ring-brand-500 w-4 h-4">
                        <span class="font-bold text-slate-700">التقارير المالية</span>
                    </label>
                    <label class="flex items-center space-x-2 space-x-reverse cursor-pointer">
                        <input type="checkbox" id="edit_mod_hr" name="module_hr" value="1" class="rounded text-purple-600 focus:ring-purple-500 w-4 h-4">
                        <span class="font-bold text-purple-800">الموارد البشرية والرواتب (HR)</span>
                    </label>
                </div>
            </div>

            <div class="flex items-center space-x-2 space-x-reverse">
                <input type="checkbox" id="edit_is_active" name="is_active" value="1" class="rounded text-emerald-600 focus:ring-emerald-500 w-4 h-4">
                <label for="edit_is_active" class="text-xs font-bold text-slate-700 cursor-pointer">الشركة نشطة ومفعلة للدخول</label>
            </div>

            <div class="flex items-center space-x-2 space-x-reverse pt-2">
                <button type="submit" class="flex-1 bg-brand-600 hover:bg-brand-700 text-white font-bold py-2.5 px-4 rounded-xl shadow-md transition text-sm">
                    حفظ التعديلات
                </button>
                <button type="button" onclick="closeModal('edit-company-modal')" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold py-2.5 px-4 rounded-xl transition text-sm">
                    إلغاء
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function editCompany(comp) {
    document.getElementById('edit_company_id').value = comp.id;
    document.getElementById('edit_name').value = comp.name;
    document.getElementById('edit_phone').value = comp.phone || '';
    document.getElementById('edit_currency').value = comp.currency || 'ج.م';
    document.getElementById('edit_mod_treasury').checked = parseInt(comp.module_treasury) === 1;
    document.getElementById('edit_mod_expenses').checked = parseInt(comp.module_expenses) === 1;
    document.getElementById('edit_mod_hr').checked = parseInt(comp.module_hr) === 1;
    document.getElementById('edit_mod_reports').checked = parseInt(comp.module_reports) === 1;
    document.getElementById('edit_is_active').checked = parseInt(comp.is_active) === 1;
    openModal('edit-company-modal');
}
</script>

<?php include_once __DIR__ . '/includes/footer.php'; ?>
