<?php
require_once __DIR__ . '/config/app.php';
requireLogin();

// Audit log view permission: Super Admin or Company Admin
if (!isSuperAdmin() && !isCompanyAdmin()) {
    setFlash('error', 'ليس لديك صلاحية للوصول إلى سجل العمليات والرقابة.');
    header('Location: dashboard.php');
    exit;
}

$currentUser = getCurrentUser();
$db = getDBConnection();
ensureAuditLogsTable();

$activeCompanyId = (int)($currentUser['active_company_id'] ?? 0);
if ($activeCompanyId <= 0 && isSuperAdmin()) {
    $firstComp = $db->query("SELECT id FROM companies WHERE is_active = 1 LIMIT 1")->fetch();
    if ($firstComp) {
        $activeCompanyId = (int)$firstComp['id'];
    }
}

// Filters
$search = trim($_GET['search'] ?? '');
$filterUser = !empty($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$filterAction = trim($_GET['action_type'] ?? '');
$filterModule = trim($_GET['module'] ?? '');
$filterFrom = trim($_GET['date_from'] ?? '');
$filterTo = trim($_GET['date_to'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

// Base query
$where = ["company_id = :cid"];
$params = [':cid' => $activeCompanyId];

if (!empty($search)) {
    $where[] = "(summary LIKE :sq OR record_ref LIKE :sq OR user_name LIKE :sq OR ip_address LIKE :sq OR device_info LIKE :sq)";
    $params[':sq'] = "%{$search}%";
}
if ($filterUser > 0) {
    $where[] = "user_id = :uid";
    $params[':uid'] = $filterUser;
}
if (!empty($filterAction)) {
    $where[] = "action = :act";
    $params[':act'] = $filterAction;
}
if (!empty($filterModule)) {
    $where[] = "module = :mod";
    $params[':mod'] = $filterModule;
}
if (!empty($filterFrom)) {
    $where[] = "created_at >= :from";
    $params[':from'] = $filterFrom . ' 00:00:00';
}
if (!empty($filterTo)) {
    $where[] = "created_at <= :to";
    $params[':to'] = $filterTo . ' 23:59:59';
}

$whereSql = implode(' AND ', $where);

// Count total
$countStmt = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE {$whereSql}");
$countStmt->execute($params);
$totalRecords = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRecords / $perPage));

// Fetch Records
$dataStmt = $db->prepare("
    SELECT *
    FROM audit_logs
    WHERE {$whereSql}
    ORDER BY id DESC
    LIMIT :limit OFFSET :offset
");
foreach ($params as $k => $v) {
    $dataStmt->bindValue($k, $v);
}
$dataStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$dataStmt->execute();
$logs = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

// Stats Queries
$statsStmt = $db->prepare("
    SELECT 
        COUNT(*) as total_logs,
        SUM(CASE WHEN action = 'edit' THEN 1 ELSE 0 END) as total_edits,
        SUM(CASE WHEN date(created_at) = date('now') THEN 1 ELSE 0 END) as today_logs,
        COUNT(DISTINCT user_id) as active_users
    FROM audit_logs
    WHERE company_id = :cid
");
$statsStmt->execute([':cid' => $activeCompanyId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Users list for filter dropdown
$usersStmt = $db->prepare("SELECT id, name, role FROM users WHERE company_id = :cid ORDER BY name ASC");
$usersStmt->execute([':cid' => $activeCompanyId]);
$usersList = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'سجل العمليات والرقابة (Equals)';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 bg-white p-5 sm:p-6 rounded-3xl border border-slate-100 shadow-xs">
        <div class="flex items-center space-x-3.5 space-x-reverse">
            <div class="w-12 h-12 rounded-2xl bg-amber-500 text-white flex items-center justify-center text-2xl shadow-md shadow-amber-500/20">
                <i class="fa-solid fa-clock-rotate-left"></i>
            </div>
            <div>
                <div class="flex items-center gap-2">
                    <h1 class="text-xl sm:text-2xl font-black text-slate-900">سجل العمليات والرقابة والتدقيق (Equals)</h1>
                    <span class="px-2.5 py-0.5 rounded-full text-xs font-black bg-amber-100 text-amber-800 border border-amber-300">
                        Audit Trail
                    </span>
                </div>
                <p class="text-xs sm:text-sm text-slate-500 mt-0.5">تتبع شامل وموثق لجميع الإجراءات والتعديلات والتغييرات التي تمت داخل النظام بدقة الثانية وبيانات الجهاز والكمبيوتر.</p>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <button onclick="window.print()" class="inline-flex items-center bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold px-4 py-2.5 rounded-xl text-xs sm:text-sm transition shadow-2xs">
                <i class="fa-solid fa-print ml-2 text-slate-500"></i> طباعة السجل
            </button>
            <a href="audit_logs.php" class="inline-flex items-center bg-slate-900 hover:bg-slate-800 text-white font-bold px-4 py-2.5 rounded-xl text-xs sm:text-sm transition shadow-xs">
                <i class="fa-solid fa-arrows-rotate ml-2"></i> تحديث فوري
            </a>
        </div>
    </div>

    <!-- Quick Stats Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <!-- 1: Total Logs -->
        <div class="bg-white p-4 sm:p-5 rounded-2xl border border-slate-100 shadow-xs">
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-bold text-slate-500">إجمالي العمليات الموثقة</span>
                <span class="w-8 h-8 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center text-sm">
                    <i class="fa-solid fa-database"></i>
                </span>
            </div>
            <h3 class="text-2xl font-black text-slate-900 font-mono"><?= number_format((int)($stats['total_logs'] ?? 0)) ?></h3>
            <span class="text-[11px] text-slate-400 block mt-1">سجل تراكمي منذ بدء النظام</span>
        </div>

        <!-- 2: Total Edits -->
        <div class="bg-white p-4 sm:p-5 rounded-2xl border border-amber-200 bg-amber-50/20 shadow-xs">
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-bold text-amber-900">عمليات التعديل والتصحيح</span>
                <span class="w-8 h-8 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center text-sm">
                    <i class="fa-solid fa-pen-to-square"></i>
                </span>
            </div>
            <h3 class="text-2xl font-black text-amber-600 font-mono"><?= number_format((int)($stats['total_edits'] ?? 0)) ?></h3>
            <span class="text-[11px] text-amber-700 block mt-1">فواتير وسندات تم تصحيحها</span>
        </div>

        <!-- 3: Today's Actions -->
        <div class="bg-white p-4 sm:p-5 rounded-2xl border border-slate-100 shadow-xs">
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-bold text-slate-500">إجراءات اليوم</span>
                <span class="w-8 h-8 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-sm">
                    <i class="fa-solid fa-calendar-day"></i>
                </span>
            </div>
            <h3 class="text-2xl font-black text-emerald-600 font-mono"><?= number_format((int)($stats['today_logs'] ?? 0)) ?></h3>
            <span class="text-[11px] text-slate-400 block mt-1"><?= date('Y-m-d') ?></span>
        </div>

        <!-- 4: Active Users -->
        <div class="bg-white p-4 sm:p-5 rounded-2xl border border-slate-100 shadow-xs">
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-bold text-slate-500">المستخدمين الموثقين</span>
                <span class="w-8 h-8 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center text-sm">
                    <i class="fa-solid fa-users"></i>
                </span>
            </div>
            <h3 class="text-2xl font-black text-purple-600 font-mono"><?= number_format((int)($stats['active_users'] ?? 0)) ?></h3>
            <span class="text-[11px] text-slate-400 block mt-1">قاموا بعمليات بالنظام</span>
        </div>
    </div>

    <!-- Filter Form -->
    <div class="bg-white p-5 rounded-2xl border border-slate-100 shadow-xs">
        <form method="GET" action="audit_logs.php" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
            <!-- Search -->
            <div class="lg:col-span-2">
                <label class="block text-[11px] font-bold text-slate-600 mb-1">البحث بالبيان أو رقم السند أو الجهاز:</label>
                <div class="relative">
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="مثال: VCH-2026، سباكة، أحمد..." class="w-full bg-slate-50 border border-slate-300 rounded-xl pr-8 pl-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-amber-500 focus:outline-none">
                    <span class="absolute right-2.5 top-2.5 text-slate-400 text-xs">
                        <i class="fa-solid fa-magnifying-glass"></i>
                    </span>
                </div>
            </div>

            <!-- User -->
            <div>
                <label class="block text-[11px] font-bold text-slate-600 mb-1">المستخدم:</label>
                <select name="user_id" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-2.5 py-2 text-xs font-bold focus:bg-white focus:ring-2 focus:ring-amber-500 focus:outline-none">
                    <option value="">جميع المستخدمين</option>
                    <?php foreach ($usersList as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= $filterUser === (int)$u['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($u['name']) ?> (<?= htmlspecialchars($u['role']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Action -->
            <div>
                <label class="block text-[11px] font-bold text-slate-600 mb-1">نوع الإجراء:</label>
                <select name="action_type" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-2.5 py-2 text-xs font-bold focus:bg-white focus:ring-2 focus:ring-amber-500 focus:outline-none">
                    <option value="">كل الإجراءات</option>
                    <option value="edit" <?= $filterAction === 'edit' ? 'selected' : '' ?>>تعديل وتصحيح (Edit)</option>
                    <option value="create" <?= $filterAction === 'create' ? 'selected' : '' ?>>إضافة / إصدار (Create)</option>
                    <option value="delete" <?= $filterAction === 'delete' ? 'selected' : '' ?>>حذف (Delete)</option>
                    <option value="approve" <?= $filterAction === 'approve' ? 'selected' : '' ?>>اعتماد (Approve)</option>
                    <option value="reject" <?= $filterAction === 'reject' ? 'selected' : '' ?>>رفض (Reject)</option>
                </select>
            </div>

            <!-- Date From -->
            <div>
                <label class="block text-[11px] font-bold text-slate-600 mb-1">من تاريخ:</label>
                <input type="date" name="date_from" value="<?= htmlspecialchars($filterFrom) ?>" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-2 py-1.5 text-xs focus:bg-white focus:ring-2 focus:ring-amber-500 focus:outline-none">
            </div>

            <!-- Date To -->
            <div>
                <label class="block text-[11px] font-bold text-slate-600 mb-1">إلى تاريخ:</label>
                <input type="date" name="date_to" value="<?= htmlspecialchars($filterTo) ?>" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-2 py-1.5 text-xs focus:bg-white focus:ring-2 focus:ring-amber-500 focus:outline-none">
            </div>

            <!-- Submit buttons -->
            <div class="lg:col-span-6 flex items-center justify-end gap-2 pt-1 border-t border-slate-100">
                <a href="audit_logs.php" class="px-4 py-2 rounded-xl text-xs font-bold text-slate-600 hover:bg-slate-100 transition">
                    إعادة ضبط
                </a>
                <button type="submit" class="bg-amber-600 hover:bg-amber-700 text-white font-bold px-6 py-2 rounded-xl text-xs transition shadow-xs flex items-center">
                    <i class="fa-solid fa-filter ml-1.5"></i> تطبيق الفلتر
                </button>
            </div>
        </form>
    </div>

    <!-- Logs Table -->
    <div class="bg-white rounded-3xl border border-slate-100 shadow-xs overflow-hidden">
        <div class="p-4 sm:p-5 border-b border-slate-100 flex items-center justify-between">
            <div class="flex items-center space-x-2 space-x-reverse">
                <span class="w-2.5 h-2.5 rounded-full bg-amber-500 animate-pulse"></span>
                <h3 class="font-black text-slate-900 text-base">سجل العمليات المباشر (<?= number_format($totalRecords) ?> عملية)</h3>
            </div>
            <span class="text-xs text-slate-400 font-bold">صفحة <?= $page ?> من <?= $totalPages ?></span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-right border-collapse text-xs">
                <thead>
                    <tr class="bg-slate-50 text-slate-600 border-b border-slate-200">
                        <th class="p-3.5 font-bold whitespace-nowrap">الوقت الدقيق</th>
                        <th class="p-3.5 font-bold whitespace-nowrap">المستخدم</th>
                        <th class="p-3.5 font-bold whitespace-nowrap">الكمبيوتر والجهاز المستخدم</th>
                        <th class="p-3.5 font-bold whitespace-nowrap">الإجراء</th>
                        <th class="p-3.5 font-bold whitespace-nowrap">المرجع</th>
                        <th class="p-3.5 font-bold">تفاصيل ما حدث</th>
                        <th class="p-3.5 font-bold text-center whitespace-nowrap">فروقات القيم (Diff)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="7" class="p-10 text-center text-slate-400">
                                <i class="fa-solid fa-magnifying-glass text-3xl mb-2 text-slate-300 block"></i>
                                لا توجد سجلات مطابقة لمعايير البحث في هذه الفترة.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <?php
                            $actionBadge = [
                                'edit' => ['bg' => 'bg-amber-100', 'text' => 'text-amber-900', 'border' => 'border-amber-300', 'icon' => 'fa-pen-to-square', 'label' => 'تعديل وتصحيح'],
                                'create' => ['bg' => 'bg-emerald-100', 'text' => 'text-emerald-900', 'border' => 'border-emerald-300', 'icon' => 'fa-plus', 'label' => 'إضافة جديدة'],
                                'delete' => ['bg' => 'bg-rose-100', 'text' => 'text-rose-900', 'border' => 'border-rose-300', 'icon' => 'fa-trash-can', 'label' => 'حذف'],
                                'approve' => ['bg' => 'bg-blue-100', 'text' => 'text-blue-900', 'border' => 'border-blue-300', 'icon' => 'fa-check', 'label' => 'اعتماد'],
                                'reject' => ['bg' => 'bg-slate-100', 'text' => 'text-slate-800', 'border' => 'border-slate-300', 'icon' => 'fa-ban', 'label' => 'رفض'],
                            ][$log['action']] ?? ['bg' => 'bg-slate-100', 'text' => 'text-slate-800', 'border' => 'border-slate-300', 'icon' => 'fa-circle-dot', 'label' => $log['action']];

                            $hasDiff = (!empty($log['old_values']) && $log['old_values'] !== 'null') || (!empty($log['new_values']) && $log['new_values'] !== 'null');
                            $timeFormatted = date('H:i:s', strtotime($log['created_at']));
                            $dateFormatted = date('Y-m-d', strtotime($log['created_at']));
                            ?>
                            <tr class="hover:bg-slate-50/80 transition">
                                <!-- Exact Time -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <span class="font-mono font-bold text-slate-900 block"><?= $timeFormatted ?></span>
                                    <span class="text-[10px] text-slate-400 font-mono block"><?= $dateFormatted ?></span>
                                </td>

                                <!-- User -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <div class="flex items-center space-x-2 space-x-reverse">
                                        <div class="w-7 h-7 rounded-full bg-slate-200 text-slate-700 flex items-center justify-center font-bold text-[11px]">
                                            <i class="fa-solid fa-user"></i>
                                        </div>
                                        <div>
                                            <span class="font-bold text-slate-800 block text-xs"><?= htmlspecialchars($log['user_name'] ?: 'مستخدم') ?></span>
                                            <span class="text-[10px] text-slate-400 block"><?= htmlspecialchars($log['user_role'] ?: 'عضو') ?></span>
                                        </div>
                                    </div>
                                </td>

                                <!-- Computer & Device Info -->
                                <td class="p-3.5">
                                    <div class="flex flex-col">
                                        <span class="font-bold text-slate-800 flex items-center gap-1">
                                            <i class="fa-solid fa-laptop text-slate-400 text-[11px]"></i>
                                            <?= htmlspecialchars($log['device_info'] ?: 'غير محدد') ?>
                                        </span>
                                        <span class="font-mono text-[10px] text-slate-400 mt-0.5">
                                            IP: <strong class="text-slate-600"><?= htmlspecialchars($log['ip_address'] ?: '127.0.0.1') ?></strong>
                                        </span>
                                    </div>
                                </td>

                                <!-- Action Badge -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-black <?= $actionBadge['bg'] ?> <?= $actionBadge['text'] ?> border <?= $actionBadge['border'] ?>">
                                        <i class="fa-solid <?= $actionBadge['icon'] ?> ml-1 text-[9px]"></i>
                                        <?= $actionBadge['label'] ?>
                                    </span>
                                    <span class="text-[10px] text-slate-400 block mt-0.5 font-bold">
                                        <?= $log['module'] === 'expenses' ? 'المصروفات والسندات' : htmlspecialchars($log['module']) ?>
                                    </span>
                                </td>

                                <!-- Record Ref -->
                                <td class="p-3.5 whitespace-nowrap font-mono font-bold text-indigo-700">
                                    <?php if ($log['module'] === 'expenses' && $log['record_id']): ?>
                                        <a href="project_voucher_print.php?id=<?= $log['record_id'] ?>" target="_blank" class="hover:underline flex items-center gap-1" title="فتح وطباعة السند">
                                            <i class="fa-solid fa-file-invoice text-indigo-400"></i>
                                            <?= htmlspecialchars($log['record_ref'] ?: ('VCH-' . $log['record_id'])) ?>
                                        </a>
                                    <?php else: ?>
                                        <?= htmlspecialchars($log['record_ref'] ?: '—') ?>
                                    <?php endif; ?>
                                </td>

                                <!-- Summary -->
                                <td class="p-3.5 text-slate-800 max-w-md">
                                    <p class="leading-relaxed"><?= htmlspecialchars($log['summary']) ?></p>
                                </td>

                                <!-- Diff View Button -->
                                <td class="p-3.5 whitespace-nowrap text-center">
                                    <?php if ($hasDiff): ?>
                                        <button type="button" 
                                                onclick='openAuditDiffModal(<?= json_encode([
                                                    'id' => $log['id'],
                                                    'ref' => $log['record_ref'],
                                                    'user' => $log['user_name'],
                                                    'time' => $timeFormatted . ' ' . $dateFormatted,
                                                    'device' => $log['device_info'],
                                                    'ip' => $log['ip_address'],
                                                    'summary' => $log['summary'],
                                                    'old' => json_decode($log['old_values'], true),
                                                    'new' => json_decode($log['new_values'], true)
                                                ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>)'
                                                class="inline-flex items-center px-3 py-1.5 rounded-xl text-xs font-bold bg-amber-50 hover:bg-amber-100 text-amber-800 border border-amber-200 transition shadow-2xs">
                                            <i class="fa-solid fa-code-compare ml-1 text-amber-600"></i> مقارنة قبل وبعد
                                        </button>
                                    <?php else: ?>
                                        <span class="text-slate-300 text-[11px]">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="p-4 border-t border-slate-100 flex items-center justify-between">
                <div class="text-xs text-slate-500 font-bold">
                    عرض <?= $offset + 1 ?> إلى <?= min($totalRecords, $offset + $perPage) ?> من إجمالي <?= number_format($totalRecords) ?> عملية
                </div>
                <div class="flex items-center space-x-1 space-x-reverse">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&user_id=<?= $filterUser ?>&action_type=<?= urlencode($filterAction) ?>&module=<?= urlencode($filterModule) ?>&date_from=<?= urlencode($filterFrom) ?>&date_to=<?= urlencode($filterTo) ?>" class="px-3 py-1.5 rounded-lg text-xs font-bold bg-slate-100 hover:bg-slate-200 text-slate-700 transition">
                            <i class="fa-solid fa-chevron-right ml-1"></i> السابق
                        </a>
                    <?php endif; ?>

                    <span class="px-3 py-1.5 text-xs font-bold bg-amber-600 text-white rounded-lg">
                        <?= $page ?> / <?= $totalPages ?>
                    </span>

                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&user_id=<?= $filterUser ?>&action_type=<?= urlencode($filterAction) ?>&module=<?= urlencode($filterModule) ?>&date_from=<?= urlencode($filterFrom) ?>&date_to=<?= urlencode($filterTo) ?>" class="px-3 py-1.5 rounded-lg text-xs font-bold bg-slate-100 hover:bg-slate-200 text-slate-700 transition">
                            التالي <i class="fa-solid fa-chevron-left mr-1"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal: Diff Viewer (Equals Comparison) -->
<div id="audit-diff-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4 overflow-y-auto">
    <div class="fixed inset-0" onclick="closeAuditDiffModal()"></div>
    <div class="relative bg-white rounded-3xl shadow-2xl max-w-2xl w-full p-6 sm:p-7 z-10 border border-slate-100 modal-animate-enter my-8">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
            <div class="flex items-center space-x-3 space-x-reverse">
                <div class="w-10 h-10 rounded-2xl bg-amber-500 text-white flex items-center justify-center text-lg font-bold shadow-md shadow-amber-500/20">
                    <i class="fa-solid fa-code-compare"></i>
                </div>
                <div>
                    <h3 class="font-black text-slate-900 text-lg">تفاصيل الفروقات قبل وبعد التعديل (Equals Diff)</h3>
                    <p id="diff_modal_subtitle" class="text-xs text-slate-500 font-mono mt-0.5">سند: VCH-XXXX</p>
                </div>
            </div>
            <button onclick="closeAuditDiffModal()" class="text-slate-400 hover:text-slate-600 text-lg p-1.5">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- Meta strip -->
        <div class="p-3 bg-slate-50 rounded-2xl border border-slate-200 text-xs mb-4 grid grid-cols-2 gap-2 text-slate-700">
            <div>
                <span class="text-slate-400 block text-[10px]">المستخدم المنفذ:</span>
                <strong id="diff_modal_user" class="text-slate-900 font-bold block">—</strong>
            </div>
            <div>
                <span class="text-slate-400 block text-[10px]">التوقيت:</span>
                <strong id="diff_modal_time" class="text-slate-900 font-mono block">—</strong>
            </div>
            <div class="col-span-2">
                <span class="text-slate-400 block text-[10px]">الجهاز والـ IP:</span>
                <strong id="diff_modal_device" class="text-slate-900 font-bold block">—</strong>
            </div>
        </div>

        <!-- Summary alert -->
        <div id="diff_modal_summary_box" class="p-3 bg-amber-50/70 border border-amber-200 rounded-xl text-xs text-amber-900 font-bold mb-4">
        </div>

        <!-- Diff Table -->
        <div class="overflow-x-auto rounded-2xl border border-slate-200 mb-4">
            <table class="w-full text-right text-xs">
                <thead>
                    <tr class="bg-slate-100 text-slate-700 font-bold border-b border-slate-200">
                        <th class="p-3">الحقل / البند</th>
                        <th class="p-3 bg-rose-50 text-rose-900">القيمة السابقة (قبل التعديل)</th>
                        <th class="p-3 bg-emerald-50 text-emerald-900">القيمة الجديدة (بعد التعديل)</th>
                    </tr>
                </thead>
                <tbody id="diff_modal_table_body" class="divide-y divide-slate-100">
                </tbody>
            </table>
        </div>

        <div class="text-left pt-2">
            <button onclick="closeAuditDiffModal()" class="bg-slate-900 hover:bg-slate-800 text-white font-bold px-6 py-2 rounded-xl text-xs transition">
                إغلاق
            </button>
        </div>
    </div>
</div>

<script>
const FIELD_NAMES_AR = {
    'amount': 'المبلغ الإجمالي',
    'title': 'بيان / سبب الصرف',
    'category_name': 'التصنيف / البند',
    'expense_date': 'تاريخ الصرف',
    'currency': 'العملة',
    'tax_amount': 'مبلغ الضريبة',
    'beneficiary_name': 'المستفيد',
    'beneficiary_id_number': 'رقم هوية المستفيد',
    'beneficiary_phone': 'رقم هاتف المستفيد',
    'payment_method': 'طريقة الدفع',
    'invoice_attachment': 'مرفق الفاتورة',
    'edit_reason': 'سبب التعديل'
};

function openAuditDiffModal(data) {
    document.getElementById('diff_modal_subtitle').innerText = 'المرجع: ' + (data.ref || '—');
    document.getElementById('diff_modal_user').innerText = data.user || 'مستخدم النظام';
    document.getElementById('diff_modal_time').innerText = data.time || '—';
    document.getElementById('diff_modal_device').innerText = (data.device || 'غير محدد') + ' (IP: ' + (data.ip || '—') + ')';
    document.getElementById('diff_modal_summary_box').innerText = data.summary || 'تم تسجيل الإجراء بنجاح';

    const tbody = document.getElementById('diff_modal_table_body');
    tbody.innerHTML = '';

    const oldVals = data.old || {};
    const newVals = data.new || {};
    const allKeys = Array.from(new Set([...Object.keys(oldVals), ...Object.keys(newVals)]));

    if (allKeys.length === 0) {
        tbody.innerHTML = '<tr><td colspan="3" class="p-4 text-center text-slate-400">لا توجد تفاصيل مقارنة مسجلة.</td></tr>';
    } else {
        allKeys.forEach(k => {
            const vOld = oldVals[k] !== undefined && oldVals[k] !== null ? String(oldVals[k]) : '—';
            const vNew = newVals[k] !== undefined && newVals[k] !== null ? String(newVals[k]) : '—';
            const isChanged = vOld !== vNew;
            const fieldLabel = FIELD_NAMES_AR[k] || k;

            const tr = document.createElement('tr');
            if (isChanged) {
                tr.className = 'bg-amber-50/30 font-bold';
            }
            tr.innerHTML = `
                <td class="p-3 text-slate-800 font-bold whitespace-nowrap">
                    ${isChanged ? '<span class="w-1.5 h-1.5 rounded-full bg-amber-500 inline-block ml-1"></span>' : ''}
                    ${fieldLabel}
                </td>
                <td class="p-3 bg-rose-50/50 text-rose-800 font-mono">${vOld}</td>
                <td class="p-3 bg-emerald-50/50 text-emerald-800 font-mono">${vNew}</td>
            `;
            tbody.appendChild(tr);
        });
    }

    const modal = document.getElementById('audit-diff-modal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.body.style.overflow = 'hidden';
}

function closeAuditDiffModal() {
    const modal = document.getElementById('audit-diff-modal');
    modal.classList.remove('flex');
    modal.classList.add('hidden');
    document.body.style.overflow = 'auto';
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
