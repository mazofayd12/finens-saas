<?php
require_once __DIR__ . '/config/app.php';
requireLogin();

$currentUser = getCurrentUser();
$db = getDBConnection();

// Check permission: Super Admin or Company Admin
if (!isSuperAdmin() && !isCompanyAdmin()) {
    setFlash('error', 'ليس لديك صلاحية الوصول لإدارة المستخدمين.');
    header('Location: dashboard.php');
    exit;
}

$pageTitle = 'إدارة المستخدمين والصلاحيات';

// Determine company scope
$filterCompanyId = null;
if (isSuperAdmin()) {
    $filterCompanyId = !empty($_GET['company_id']) ? (int)$_GET['company_id'] : ($currentUser['active_company_id'] ?? null);
} else {
    $filterCompanyId = (int)$currentUser['company_id'];
}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('خطأ في التحقق من صحة الطلب');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create_user') {
        $name = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $role = trim($_POST['role'] ?? 'data_entry');
        
        $assignedCompanyId = isSuperAdmin() ? (!empty($_POST['company_id']) ? (int)$_POST['company_id'] : null) : (int)$currentUser['company_id'];

        // Non-super-admins can only create users for their own company and cannot make super_admin
        if (!isSuperAdmin() && $role === 'super_admin') {
            $role = 'data_entry';
        }

        // Collect granular permissions
        $perms = [
            'view_only' => !empty($_POST['perm_view_only']),
            'view_balances' => !empty($_POST['perm_view_balances']),
            'invoices' => !empty($_POST['perm_invoices']),
            'receipts' => !empty($_POST['perm_receipts']),
            'expenses' => !empty($_POST['perm_expenses']),
            'approve_expenses' => !empty($_POST['perm_approve_expenses']),
            'projects' => !empty($_POST['perm_projects']),
            'treasury' => !empty($_POST['perm_treasury']),
            'reports' => !empty($_POST['perm_reports']),
            'settings' => !empty($_POST['perm_settings']),
            'users' => !empty($_POST['perm_users']),
        ];
        $permsJson = json_encode($perms, JSON_UNESCAPED_UNICODE);

        if (empty($name) || empty($username) || empty($password)) {
            setFlash('error', 'الاسم واسم المستخدم وكلمة المرور مطلوبة.');
        } else {
            // Check username uniqueness
            $chk = $db->prepare("SELECT id FROM users WHERE username = :u");
            $chk->execute([':u' => $username]);
            if ($chk->fetch()) {
                setFlash('error', 'اسم المستخدم هذا مسجل بالفعل لمستخدم آخر.');
            } else {
                $ins = $db->prepare("
                    INSERT INTO users (company_id, name, username, password, role, phone, permissions, is_active, created_at)
                    VALUES (:cid, :name, :u, :p, :role, :phone, :perms, 1, :now)
                ");
                $ins->execute([
                    ':cid' => $assignedCompanyId,
                    ':name' => $name,
                    ':u' => $username,
                    ':p' => password_hash($password, PASSWORD_DEFAULT),
                    ':role' => $role,
                    ':phone' => $phone,
                    ':perms' => $permsJson,
                    ':now' => date('Y-m-d H:i:s')
                ]);
                setFlash('success', "تم إضافة المستخدم {$name} بنجاح مع ضبط الصلاحيات!");
                header('Location: users.php' . ($filterCompanyId ? '?company_id=' . $filterCompanyId : ''));
                exit;
            }
        }
    } elseif ($action === 'update_user') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $role = trim($_POST['role'] ?? 'data_entry');
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $newPassword = trim($_POST['password'] ?? '');

        // Collect granular permissions
        $perms = [
            'view_only' => !empty($_POST['perm_view_only']),
            'view_balances' => !empty($_POST['perm_view_balances']),
            'invoices' => !empty($_POST['perm_invoices']),
            'receipts' => !empty($_POST['perm_receipts']),
            'expenses' => !empty($_POST['perm_expenses']),
            'approve_expenses' => !empty($_POST['perm_approve_expenses']),
            'projects' => !empty($_POST['perm_projects']),
            'treasury' => !empty($_POST['perm_treasury']),
            'reports' => !empty($_POST['perm_reports']),
            'settings' => !empty($_POST['perm_settings']),
            'users' => !empty($_POST['perm_users']),
        ];
        $permsJson = json_encode($perms, JSON_UNESCAPED_UNICODE);

        // Security check: If company admin, ensure user belongs to their company
        if (!isSuperAdmin()) {
            $chkOwner = $db->prepare("SELECT company_id FROM users WHERE id = :id");
            $chkOwner->execute([':id' => $userId]);
            $owner = $chkOwner->fetch();
            if (!$owner || (int)$owner['company_id'] !== (int)$currentUser['company_id']) {
                setFlash('error', 'غير مسموح لك بتعديل هذا المستخدم.');
                header('Location: users.php');
                exit;
            }
            if ($role === 'super_admin') {
                $role = 'data_entry';
            }
        }

        if (empty($name) || empty($username)) {
            setFlash('error', 'الاسم واسم الدخول (Username) كلاهما مطلوب.');
        } else {
            // Check username uniqueness (excluding current user)
            $chkU = $db->prepare("SELECT id FROM users WHERE username = :u AND id != :id");
            $chkU->execute([':u' => $username, ':id' => $userId]);
            if ($chkU->fetch()) {
                setFlash('error', 'اسم الدخول هذا مستخدم بالفعل من قبل شخص آخر. اختر اسماً آخر.');
                header('Location: users.php' . ($filterCompanyId ? '?company_id=' . $filterCompanyId : ''));
                exit;
            }

            if (!empty($newPassword)) {
                $upd = $db->prepare("
                    UPDATE users 
                    SET name = :name, username = :username, phone = :phone, role = :role, permissions = :perms, is_active = :act, password = :pass
                    WHERE id = :id
                ");
                $upd->execute([
                    ':name' => $name,
                    ':username' => $username,
                    ':phone' => $phone,
                    ':role' => $role,
                    ':perms' => $permsJson,
                    ':act' => $isActive,
                    ':pass' => password_hash($newPassword, PASSWORD_DEFAULT),
                    ':id' => $userId
                ]);
            } else {
                $upd = $db->prepare("
                    UPDATE users 
                    SET name = :name, username = :username, phone = :phone, role = :role, permissions = :perms, is_active = :act
                    WHERE id = :id
                ");
                $upd->execute([
                    ':name' => $name,
                    ':username' => $username,
                    ':phone' => $phone,
                    ':role' => $role,
                    ':perms' => $permsJson,
                    ':act' => $isActive,
                    ':id' => $userId
                ]);
            }
            setFlash('success', 'تم تحديث بيانات وصلاحيات المستخدم بنجاح.');
            header('Location: users.php' . ($filterCompanyId ? '?company_id=' . $filterCompanyId : ''));
            exit;
        }
    }
}

// Fetch users query
$query = "
    SELECT u.*, c.name as company_name 
    FROM users u
    LEFT JOIN companies c ON u.company_id = c.id
";
$params = [];

if ($filterCompanyId) {
    $query .= " WHERE u.company_id = :cid";
    $params[':cid'] = $filterCompanyId;
} elseif (!isSuperAdmin()) {
    $query .= " WHERE u.company_id = :cid";
    $params[':cid'] = $currentUser['company_id'];
}
$query .= " ORDER BY u.id DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$usersList = $stmt->fetchAll();

// Fetch companies for dropdown if super admin
$allCompanies = [];
if (isSuperAdmin()) {
    $allCompanies = $db->query("SELECT id, name FROM companies WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-slate-900">المستخدمين وإدارة الصلاحيات</h1>
            <p class="text-xs text-slate-500 mt-1">
                إدارة حسابات الدخول (مدير شركة، مدخل بيانات) وتحديد كلمات المرور
            </p>
        </div>
        <div class="flex items-center space-x-2 space-x-reverse">
            <?php if (isSuperAdmin() && !empty($allCompanies)): ?>
                <form method="GET" action="users.php" class="flex items-center">
                    <select name="company_id" onchange="this.form.submit()" class="bg-white border border-slate-300 rounded-xl px-3 py-2 text-xs font-bold text-slate-700 focus:outline-none">
                        <option value="">-- كل الشركات --</option>
                        <?php foreach ($allCompanies as $comp): ?>
                            <option value="<?= $comp['id'] ?>" <?= $filterCompanyId == $comp['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($comp['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>

            <button onclick="openModal('add-user-modal')" class="inline-flex items-center bg-brand-600 hover:bg-brand-700 text-white font-bold px-4 py-2.5 rounded-xl shadow-md transition text-xs sm:text-sm">
                <i class="fa-solid fa-user-plus ml-2"></i>
                إضافة مستخدم جديد
            </button>
        </div>
    </div>

    <!-- Users Table -->
    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="bg-slate-50 text-slate-500 font-bold border-b border-slate-200">
                    <tr>
                        <th class="p-4">المستخدم</th>
                        <th class="p-4">اسم الدخول (Username)</th>
                        <th class="p-4">الشركة التابع لها</th>
                        <th class="p-4">نوع الصلاحية</th>
                        <th class="p-4">الهاتف</th>
                        <th class="p-4">الحالة</th>
                        <th class="p-4 text-center">إجراءات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">
                    <?php if (empty($usersList)): ?>
                        <tr>
                            <td colspan="7" class="p-8 text-center text-slate-400">لا يوجد مستخدمين مسجلين في هذا النطاق.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($usersList as $u): ?>
                            <tr class="hover:bg-slate-50/70 transition">
                                <td class="p-4">
                                    <div class="flex items-center space-x-2.5 space-x-reverse">
                                        <div class="w-8 h-8 rounded-full bg-slate-200 text-slate-700 flex items-center justify-center font-bold text-xs">
                                            <?= mb_substr($u['name'], 0, 1) ?>
                                        </div>
                                        <span class="font-extrabold text-slate-900"><?= htmlspecialchars($u['name']) ?></span>
                                    </div>
                                </td>
                                <td class="p-4 whitespace-nowrap font-mono font-bold text-slate-600">
                                    @<?= htmlspecialchars($u['username']) ?>
                                </td>
                                <td class="p-4 whitespace-nowrap">
                                    <?php if ($u['role'] === 'super_admin'): ?>
                                        <span class="text-indigo-600 font-bold">المنصة المركزية (ساس)</span>
                                    <?php else: ?>
                                        <span class="font-medium text-slate-800"><?= htmlspecialchars($u['company_name'] ?? 'بدون شركة') ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-4 whitespace-nowrap">
                                    <div class="flex flex-col gap-1 items-start">
                                        <?php if ($u['role'] === 'super_admin'): ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800">
                                                <i class="fa-solid fa-crown ml-1 text-amber-600"></i> سوبر أدمن
                                            </span>
                                        <?php elseif ($u['role'] === 'company_admin'): ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-indigo-100 text-indigo-800">
                                                <i class="fa-solid fa-user-shield ml-1 text-indigo-600"></i> مدير شركة
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-700">
                                                <i class="fa-solid fa-user-check ml-1 text-slate-500"></i> موظف / مستخدم
                                            </span>
                                        <?php endif; ?>

                                        <!-- Granular Permission Tags -->
                                        <?php
                                        $uPerms = getUserPermissions($u);
                                        $isVOnly = !empty($uPerms['view_only']);
                                        $canBal = !empty($uPerms['view_balances']);
                                        ?>
                                        <div class="flex flex-wrap gap-1 mt-0.5">
                                            <?php if ($isVOnly): ?>
                                                <span class="inline-flex items-center px-1.5 py-0.2 rounded text-[9px] font-black bg-amber-50 text-amber-800 border border-amber-300">
                                                    <i class="fa-solid fa-eye ml-1 text-[8px]"></i>مشاهدة فقط
                                                </span>
                                            <?php endif; ?>

                                            <?php if (!$canBal && $u['role'] !== 'super_admin'): ?>
                                                <span class="inline-flex items-center px-1.5 py-0.2 rounded text-[9px] font-black bg-rose-50 text-rose-800 border border-rose-300">
                                                    <i class="fa-solid fa-eye-slash ml-1 text-[8px]"></i>حجب الخزنة
                                                </span>
                                            <?php endif; ?>

                                            <?php if (!empty($uPerms['invoices'])): ?>
                                                <span class="px-1 py-0.2 bg-blue-50 text-blue-700 rounded text-[9px] font-bold">فواتير</span>
                                            <?php endif; ?>
                                            <?php if (!empty($uPerms['receipts'])): ?>
                                                <span class="px-1 py-0.2 bg-emerald-50 text-emerald-700 rounded text-[9px] font-bold">قبض</span>
                                            <?php endif; ?>
                                            <?php if (!empty($uPerms['expenses'])): ?>
                                                <span class="px-1 py-0.2 bg-rose-50 text-rose-700 rounded text-[9px] font-bold">صرف</span>
                                            <?php endif; ?>
                                            <?php if (!empty($uPerms['projects'])): ?>
                                                <span class="px-1 py-0.2 bg-amber-50 text-amber-800 rounded text-[9px] font-bold">عقارات</span>
                                            <?php endif; ?>
                                            <?php if (!empty($uPerms['treasury']) && $canBal): ?>
                                                <span class="px-1 py-0.2 bg-purple-50 text-purple-700 rounded text-[9px] font-bold">خزنة</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="p-4 whitespace-nowrap text-slate-500 font-mono">
                                    <?= htmlspecialchars($u['phone'] ?: '-') ?>
                                </td>
                                <td class="p-4 whitespace-nowrap">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?= (int)$u['is_active'] === 1 ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800' ?>">
                                        <?= (int)$u['is_active'] === 1 ? 'نشط' : 'معطل' ?>
                                    </span>
                                </td>
                                <td class="p-4 whitespace-nowrap text-center">
                                    <button onclick='editUser(<?= json_encode($u) ?>)' class="bg-slate-100 hover:bg-slate-200 text-slate-700 px-3 py-1.5 rounded-xl text-xs font-bold transition inline-flex items-center">
                                        <i class="fa-solid fa-user-pen ml-1.5 text-brand-600"></i> تعديل وصلاحيات
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

<!-- ADD USER MODAL -->
<div id="add-user-modal" class="modal-container fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4 overflow-y-auto">
    <div class="modal-backdrop fixed inset-0" onclick="closeModal('add-user-modal')"></div>
    <div class="relative bg-white rounded-3xl shadow-2xl max-w-2xl w-full p-6 modal-animate-enter z-10 border border-slate-100 max-h-[92vh] overflow-y-auto">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4 sticky top-0 bg-white z-20">
            <div>
                <h3 class="font-black text-slate-900 text-lg flex items-center">
                    <i class="fa-solid fa-user-plus ml-2 text-brand-600"></i>
                    إضافة مستخدم جديد وتحديد الصلاحيات
                </h3>
                <p class="text-xs text-slate-500 mt-0.5">حدد بيانات الدخول وخيارات الصلاحيات بدقة لهذا المستخدم</p>
            </div>
            <button onclick="closeModal('add-user-modal')" class="text-slate-400 hover:text-slate-600 text-lg p-1.5 rounded-lg hover:bg-slate-100">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form action="users.php" method="POST" class="space-y-4">
            <?= getCSRFInput() ?>
            <input type="hidden" name="action" value="create_user">

            <?php if (isSuperAdmin()): ?>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">الشركة التابع لها</label>
                    <select name="company_id" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                        <option value="">-- بدون شركة (سوبر أدمن) --</option>
                        <?php foreach ($allCompanies as $comp): ?>
                            <option value="<?= $comp['id'] ?>" <?= $filterCompanyId == $comp['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($comp['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <!-- User Info Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">الاسم الكامل <span class="text-red-500">*</span></label>
                    <input type="text" name="name" required placeholder="مثال: أحمد مصطفى" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">رقم الهاتف</label>
                    <input type="text" name="phone" placeholder="010xxxxxxxx" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">اسم الدخول (Username) <span class="text-red-500">*</span></label>
                    <input type="text" name="username" required placeholder="ahmed123" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs font-mono focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">كلمة المرور <span class="text-red-500">*</span></label>
                    <input type="password" name="password" required placeholder="••••••••" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">الصفة الأساسية <span class="text-red-500">*</span></label>
                <select name="role" id="add_role" required class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                    <option value="data_entry" selected>موظف / مدخل بيانات (محدد الصلاحيات)</option>
                    <option value="company_admin">مدير الشركة (إدارة شاملة)</option>
                    <?php if (isSuperAdmin()): ?>
                        <option value="super_admin">سوبر أدمن (المنصة العامة)</option>
                    <?php endif; ?>
                </select>
            </div>

            <!-- Role Presets Pills -->
            <div class="bg-indigo-50/70 border border-indigo-100 rounded-2xl p-3">
                <span class="block text-xs font-black text-indigo-900 mb-2 flex items-center">
                    <i class="fa-solid fa-wand-magic-sparkles ml-1.5 text-indigo-600"></i>
                    تطبيق قالب صلاحيات سريع بنقرة واحدة:
                </span>
                <div class="flex flex-wrap gap-1.5">
                    <button type="button" onclick="applyRolePreset('add', 'admin')" class="px-2.5 py-1 rounded-lg text-xs font-bold bg-white text-indigo-700 border border-indigo-200 hover:bg-indigo-600 hover:text-white transition shadow-2xs">
                        🌟 مدير كامل
                    </button>
                    <button type="button" onclick="applyRolePreset('add', 'invoicing')" class="px-2.5 py-1 rounded-lg text-xs font-bold bg-white text-emerald-700 border border-emerald-200 hover:bg-emerald-600 hover:text-white transition shadow-2xs">
                        🧾 فواتير وقبض (حجب الخزنة)
                    </button>
                    <button type="button" onclick="applyRolePreset('add', 'projects')" class="px-2.5 py-1 rounded-lg text-xs font-bold bg-white text-amber-800 border border-amber-200 hover:bg-amber-600 hover:text-white transition shadow-2xs">
                        🏗️ مشرف مشاريع وعقارات
                    </button>
                    <button type="button" onclick="applyRolePreset('add', 'data_entry')" class="px-2.5 py-1 rounded-lg text-xs font-bold bg-white text-slate-700 border border-slate-200 hover:bg-slate-700 hover:text-white transition shadow-2xs">
                        ✍️ مدخل بيانات عام
                    </button>
                    <button type="button" onclick="applyRolePreset('add', 'view_only')" class="px-2.5 py-1 rounded-lg text-xs font-bold bg-white text-rose-700 border border-rose-200 hover:bg-rose-600 hover:text-white transition shadow-2xs">
                        👁️ مشاهد فقط (View Only)
                    </button>
                </div>
            </div>

            <!-- Granular Permissions Matrix -->
            <div class="space-y-3 pt-1">
                <!-- Group 1: General & Balances Control -->
                <div class="border border-slate-200 rounded-2xl p-3.5 bg-slate-50/50">
                    <span class="block text-xs font-black text-slate-800 mb-2.5 flex items-center">
                        <i class="fa-solid fa-shield-halved ml-1.5 text-indigo-600"></i>
                        قواعد الأمان والسيولة المالية:
                    </span>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                        <label class="flex items-start space-x-2.5 space-x-reverse p-2.5 rounded-xl bg-white border border-slate-200 cursor-pointer hover:border-brand-300 transition">
                            <input type="checkbox" id="add_perm_view_only" name="perm_view_only" value="1" class="rounded text-brand-600 focus:ring-brand-500 w-4 h-4 mt-0.5">
                            <div>
                                <span class="text-xs font-bold text-slate-900 block">حساب للمشاهدة فقط (View Only)</span>
                                <span class="text-[10px] text-slate-500 block leading-tight mt-0.5">يمنع الموظف من إجراء أي حفظ، إضافة، تعديل أو حذف في أي قسم، ويسمح بالاطلاع فقط.</span>
                            </div>
                        </label>

                        <label class="flex items-start space-x-2.5 space-x-reverse p-2.5 rounded-xl bg-white border border-slate-200 cursor-pointer hover:border-brand-300 transition">
                            <input type="checkbox" id="add_perm_view_balances" name="perm_view_balances" value="1" checked class="rounded text-brand-600 focus:ring-brand-500 w-4 h-4 mt-0.5">
                            <div>
                                <span class="text-xs font-bold text-slate-900 block">إظهار رصيد الخزنة والسيولة</span>
                                <span class="text-[10px] text-slate-500 block leading-tight mt-0.5">إذا أُلغي التحديد، يُحجب رصيد الخزنة تماماً ويظهر بدلاً منه <strong class="text-rose-600 font-mono">****</strong> لحماية السيولة.</span>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Group 2: Operational Modules -->
                <div class="border border-slate-200 rounded-2xl p-3.5 bg-slate-50/50">
                    <span class="block text-xs font-black text-slate-800 mb-2.5 flex items-center">
                        <i class="fa-solid fa-cubes ml-1.5 text-indigo-600"></i>
                        الأقسام والشاشات المسموح بالوصول إليها:
                    </span>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        <label class="flex items-center space-x-2 space-x-reverse p-2 rounded-xl bg-white border border-slate-200 cursor-pointer hover:border-brand-300 transition">
                            <input type="checkbox" id="add_perm_invoices" name="perm_invoices" value="1" checked class="rounded text-brand-600 focus:ring-brand-500 w-4 h-4">
                            <span class="text-xs font-bold text-slate-800">🧾 الفواتير الضريبية المبسطة</span>
                        </label>

                        <label class="flex items-center space-x-2 space-x-reverse p-2 rounded-xl bg-white border border-slate-200 cursor-pointer hover:border-brand-300 transition">
                            <input type="checkbox" id="add_perm_receipts" name="perm_receipts" value="1" checked class="rounded text-brand-600 focus:ring-brand-500 w-4 h-4">
                            <span class="text-xs font-bold text-slate-800">📥 سندات القبض واستلام النقدية</span>
                        </label>

                        <label class="flex items-center space-x-2 space-x-reverse p-2 rounded-xl bg-white border border-slate-200 cursor-pointer hover:border-brand-300 transition">
                            <input type="checkbox" id="add_perm_expenses" name="perm_expenses" value="1" checked class="rounded text-brand-600 focus:ring-brand-500 w-4 h-4">
                            <span class="text-xs font-bold text-slate-800">📤 سندات الصرف والمصروفات</span>
                        </label>

                        <label class="flex items-start space-x-2.5 space-x-reverse p-3 rounded-xl bg-amber-50/80 border-2 border-amber-300 cursor-pointer hover:border-amber-400 transition sm:col-span-2">
                            <input type="checkbox" id="add_perm_approve_expenses" name="perm_approve_expenses" value="1" class="rounded text-amber-600 focus:ring-amber-500 w-4 h-4 mt-0.5">
                            <div>
                                <span class="text-xs font-black text-amber-950 block">🛡️ صلاحية اعتماد المصروفات والخصم الفوري من الخزنة (صلاحية إدارية)</span>
                                <span class="text-[10.5px] text-amber-900 block leading-tight mt-1">
                                    إذا تم تحديدها: تُعتمد المصروفات وتُخصم فوراً من الخزنة.  
                                    <strong>إذا أُلغي تحديدها (الموظف ليس أدمن):</strong> أي مصروف يسجله الموظف يدخل تلقائياً في حالة <span class="bg-amber-200 text-amber-950 px-1 py-0.5 rounded font-bold">معلق وبانتظار موافقة السوبر أدمن</span>، ولا يُخصم من الخزنة ولا يُضاف لتكاليف المشروع حتى يعتمده الأدمن.
                                </span>
                            </div>
                        </label>

                        <label class="flex items-center space-x-2 space-x-reverse p-2 rounded-xl bg-white border border-slate-200 cursor-pointer hover:border-brand-300 transition">
                            <input type="checkbox" id="add_perm_projects" name="perm_projects" value="1" checked class="rounded text-brand-600 focus:ring-brand-500 w-4 h-4">
                            <span class="text-xs font-bold text-slate-800">🏗️ المشاريع العقارية وعُهد العقار</span>
                        </label>

                        <label class="flex items-center space-x-2 space-x-reverse p-2 rounded-xl bg-white border border-slate-200 cursor-pointer hover:border-brand-300 transition">
                            <input type="checkbox" id="add_perm_treasury" name="perm_treasury" value="1" checked class="rounded text-brand-600 focus:ring-brand-500 w-4 h-4">
                            <span class="text-xs font-bold text-slate-800">🏦 الخزنة وحركات الإيداع</span>
                        </label>

                        <label class="flex items-center space-x-2 space-x-reverse p-2 rounded-xl bg-white border border-slate-200 cursor-pointer hover:border-brand-300 transition">
                            <input type="checkbox" id="add_perm_reports" name="perm_reports" value="1" class="rounded text-brand-600 focus:ring-brand-500 w-4 h-4">
                            <span class="text-xs font-bold text-slate-800">📊 التقارير المالية والإحصائيات</span>
                        </label>

                        <label class="flex items-center space-x-2 space-x-reverse p-2 rounded-xl bg-white border border-slate-200 cursor-pointer hover:border-brand-300 transition">
                            <input type="checkbox" id="add_perm_settings" name="perm_settings" value="1" class="rounded text-brand-600 focus:ring-brand-500 w-4 h-4">
                            <span class="text-xs font-bold text-slate-800">⚙️ إعدادات وهوية الشركة والمقر</span>
                        </label>

                        <label class="flex items-center space-x-2 space-x-reverse p-2 rounded-xl bg-white border border-slate-200 cursor-pointer hover:border-brand-300 transition">
                            <input type="checkbox" id="add_perm_users" name="perm_users" value="1" class="rounded text-brand-600 focus:ring-brand-500 w-4 h-4">
                            <span class="text-xs font-bold text-slate-800">👥 إدارة المستخدمين والصلاحيات</span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="flex items-center space-x-2 space-x-reverse pt-3 sticky bottom-0 bg-white pb-1 border-t border-slate-100">
                <button type="submit" class="flex-1 bg-brand-600 hover:bg-brand-700 text-white font-bold py-2.5 px-4 rounded-xl shadow-md transition text-xs sm:text-sm">
                    إضافة المستخدم بالصلاحيات المحددة
                </button>
                <button type="button" onclick="closeModal('add-user-modal')" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold py-2.5 px-4 rounded-xl transition text-xs">
                    إلغاء
                </button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT USER MODAL -->
<div id="edit-user-modal" class="modal-container fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4 overflow-y-auto">
    <div class="modal-backdrop fixed inset-0" onclick="closeModal('edit-user-modal')"></div>
    <div class="relative bg-white rounded-3xl shadow-2xl max-w-2xl w-full p-6 modal-animate-enter z-10 border border-slate-100 max-h-[92vh] overflow-y-auto">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4 sticky top-0 bg-white z-20">
            <div>
                <h3 class="font-black text-slate-900 text-lg flex items-center">
                    <i class="fa-solid fa-user-pen ml-2 text-brand-600"></i>
                    تعديل بيانات وصلاحيات المستخدم
                </h3>
                <p class="text-xs text-slate-500 mt-0.5">تحكم في الصلاحيات بدقة وتفعيل أو تعطيل الحساب</p>
            </div>
            <button onclick="closeModal('edit-user-modal')" class="text-slate-400 hover:text-slate-600 text-lg p-1.5 rounded-lg hover:bg-slate-100">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form action="users.php" method="POST" class="space-y-4">
            <?= getCSRFInput() ?>
            <input type="hidden" name="action" value="update_user">
            <input type="hidden" id="edit_user_id" name="user_id">

            <!-- User Info Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">الاسم الكامل <span class="text-red-500">*</span></label>
                    <input type="text" id="edit_name" name="name" required class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">الهاتف</label>
                    <input type="text" id="edit_phone" name="phone" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">اسم الدخول (Username) <span class="text-red-500">*</span></label>
                    <input type="text" id="edit_username" name="username" required placeholder="مثال: ahmed123" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs font-mono focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">تغيير كلمة المرور</label>
                    <input type="password" name="password" placeholder="اتركه فارغاً إن لم ترغب بالتغيير..." class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 items-center">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">الصفة الأساسية <span class="text-red-500">*</span></label>
                    <select id="edit_role" name="role" required class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                        <option value="data_entry">موظف / مدخل بيانات (محدد الصلاحيات)</option>
                        <option value="company_admin">مدير الشركة (إدارة شاملة)</option>
                        <?php if (isSuperAdmin()): ?>
                            <option value="super_admin">سوبر أدمن (المنصة العامة)</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="pt-4">
                    <label class="flex items-center space-x-2 space-x-reverse cursor-pointer">
                        <input type="checkbox" id="edit_is_active" name="is_active" value="1" class="rounded text-emerald-600 focus:ring-emerald-500 w-4 h-4">
                        <span class="text-xs font-bold text-slate-800">الحساب نشط ومفعل للدخول</span>
                    </label>
                </div>
            </div>

            <!-- Role Presets Pills -->
            <div class="bg-indigo-50/70 border border-indigo-100 rounded-2xl p-3">
                <span class="block text-xs font-black text-indigo-900 mb-2 flex items-center">
                    <i class="fa-solid fa-wand-magic-sparkles ml-1.5 text-indigo-600"></i>
                    تطبيق قالب صلاحيات سريع بنقرة واحدة:
                </span>
                <div class="flex flex-wrap gap-1.5">
                    <button type="button" onclick="applyRolePreset('edit', 'admin')" class="px-2.5 py-1 rounded-lg text-xs font-bold bg-white text-indigo-700 border border-indigo-200 hover:bg-indigo-600 hover:text-white transition shadow-2xs">
                        🌟 مدير كامل
                    </button>
                    <button type="button" onclick="applyRolePreset('edit', 'invoicing')" class="px-2.5 py-1 rounded-lg text-xs font-bold bg-white text-emerald-700 border border-emerald-200 hover:bg-emerald-600 hover:text-white transition shadow-2xs">
                        🧾 فواتير وقبض (حجب الخزنة)
                    </button>
                    <button type="button" onclick="applyRolePreset('edit', 'projects')" class="px-2.5 py-1 rounded-lg text-xs font-bold bg-white text-amber-800 border border-amber-200 hover:bg-amber-600 hover:text-white transition shadow-2xs">
                        🏗️ مشرف مشاريع وعقارات
                    </button>
                    <button type="button" onclick="applyRolePreset('edit', 'data_entry')" class="px-2.5 py-1 rounded-lg text-xs font-bold bg-white text-slate-700 border border-slate-200 hover:bg-slate-700 hover:text-white transition shadow-2xs">
                        ✍️ مدخل بيانات عام
                    </button>
                    <button type="button" onclick="applyRolePreset('edit', 'view_only')" class="px-2.5 py-1 rounded-lg text-xs font-bold bg-white text-rose-700 border border-rose-200 hover:bg-rose-600 hover:text-white transition shadow-2xs">
                        👁️ مشاهد فقط (View Only)
                    </button>
                </div>
            </div>

            <!-- Granular Permissions Matrix -->
            <div class="space-y-3 pt-1">
                <!-- Group 1: General & Balances Control -->
                <div class="border border-slate-200 rounded-2xl p-3.5 bg-slate-50/50">
                    <span class="block text-xs font-black text-slate-800 mb-2.5 flex items-center">
                        <i class="fa-solid fa-shield-halved ml-1.5 text-indigo-600"></i>
                        قواعد الأمان والسيولة المالية:
                    </span>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                        <label class="flex items-start space-x-2.5 space-x-reverse p-2.5 rounded-xl bg-white border border-slate-200 cursor-pointer hover:border-brand-300 transition">
                            <input type="checkbox" id="edit_perm_view_only" name="perm_view_only" value="1" class="rounded text-brand-600 focus:ring-brand-500 w-4 h-4 mt-0.5">
                            <div>
                                <span class="text-xs font-bold text-slate-900 block">حساب للمشاهدة فقط (View Only)</span>
                                <span class="text-[10px] text-slate-500 block leading-tight mt-0.5">يمنع الموظف من إجراء أي حفظ، إضافة، تعديل أو حذف في أي قسم، ويسمح بالاطلاع فقط.</span>
                            </div>
                        </label>

                        <label class="flex items-start space-x-2.5 space-x-reverse p-2.5 rounded-xl bg-white border border-slate-200 cursor-pointer hover:border-brand-300 transition">
                            <input type="checkbox" id="edit_perm_view_balances" name="perm_view_balances" value="1" class="rounded text-brand-600 focus:ring-brand-500 w-4 h-4 mt-0.5">
                            <div>
                                <span class="text-xs font-bold text-slate-900 block">إظهار رصيد الخزنة والسيولة</span>
                                <span class="text-[10px] text-slate-500 block leading-tight mt-0.5">إذا أُلغي التحديد، يُحجب رصيد الخزنة تماماً ويظهر بدلاً منه <strong class="text-rose-600 font-mono">****</strong> لحماية السيولة.</span>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Group 2: Operational Modules -->
                <div class="border border-slate-200 rounded-2xl p-3.5 bg-slate-50/50">
                    <span class="block text-xs font-black text-slate-800 mb-2.5 flex items-center">
                        <i class="fa-solid fa-cubes ml-1.5 text-indigo-600"></i>
                        الأقسام والشاشات المسموح بالوصول إليها:
                    </span>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        <label class="flex items-center space-x-2 space-x-reverse p-2 rounded-xl bg-white border border-slate-200 cursor-pointer hover:border-brand-300 transition">
                            <input type="checkbox" id="edit_perm_invoices" name="perm_invoices" value="1" class="rounded text-brand-600 focus:ring-brand-500 w-4 h-4">
                            <span class="text-xs font-bold text-slate-800">🧾 الفواتير الضريبية المبسطة</span>
                        </label>

                        <label class="flex items-center space-x-2 space-x-reverse p-2 rounded-xl bg-white border border-slate-200 cursor-pointer hover:border-brand-300 transition">
                            <input type="checkbox" id="edit_perm_receipts" name="perm_receipts" value="1" class="rounded text-brand-600 focus:ring-brand-500 w-4 h-4">
                            <span class="text-xs font-bold text-slate-800">📥 سندات القبض واستلام النقدية</span>
                        </label>

                        <label class="flex items-center space-x-2 space-x-reverse p-2 rounded-xl bg-white border border-slate-200 cursor-pointer hover:border-brand-300 transition">
                            <input type="checkbox" id="edit_perm_expenses" name="perm_expenses" value="1" class="rounded text-brand-600 focus:ring-brand-500 w-4 h-4">
                            <span class="text-xs font-bold text-slate-800">📤 سندات الصرف والمصروفات</span>
                        </label>

                        <label class="flex items-start space-x-2.5 space-x-reverse p-3 rounded-xl bg-amber-50/80 border-2 border-amber-300 cursor-pointer hover:border-amber-400 transition sm:col-span-2">
                            <input type="checkbox" id="edit_perm_approve_expenses" name="perm_approve_expenses" value="1" class="rounded text-amber-600 focus:ring-amber-500 w-4 h-4 mt-0.5">
                            <div>
                                <span class="text-xs font-black text-amber-950 block">🛡️ صلاحية اعتماد المصروفات والخصم الفوري من الخزنة (صلاحية إدارية)</span>
                                <span class="text-[10.5px] text-amber-900 block leading-tight mt-1">
                                    إذا تم تحديدها: تُعتمد المصروفات وتُخصم فوراً من الخزنة.  
                                    <strong>إذا أُلغي تحديدها (الموظف ليس أدمن):</strong> أي مصروف يسجله الموظف يدخل تلقائياً في حالة <span class="bg-amber-200 text-amber-950 px-1 py-0.5 rounded font-bold">معلق وبانتظار موافقة السوبر أدمن</span>، ولا يُخصم من الخزنة ولا يُضاف لتكاليف المشروع حتى يعتمده الأدمن.
                                </span>
                            </div>
                        </label>

                        <label class="flex items-center space-x-2 space-x-reverse p-2 rounded-xl bg-white border border-slate-200 cursor-pointer hover:border-brand-300 transition">
                            <input type="checkbox" id="edit_perm_projects" name="perm_projects" value="1" class="rounded text-brand-600 focus:ring-brand-500 w-4 h-4">
                            <span class="text-xs font-bold text-slate-800">🏗️ المشاريع العقارية وعُهد العقار</span>
                        </label>

                        <label class="flex items-center space-x-2 space-x-reverse p-2 rounded-xl bg-white border border-slate-200 cursor-pointer hover:border-brand-300 transition">
                            <input type="checkbox" id="edit_perm_treasury" name="perm_treasury" value="1" class="rounded text-brand-600 focus:ring-brand-500 w-4 h-4">
                            <span class="text-xs font-bold text-slate-800">🏦 الخزنة وحركات الإيداع</span>
                        </label>

                        <label class="flex items-center space-x-2 space-x-reverse p-2 rounded-xl bg-white border border-slate-200 cursor-pointer hover:border-brand-300 transition">
                            <input type="checkbox" id="edit_perm_reports" name="perm_reports" value="1" class="rounded text-brand-600 focus:ring-brand-500 w-4 h-4">
                            <span class="text-xs font-bold text-slate-800">📊 التقارير المالية والإحصائيات</span>
                        </label>

                        <label class="flex items-center space-x-2 space-x-reverse p-2 rounded-xl bg-white border border-slate-200 cursor-pointer hover:border-brand-300 transition">
                            <input type="checkbox" id="edit_perm_settings" name="perm_settings" value="1" class="rounded text-brand-600 focus:ring-brand-500 w-4 h-4">
                            <span class="text-xs font-bold text-slate-800">⚙️ إعدادات وهوية الشركة والمقر</span>
                        </label>

                        <label class="flex items-center space-x-2 space-x-reverse p-2 rounded-xl bg-white border border-slate-200 cursor-pointer hover:border-brand-300 transition">
                            <input type="checkbox" id="edit_perm_users" name="perm_users" value="1" class="rounded text-brand-600 focus:ring-brand-500 w-4 h-4">
                            <span class="text-xs font-bold text-slate-800">👥 إدارة المستخدمين والصلاحيات</span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="flex items-center space-x-2 space-x-reverse pt-3 sticky bottom-0 bg-white pb-1 border-t border-slate-100">
                <button type="submit" class="flex-1 bg-brand-600 hover:bg-brand-700 text-white font-bold py-2.5 px-4 rounded-xl shadow-md transition text-xs sm:text-sm">
                    حفظ التعديلات والصلاحيات
                </button>
                <button type="button" onclick="closeModal('edit-user-modal')" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold py-2.5 px-4 rounded-xl transition text-xs">
                    إلغاء
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const PRESETS = {
    admin: {
        view_only: false,
        view_balances: true,
        invoices: true,
        receipts: true,
        expenses: true,
        approve_expenses: true,
        projects: true,
        treasury: true,
        reports: true,
        settings: true,
        users: true
    },
    invoicing: {
        view_only: false,
        view_balances: false,
        invoices: true,
        receipts: true,
        expenses: false,
        approve_expenses: false,
        projects: false,
        treasury: false,
        reports: false,
        settings: false,
        users: false
    },
    projects: {
        view_only: false,
        view_balances: false,
        invoices: false,
        receipts: false,
        expenses: true,
        approve_expenses: false,
        projects: true,
        treasury: false,
        reports: false,
        settings: false,
        users: false
    },
    data_entry: {
        view_only: false,
        view_balances: true,
        invoices: true,
        receipts: true,
        expenses: true,
        approve_expenses: false,
        projects: true,
        treasury: true,
        reports: false,
        settings: false,
        users: false
    },
    view_only: {
        view_only: true,
        view_balances: true,
        invoices: true,
        receipts: true,
        expenses: true,
        approve_expenses: false,
        projects: true,
        treasury: true,
        reports: true,
        settings: false,
        users: false
    }
};

function applyRolePreset(prefix, presetKey) {
    const p = PRESETS[presetKey];
    if (!p) return;
    for (const [k, v] of Object.entries(p)) {
        const el = document.getElementById(prefix + '_perm_' + k);
        if (el) {
            el.checked = !!v;
        }
    }
}

function editUser(u) {
    document.getElementById('edit_user_id').value = u.id;
    document.getElementById('edit_name').value = u.name;
    document.getElementById('edit_username').value = u.username;
    document.getElementById('edit_phone').value = u.phone || '';
    document.getElementById('edit_role').value = u.role;
    document.getElementById('edit_is_active').checked = parseInt(u.is_active) === 1;

    // Permissions decoding
    let perms = {};
    if (u.permissions) {
        if (typeof u.permissions === 'string') {
            try {
                perms = JSON.parse(u.permissions);
            } catch(e) {
                perms = {};
            }
        } else if (typeof u.permissions === 'object') {
            perms = u.permissions;
        }
    }

    // If permissions empty, fallback to role default
    if (Object.keys(perms).length === 0) {
        if (u.role === 'super_admin' || u.role === 'company_admin') {
            perms = PRESETS.admin;
        } else {
            perms = PRESETS.data_entry;
        }
    }

    // Set checkboxes
    const keys = ['view_only', 'view_balances', 'invoices', 'receipts', 'expenses', 'approve_expenses', 'projects', 'treasury', 'reports', 'settings', 'users'];
    keys.forEach(k => {
        const el = document.getElementById('edit_perm_' + k);
        if (el) {
            el.checked = !!perms[k];
        }
    });

    openModal('edit-user-modal');
}
</script>

<?php include_once __DIR__ . '/includes/footer.php'; ?>
