<?php
require_once __DIR__ . '/config/app.php';
requireLogin();
requireModule('expenses');

$currentUser = getCurrentUser();
$activeCompanyId = (int)$currentUser['active_company_id'];
$currency = $currentUser['currency'] ?? 'ج.م';
$db = getDBConnection();

if (!isCompanyAdmin()) {
    setFlash('error', 'ليس لديك صلاحية لتعديل بنود المصروفات.');
    header('Location: expenses.php');
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('خطأ في التحقق من صحة الطلب');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create_category') {
        $name = trim($_POST['name'] ?? '');
        $icon = trim($_POST['icon'] ?? 'fa-folder');

        if (empty($name)) {
            setFlash('error', 'اسم البند مطلوب.');
        } else {
            $ins = $db->prepare("
                INSERT INTO expense_categories (company_id, name, icon, is_active, created_at)
                VALUES (:cid, :name, :icon, 1, :now)
            ");
            $ins->execute([
                ':cid' => $activeCompanyId,
                ':name' => $name,
                ':icon' => $icon ?: 'fa-folder',
                ':now' => date('Y-m-d H:i:s')
            ]);
            setFlash('success', "تم إضافة بند المصروف '{$name}' بنجاح.");
            header('Location: expense_categories.php');
            exit;
        }
    } elseif ($action === 'update_category') {
        $id = (int)($_POST['category_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if (empty($name)) {
            setFlash('error', 'اسم البند مطلوب.');
        } else {
            $upd = $db->prepare("
                UPDATE expense_categories 
                SET name = :name, is_active = :act
                WHERE id = :id AND company_id = :cid
            ");
            $upd->execute([
                ':name' => $name,
                ':act' => $isActive,
                ':id' => $id,
                ':cid' => $activeCompanyId
            ]);
            setFlash('success', 'تم تعديل بند المصروف بنجاح.');
            header('Location: expense_categories.php');
            exit;
        }
    }
}

// Fetch categories with total expenses count & sum
$categories = $db->prepare("
    SELECT ec.*,
           (SELECT COUNT(*) FROM expenses WHERE category_id = ec.id) as expense_count,
           (SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE category_id = ec.id) as total_spent
    FROM expense_categories ec
    WHERE ec.company_id = :cid
    ORDER BY ec.id ASC
");
$categories->execute([':cid' => $activeCompanyId]);
$categoryList = $categories->fetchAll();

$pageTitle = 'بنود المصروفات';
require_once __DIR__ . '/includes/header.php';
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center space-x-2 space-x-reverse mb-1">
                <a href="expenses.php" class="text-xs font-bold text-brand-600 hover:underline">&larr; العودة للمصروفات</a>
            </div>
            <h1 class="text-2xl font-black text-slate-900">بنود وتصنيفات المصروفات</h1>
            <p class="text-xs text-slate-500 mt-1">تحديد بنود الصرف الخاصة بشركتك (إيجار، فواتير، بضاعة، نثريات، بوفيه...)</p>
        </div>
        <button onclick="openModal('add-cat-modal')" class="inline-flex items-center bg-brand-600 hover:bg-brand-700 text-white font-bold px-4 py-2.5 rounded-xl shadow-md transition text-xs sm:text-sm">
            <i class="fa-solid fa-plus-circle ml-2"></i>
            إضافة بند جديد
        </button>
    </div>

    <!-- Categories Grid -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <?php foreach ($categoryList as $cat): ?>
            <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs flex flex-col justify-between hover:border-slate-300 transition">
                <div>
                    <div class="flex items-start justify-between">
                        <div class="flex items-center space-x-3 space-x-reverse">
                            <div class="w-10 h-10 rounded-xl bg-slate-100 text-slate-700 flex items-center justify-center text-base">
                                <i class="fa-solid <?= htmlspecialchars($cat['icon'] ?: 'fa-folder') ?>"></i>
                            </div>
                            <div>
                                <h3 class="font-black text-slate-900 text-base"><?= htmlspecialchars($cat['name']) ?></h3>
                                <span class="text-[11px] text-slate-400"><?= $cat['expense_count'] ?> عملية صرف مسجلة</span>
                            </div>
                        </div>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?= (int)$cat['is_active'] === 1 ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-500' ?>">
                            <?= (int)$cat['is_active'] === 1 ? 'نشط' : 'معطل' ?>
                        </span>
                    </div>

                    <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between">
                        <span class="text-xs text-slate-500 font-bold">إجمالي المصروف:</span>
                        <span class="text-sm font-black text-rose-600"><?= formatMoney($cat['total_spent'], $currency) ?></span>
                    </div>
                </div>

                <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-end space-x-2 space-x-reverse">
                    <button onclick='editCategory(<?= json_encode($cat) ?>)' class="bg-slate-50 hover:bg-slate-100 text-slate-700 px-3 py-1.5 rounded-xl text-xs font-bold transition border border-slate-200">
                        <i class="fa-solid fa-pen ml-1"></i> تعديل
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ADD CATEGORY MODAL -->
<div id="add-cat-modal" class="modal-container fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4 overflow-y-auto">
    <div class="modal-backdrop fixed inset-0"></div>
    <div class="relative bg-white rounded-3xl shadow-2xl max-w-md w-full p-6 modal-animate-enter z-10 border border-slate-100">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
            <h3 class="font-black text-slate-800 text-lg">إضافة بند مصروف جديد</h3>
            <button onclick="closeModal('add-cat-modal')" class="text-slate-400 hover:text-slate-600 text-lg">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form action="expense_categories.php" method="POST" class="space-y-4">
            <?= getCSRFInput() ?>
            <input type="hidden" name="action" value="create_category">

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">اسم البند <span class="text-red-500">*</span></label>
                <input type="text" name="name" required placeholder="مثال: صيانة سيارات، دعاية وإعلان..." class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">أيقونة البند</label>
                <select name="icon" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                    <option value="fa-folder">مجلد عام (fa-folder)</option>
                    <option value="fa-building">مقر / عقار (fa-building)</option>
                    <option value="fa-bolt">فواتير وكهرباء (fa-bolt)</option>
                    <option value="fa-truck-fast">شحن وتوصيل (fa-truck-fast)</option>
                    <option value="fa-mug-hot">ضيافة وبوفيه (fa-mug-hot)</option>
                    <option value="fa-wrench">صيانة وأدوات (fa-wrench)</option>
                    <option value="fa-bullhorn">تسويق وإعلانات (fa-bullhorn)</option>
                    <option value="fa-boxes-stacked">بضاعة ومخزون (fa-boxes-stacked)</option>
                </select>
            </div>

            <div class="flex items-center space-x-2 space-x-reverse pt-2">
                <button type="submit" class="flex-1 bg-brand-600 hover:bg-brand-700 text-white font-bold py-2.5 px-4 rounded-xl shadow-md transition text-xs">
                    إضافة البند
                </button>
                <button type="button" onclick="closeModal('add-cat-modal')" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold py-2.5 px-4 rounded-xl transition text-xs">
                    إلغاء
                </button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT CATEGORY MODAL -->
<div id="edit-cat-modal" class="modal-container fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4 overflow-y-auto">
    <div class="modal-backdrop fixed inset-0"></div>
    <div class="relative bg-white rounded-3xl shadow-2xl max-w-md w-full p-6 modal-animate-enter z-10 border border-slate-100">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
            <h3 class="font-black text-slate-800 text-lg">تعديل بند المصروف</h3>
            <button onclick="closeModal('edit-cat-modal')" class="text-slate-400 hover:text-slate-600 text-lg">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form action="expense_categories.php" method="POST" class="space-y-4">
            <?= getCSRFInput() ?>
            <input type="hidden" name="action" value="update_category">
            <input type="hidden" id="edit_cat_id" name="category_id">

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">اسم البند</label>
                <input type="text" id="edit_cat_name" name="name" required class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
            </div>

            <div class="flex items-center space-x-2 space-x-reverse">
                <input type="checkbox" id="edit_cat_is_active" name="is_active" value="1" class="rounded text-brand-600 focus:ring-brand-500 w-4 h-4">
                <label for="edit_cat_is_active" class="text-xs font-bold text-slate-700 cursor-pointer">البند نشط ومتاح في قائمة المصروفات</label>
            </div>

            <div class="flex items-center space-x-2 space-x-reverse pt-2">
                <button type="submit" class="flex-1 bg-brand-600 hover:bg-brand-700 text-white font-bold py-2.5 px-4 rounded-xl shadow-md transition text-xs">
                    حفظ التعديلات
                </button>
                <button type="button" onclick="closeModal('edit-cat-modal')" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold py-2.5 px-4 rounded-xl transition text-xs">
                    إلغاء
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function editCategory(c) {
    document.getElementById('edit_cat_id').value = c.id;
    document.getElementById('edit_cat_name').value = c.name;
    document.getElementById('edit_cat_is_active').checked = parseInt(c.is_active) === 1;
    openModal('edit-cat-modal');
}
</script>

<?php include_once __DIR__ . '/includes/footer.php'; ?>
