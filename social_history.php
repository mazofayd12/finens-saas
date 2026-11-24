<?php
/**
 * Social Media Center - Publishing History & Audit Logs
 */

require_once __DIR__ . '/config/app.php';
requireLogin();
requireModule('social_media');

require_once __DIR__ . '/includes/social/SocialSecurity.php';
require_once __DIR__ . '/includes/social/SocialMediaManager.php';

$currentUser = getCurrentUser();
$activeCompanyId = (int)($currentUser['active_company_id'] ?? $currentUser['company_id']);

$manager = new SocialMediaManager();

// Fetch logs
$logs = $manager->getPublishingLogs($activeCompanyId, 100);

$pageTitle = 'إدارة السوشيال ميديا - سجل العمليات والنشر';
include_once __DIR__ . '/includes/header.php';
?>

<?php include_once __DIR__ . '/includes/social_nav.php'; ?>

<!-- Top Info Bar -->
<div class="bg-white p-4 sm:p-5 rounded-3xl border border-slate-200/80 shadow-xs mb-6 flex items-center justify-between">
    <div class="flex items-center space-x-3 space-x-reverse">
        <div class="w-11 h-11 rounded-2xl bg-slate-100 text-slate-700 flex items-center justify-center text-xl font-bold">
            <i class="fa-solid fa-clock-rotate-left"></i>
        </div>
        <div>
            <h2 class="text-base sm:text-lg font-black text-slate-900">سجل عمليات النشر والتدقيق (Equals)</h2>
            <p class="text-xs text-slate-500 font-medium">سجل زمني لجميع طلبات النشر، الردود، وتفويض الحسابات</p>
        </div>
    </div>
    <span class="text-xs font-bold bg-slate-100 text-slate-700 px-3 py-1.5 rounded-xl">
        إجمالي العمليات: <?= count($logs) ?>
    </span>
</div>

<!-- Logs Table Card -->
<div class="bg-white rounded-3xl border border-slate-200/80 shadow-xs overflow-hidden">
    <?php if (empty($logs)): ?>
        <div class="py-12 text-center text-slate-400 space-y-2">
            <i class="fa-solid fa-list-check text-4xl opacity-50 block"></i>
            <p class="text-xs font-bold text-slate-600">لا توجد أي سجلات أو عمليات مسجلة بعد</p>
        </div>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="bg-slate-50 text-slate-600 font-black border-b border-slate-200 uppercase text-[11px]">
                    <tr>
                        <th class="p-4 whitespace-nowrap">التوقيت والتاريخ</th>
                        <th class="p-4 whitespace-nowrap">المنصة</th>
                        <th class="p-4 whitespace-nowrap">الحساب المستهدف</th>
                        <th class="p-4 whitespace-nowrap">الإجراء</th>
                        <th class="p-4 whitespace-nowrap">كود الاستجابة</th>
                        <th class="p-4 whitespace-nowrap">النتيجة والملاحظات</th>
                        <th class="p-4 whitespace-nowrap text-center">التفاصيل</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($logs as $log): ?>
                        <?php
                        $isSuccess = ($log['status_code'] >= 200 && $log['status_code'] < 300) || $log['action'] === 'publish_success';
                        ?>
                        <tr class="hover:bg-slate-50/60 transition">
                            <!-- Timestamp -->
                            <td class="p-4 whitespace-nowrap font-mono text-[11px] text-slate-500 dir-ltr text-right">
                                <?= date('Y/m/d h:i:s A', strtotime($log['created_at'])) ?>
                            </td>

                            <!-- Platform -->
                            <td class="p-4 whitespace-nowrap font-bold">
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-xl text-[10px] <?= $log['platform'] === 'facebook' ? 'bg-blue-50 text-blue-700' : ($log['platform'] === 'instagram' ? 'bg-pink-50 text-pink-700' : ($log['platform'] === 'tiktok' ? 'bg-slate-900 text-white' : 'bg-amber-50 text-amber-800')) ?>">
                                    <i class="fa-brands fa-<?= $log['platform'] === 'facebook' ? 'facebook-f' : ($log['platform'] === 'instagram' ? 'instagram' : ($log['platform'] === 'tiktok' ? 'tiktok' : 'snapchat')) ?>"></i>
                                    <span><?= ucfirst($log['platform']) ?></span>
                                </span>
                            </td>

                            <!-- Account Name -->
                            <td class="p-4 whitespace-nowrap font-bold text-slate-800">
                                <?= htmlspecialchars($log['account_name'] ?: 'حساب المنصة') ?>
                            </td>

                            <!-- Action -->
                            <td class="p-4 whitespace-nowrap font-bold text-slate-700">
                                <?php if ($log['action'] === 'publish_success'): ?>
                                    <span class="text-emerald-600"><i class="fa-solid fa-circle-check ml-1"></i> نشر ناجح</span>
                                <?php elseif ($log['action'] === 'publish_failure'): ?>
                                    <span class="text-rose-600"><i class="fa-solid fa-circle-xmark ml-1"></i> فشل النشر</span>
                                <?php elseif ($log['action'] === 'account_connected'): ?>
                                    <span class="text-purple-600"><i class="fa-solid fa-link ml-1"></i> ربط حساب</span>
                                <?php elseif ($log['action'] === 'account_disconnected'): ?>
                                    <span class="text-slate-500"><i class="fa-solid fa-link-slash ml-1"></i> فصل حساب</span>
                                <?php else: ?>
                                    <span><?= htmlspecialchars($log['action']) ?></span>
                                <?php endif; ?>
                            </td>

                            <!-- Status Code -->
                            <td class="p-4 whitespace-nowrap font-mono font-bold">
                                <span class="px-2 py-0.5 rounded text-[10px] <?= $isSuccess ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800' ?>">
                                    <?= $log['status_code'] ?: '--' ?>
                                </span>
                            </td>

                            <!-- Error / Message -->
                            <td class="p-4 max-w-xs truncate text-slate-600">
                                <?php if (!empty($log['error_message'])): ?>
                                    <span class="text-rose-600 font-bold truncate block" title="<?= htmlspecialchars($log['error_message']) ?>">
                                        <?= htmlspecialchars($log['error_message']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-emerald-700 font-medium">اكتمل الإجراء بنجاح</span>
                                <?php endif; ?>
                            </td>

                            <!-- Details Modal Trigger -->
                            <td class="p-4 whitespace-nowrap text-center">
                                <?php if (!empty($log['response_payload'])): ?>
                                    <button type="button" onclick="viewLogPayload(<?= htmlspecialchars(json_encode($log['response_payload'])) ?>)" class="text-slate-500 hover:text-purple-600 font-bold text-xs p-1.5 rounded-lg hover:bg-slate-100 transition" title="عرض البيانات التقنية">
                                        <i class="fa-solid fa-code"></i>
                                    </button>
                                <?php else: ?>
                                    <span class="text-slate-300">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Modal: View Log JSON Payload -->
<div id="log-payload-modal" class="modal-container fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4 overflow-y-auto">
    <div class="modal-backdrop fixed inset-0" onclick="closeModal('log-payload-modal')"></div>
    <div class="relative bg-white rounded-3xl shadow-2xl max-w-lg w-full p-6 modal-animate-enter z-10 border border-slate-100">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
            <h3 class="font-black text-slate-900 text-sm">بيانات الاستجابة التقنية (Sanitized API Response)</h3>
            <button onclick="closeModal('log-payload-modal')" class="text-slate-400 hover:text-slate-600 text-lg">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <pre id="log_payload_content" class="bg-slate-950 text-emerald-400 p-4 rounded-2xl text-[11px] font-mono overflow-x-auto max-h-96 dir-ltr"></pre>
    </div>
</div>

<script>
function viewLogPayload(raw) {
    var formatted = raw;
    try {
        var parsed = JSON.parse(raw);
        formatted = JSON.stringify(parsed, null, 2);
    } catch(e) {}
    document.getElementById('log_payload_content').innerText = formatted;
    openModal('log-payload-modal');
}
</script>

<?php include_once __DIR__ . '/includes/footer.php'; ?>
