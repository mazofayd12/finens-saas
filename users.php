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
                    INSERT INTO users (company_id, name, username, password, role, phone, is_active, created_at)
                    VALUES (:cid, :name, :u, :p, :role, :phone, 1, :now)
                ");
                $ins->execute([
                    ':cid' => $assignedCompanyId,
                    ':name' => $name,
                    ':u' => $username,
                    ':p' => password_hash($password, PASSWORD_DEFAULT),
                    ':role' => $role,
                    ':phone' => $phone,
                    ':now' => date('Y-m-d H:i:s')
                ]);
                setFlash('success', "تم إضافة المستخدم {$name} بنجاح!");
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
                    SET name = :name, username = :username, phone = :phone, role = :role, is_active = :act, password = :pass
                    WHERE id = :id
                ");
                $upd->execute([
                    ':name' => $name,
                    ':username' => $username,
                    ':phone' => $phone,
                    ':role' => $role,
                    ':act' => $isActive,
                    ':pass' => password_hash($newPassword, PASSWORD_DEFAULT),
                    ':id' => $userId
                ]);
            } else {
                $upd = $db->prepare("
                    UPDATE users 
                    SET name = :name, username = :username, phone = :phone, role = :role, is_active = :act
                    WHERE id = :id
                ");
                $upd->execute([
                    ':name' => $name,
                    ':username' => $username,
                    ':phone' => $phone,
                    ':role' => $role,
                    ':act' => $isActive,
                    ':id' => $userId
                ]);
            }
            setFlash('success', 'تم تحديث بيانات المستخدم واسم الدخول وكلمة المرور بنجاح.');
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
                                            <i class="fa-solid fa-pen-to-square ml-1 text-slate-500"></i> مدخل بيانات
                                        </span>
                                    <?php endif; ?>
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
                                    <button onclick='editUser(<?= json_encode($u) ?>)' class="bg-slate-100 hover:bg-slate-200 text-slate-700 px-2.5 py-1 rounded-lg text-xs font-bold transition">
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

<!-- ADD USER MODAL -->
<div id="add-user-modal" class="modal-container fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4 overflow-y-auto">
    <div class="modal-backdrop fixed inset-0"></div>
    <div class="relative bg-white rounded-3xl shadow-2xl max-w-md w-full p-6 modal-animate-enter z-10 border border-slate-100">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
            <h3 class="font-black text-slate-800 text-lg">إضافة مستخدم جديد</h3>
            <button onclick="closeModal('add-user-modal')" class="text-slate-400 hover:text-slate-600 text-lg">
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

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">الاسم الكامل <span class="text-red-500">*</span></label>
                <input type="text" name="name" required placeholder="مثال: أحمد مصطفى" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">اسم الدخول <span class="text-red-500">*</span></label>
                    <input type="text" name="username" required placeholder="ahmed123" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs font-mono focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">كلمة المرور <span class="text-red-500">*</span></label>
                    <input type="password" name="password" required placeholder="••••••••" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">الصلاحية <span class="text-red-500">*</span></label>
                    <select name="role" required class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                        <option value="data_entry">مدخل بيانات (تسجيل فقط)</option>
                        <option value="company_admin">مدير الشركة (إشراف كامل)</option>
                        <?php if (isSuperAdmin()): ?>
                            <option value="super_admin">سوبر أدمن (المنصة)</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">رقم الهاتف</label>
                    <input type="text" name="phone" placeholder="010xxxxxxxx" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                </div>
            </div>

            <div class="flex items-center space-x-2 space-x-reverse pt-2">
                <button type="submit" class="flex-1 bg-brand-600 hover:bg-brand-700 text-white font-bold py-2.5 px-4 rounded-xl shadow-md transition text-xs">
                    إضافة المستخدم
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
    <div class="modal-backdrop fixed inset-0"></div>
    <div class="relative bg-white rounded-3xl shadow-2xl max-w-md w-full p-6 modal-animate-enter z-10 border border-slate-100">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
            <h3 class="font-black text-slate-800 text-lg">تعديل بيانات المستخدم</h3>
            <button onclick="closeModal('edit-user-modal')" class="text-slate-400 hover:text-slate-600 text-lg">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form action="users.php" method="POST" class="space-y-4">
            <?= getCSRFInput() ?>
            <input type="hidden" name="action" value="update_user">
            <input type="hidden" id="edit_user_id" name="user_id">

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">الاسم الكامل <span class="text-red-500">*</span></label>
                <input type="text" id="edit_name" name="name" required class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">اسم الدخول (Username) <span class="text-red-500">*</span></label>
                <input type="text" id="edit_username" name="username" required placeholder="مثال: ahmed123" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs font-mono focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">الصلاحية</label>
                    <select id="edit_role" name="role" required class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                        <option value="data_entry">مدخل بيانات</option>
                        <option value="company_admin">مدير الشركة</option>
                        <?php if (isSuperAdmin()): ?>
                            <option value="super_admin">سوبر أدمن</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">الهاتف</label>
                    <input type="text" id="edit_phone" name="phone" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">تغيير كلمة المرور (اتركه فارغاً إن لم ترغب بالتغيير)</label>
                <input type="password" name="password" placeholder="أدخل كلمة مرور جديدة لتغييرها..." class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                <span class="text-[10px] text-slate-400 mt-0.5 block">اكتب كلمة مرور جديدة هنا إذا أردت تغييرها للمستخدم، أو اترك الحقل فارغاً للإبقاء على كلمته الحالية</span>
            </div>

            <div class="flex items-center space-x-2 space-x-reverse">
                <input type="checkbox" id="edit_is_active" name="is_active" value="1" class="rounded text-emerald-600 focus:ring-emerald-500 w-4 h-4">
                <label for="edit_is_active" class="text-xs font-bold text-slate-700 cursor-pointer">الحساب نشط ومفعل</label>
            </div>

            <div class="flex items-center space-x-2 space-x-reverse pt-2">
                <button type="submit" class="flex-1 bg-brand-600 hover:bg-brand-700 text-white font-bold py-2.5 px-4 rounded-xl shadow-md transition text-xs">
                    حفظ التعديلات
                </button>
                <button type="button" onclick="closeModal('edit-user-modal')" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold py-2.5 px-4 rounded-xl transition text-xs">
                    إلغاء
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function editUser(u) {
    document.getElementById('edit_user_id').value = u.id;
    document.getElementById('edit_name').value = u.name;
    document.getElementById('edit_username').value = u.username;
    document.getElementById('edit_phone').value = u.phone || '';
    document.getElementById('edit_role').value = u.role;
    document.getElementById('edit_is_active').checked = parseInt(u.is_active) === 1;
    openModal('edit-user-modal');
}
</script>

<?php include_once __DIR__ . '/includes/footer.php'; ?>
