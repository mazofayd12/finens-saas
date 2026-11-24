<?php
/**
 * Social Media Center - Content Calendar
 * Monthly, Weekly, and Daily Visual Content Calendar
 */

require_once __DIR__ . '/config/app.php';
requireLogin();
requireModule('social_media');

require_once __DIR__ . '/includes/social/SocialSecurity.php';
require_once __DIR__ . '/includes/social/SocialMediaManager.php';

$currentUser = getCurrentUser();
$activeCompanyId = (int)($currentUser['active_company_id'] ?? $currentUser['company_id']);

$manager = new SocialMediaManager();

// Selected month and year
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');

if ($month < 1) { $month = 12; $year--; }
if ($month > 12) { $month = 1; $year++; }

// Compute dates for the month
$firstDayOfMonth = sprintf('%04d-%02d-01', $year, $month);
$daysInMonth = (int)date('t', strtotime($firstDayOfMonth));
$lastDayOfMonth = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);

// Previous and next month links
$prevMonth = $month - 1;
$prevYear = $year;
if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }

$nextMonth = $month + 1;
$nextYear = $year;
if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }

// Fetch posts for the month range
$posts = $manager->getCalendarPosts($activeCompanyId, $firstDayOfMonth, $lastDayOfMonth);

// Group posts by day number (1..31)
$postsByDay = [];
foreach ($posts as $p) {
    $dateStr = $p['scheduled_at'] ?: ($p['published_at'] ?: $p['created_at']);
    $dayNum = (int)date('j', strtotime($dateStr));
    $postsByDay[$dayNum][] = $p;
}

// Arabic Month Names
$arabicMonths = [
    1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل',
    5 => 'مايو', 6 => 'يونيو', 7 => 'يوليو', 8 => 'أغسطس',
    9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر'
];

$monthName = $arabicMonths[$month] ?? date('F', strtotime($firstDayOfMonth));

// Start day of week (0 = Sunday, 6 = Saturday)
$startDayOfWeek = (int)date('w', strtotime($firstDayOfMonth));

$pageTitle = "تقويم المحتوى والنشر - {$monthName} {$year}";
include_once __DIR__ . '/includes/header.php';
?>

<?php include_once __DIR__ . '/includes/social_nav.php'; ?>

<!-- Calendar Top Navigation Bar -->
<div class="bg-white p-4 sm:p-5 rounded-3xl border border-slate-200/80 shadow-xs mb-6 flex flex-col sm:flex-row items-center justify-between gap-4">
    <div class="flex items-center space-x-3 space-x-reverse">
        <div class="w-11 h-11 rounded-2xl bg-purple-50 text-purple-600 flex items-center justify-center text-xl font-bold shadow-xs">
            <i class="fa-solid fa-calendar-days"></i>
        </div>
        <div>
            <h2 class="text-base sm:text-lg font-black text-slate-900"><?= $monthName ?> <?= $year ?></h2>
            <p class="text-xs text-slate-500 font-medium">مواعيد المنشورات المجدولة والمحتوى المنشور لهذا الشهر</p>
        </div>
    </div>

    <!-- Navigation Arrows & Today Button -->
    <div class="flex items-center space-x-2 space-x-reverse">
        <a href="social_calendar.php?year=<?= $prevYear ?>&month=<?= $prevMonth ?>" class="p-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs transition" title="الشهر السابق">
            <i class="fa-solid fa-chevron-right ml-1"></i> الشهر السابق
        </a>
        <a href="social_calendar.php?year=<?= date('Y') ?>&month=<?= date('m') ?>" class="px-3 py-2.5 rounded-xl bg-purple-50 hover:bg-purple-100 text-purple-700 font-bold text-xs transition">
            اليوم الحالي
        </a>
        <a href="social_calendar.php?year=<?= $nextYear ?>&month=<?= $nextMonth ?>" class="p-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs transition" title="الشهر التالي">
            الشهر التالي <i class="fa-solid fa-chevron-left mr-1"></i>
        </a>
    </div>
</div>

<!-- Calendar Grid -->
<div class="bg-white rounded-3xl border border-slate-200/80 shadow-xs overflow-hidden">
    <!-- Days of Week Header -->
    <div class="grid grid-cols-7 bg-slate-50 border-b border-slate-200 text-center py-3 text-xs font-black text-slate-600">
        <div>الأحد</div>
        <div>الإثنين</div>
        <div>الثلاثاء</div>
        <div>الأربعاء</div>
        <div>الخميس</div>
        <div class="text-purple-600">الجمعة</div>
        <div>السبت</div>
    </div>

    <!-- Days Grid Cells -->
    <div class="grid grid-cols-7 auto-rows-fr divide-x divide-y divide-slate-200/60 divide-x-reverse">
        <?php
        // Empty cells before start of month
        for ($i = 0; $i < $startDayOfWeek; $i++):
        ?>
            <div class="bg-slate-50/40 min-h-[110px] p-2"></div>
        <?php endfor; ?>

        <?php
        $todayYear = (int)date('Y');
        $todayMonth = (int)date('m');
        $todayDay = (int)date('j');

        for ($d = 1; $d <= $daysInMonth; $d++):
            $isToday = ($year === $todayYear && $month === $todayMonth && $d === $todayDay);
            $dayPosts = $postsByDay[$d] ?? [];
            $cellDate = sprintf('%04d-%02d-%02d', $year, $month, $d);
        ?>
            <div class="min-h-[110px] p-2 flex flex-col justify-between group transition hover:bg-purple-50/20 relative <?= $isToday ? 'bg-purple-50/30' : 'bg-white' ?>">
                <!-- Day Number & Quick Add Button -->
                <div class="flex items-center justify-between mb-1.5">
                    <span class="w-6 h-6 rounded-full flex items-center justify-center text-xs font-black <?= $isToday ? 'bg-purple-600 text-white shadow-xs' : 'text-slate-700' ?>">
                        <?= $d ?>
                    </span>
                    <a href="social_post_create.php?scheduled_date=<?= $cellDate ?>" class="opacity-0 group-hover:opacity-100 text-[10px] font-bold text-purple-600 hover:text-purple-800 p-1 rounded transition" title="إنشاء منشور في هذا اليوم">
                        <i class="fa-solid fa-plus"></i>
                    </a>
                </div>

                <!-- Day Posts List -->
                <div class="space-y-1.5 flex-1 overflow-y-auto max-h-24 no-scrollbar">
                    <?php foreach ($dayPosts as $p): ?>
                        <?php
                        $statusClass = 'bg-slate-100 text-slate-700 border-slate-200';
                        if ($p['status'] === 'scheduled') $statusClass = 'bg-blue-50 text-blue-800 border-blue-200';
                        elseif ($p['status'] === 'published') $statusClass = 'bg-emerald-50 text-emerald-800 border-emerald-200';
                        elseif ($p['status'] === 'failed') $statusClass = 'bg-rose-50 text-rose-800 border-rose-200';
                        ?>
                        <div onclick="openCalendarPostModal(<?= htmlspecialchars(json_encode($p)) ?>)" class="p-1.5 rounded-xl border text-[10px] font-bold cursor-pointer transition hover:shadow-xs hover:scale-[1.01] <?= $statusClass ?>">
                            <div class="flex items-center justify-between mb-0.5">
                                <span class="font-mono text-[9px] opacity-80 dir-ltr">
                                    <?= date('h:i A', strtotime($p['scheduled_at'] ?: $p['created_at'])) ?>
                                </span>
                                <!-- Platform Badges -->
                                <div class="flex items-center gap-0.5">
                                    <?php foreach ($p['platforms'] as $pl): ?>
                                        <i class="fa-brands fa-<?= $pl['platform'] === 'facebook' ? 'facebook-f text-blue-600' : ($pl['platform'] === 'instagram' ? 'instagram text-pink-600' : ($pl['platform'] === 'tiktok' ? 'tiktok text-slate-900' : 'snapchat text-amber-500')) ?>"></i>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <p class="truncate font-medium leading-tight">
                                <?= htmlspecialchars($p['caption']) ?>
                            </p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endfor; ?>

        <?php
        // Trailing empty cells to complete the 7-column row
        $totalCells = $startDayOfWeek + $daysInMonth;
        $remainder = $totalCells % 7;
        if ($remainder > 0):
            for ($j = 0; $j < (7 - $remainder); $j++):
        ?>
            <div class="bg-slate-50/40 min-h-[110px] p-2"></div>
        <?php
            endfor;
        endif;
        ?>
    </div>
</div>

<!-- Modal: Post Details & Quick Reschedule -->
<div id="calendar-post-modal" class="modal-container fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4 overflow-y-auto">
    <div class="modal-backdrop fixed inset-0" onclick="closeModal('calendar-post-modal')"></div>
    <div class="relative bg-white rounded-3xl shadow-2xl max-w-lg w-full p-6 modal-animate-enter z-10 border border-slate-100">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
            <div class="flex items-center space-x-2 space-x-reverse">
                <div class="w-10 h-10 rounded-2xl bg-purple-50 text-purple-600 flex items-center justify-center text-lg font-bold">
                    <i class="fa-solid fa-newspaper"></i>
                </div>
                <div>
                    <h3 class="font-black text-slate-900 text-sm sm:text-base">تفاصيل المنشور</h3>
                    <p class="text-[11px] text-slate-500" id="modal_post_date">--</p>
                </div>
            </div>
            <button onclick="closeModal('calendar-post-modal')" class="text-slate-400 hover:text-slate-600 text-lg">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="space-y-4">
            <!-- Thumbnail if available -->
            <div id="modal_thumb_wrap" class="hidden aspect-video bg-slate-100 rounded-2xl overflow-hidden border border-slate-200">
                <img id="modal_post_thumb" src="" class="w-full h-full object-cover">
            </div>

            <!-- Caption -->
            <div class="p-3.5 bg-slate-50 rounded-2xl border border-slate-200/80 text-xs text-slate-800 leading-relaxed whitespace-pre-wrap" id="modal_post_caption">
                --
            </div>

            <!-- Status and Targeted Channels -->
            <div class="flex items-center justify-between p-3 bg-purple-50/50 rounded-2xl border border-purple-100 text-xs">
                <span class="font-bold text-slate-600">حالة المنشور:</span>
                <span id="modal_post_status_badge" class="font-bold px-2.5 py-0.5 rounded-full text-[10px]">--</span>
            </div>

            <!-- Reschedule Form -->
            <div class="p-4 bg-slate-50 rounded-2xl border border-slate-200 space-y-3">
                <label class="block text-xs font-bold text-slate-800">
                    <i class="fa-regular fa-clock ml-1 text-purple-600"></i> تعديل موعد الجدولة سريعاً:
                </label>
                <div class="grid grid-cols-2 gap-2">
                    <input type="date" id="resched_date" class="bg-white border border-slate-300 rounded-xl px-3 py-2 text-xs font-bold">
                    <input type="time" id="resched_time" class="bg-white border border-slate-300 rounded-xl px-3 py-2 text-xs font-bold">
                </div>
                <button type="button" onclick="submitQuickReschedule()" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 rounded-xl text-xs transition">
                    حفظ الموعد الجديد
                </button>
            </div>

            <!-- Modal Action Buttons -->
            <div class="flex items-center space-x-2 space-x-reverse pt-2">
                <a href="#" id="modal_edit_link" class="flex-1 bg-purple-600 hover:bg-purple-700 text-white font-bold py-2.5 px-4 rounded-xl text-xs text-center transition">
                    <i class="fa-solid fa-pen ml-1"></i> فتح المنشور في المحرر الكامل
                </a>
                <button type="button" onclick="closeModal('calendar-post-modal')" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold py-2.5 px-4 rounded-xl text-xs transition">
                    إغلاق
                </button>
            </div>
        </div>
    </div>
</div>

<script>
var currentModalPostId = null;
var csrfToken = "<?= generateCSRFToken() ?>";

function openCalendarPostModal(post) {
    currentModalPostId = post.id;
    document.getElementById('modal_post_caption').innerText = post.caption || '';
    
    var dateStr = post.scheduled_at || post.published_at || post.created_at;
    document.getElementById('modal_post_date').innerText = 'الموعد: ' + dateStr;

    // Thumbnail
    var thumbWrap = document.getElementById('modal_thumb_wrap');
    var thumbImg = document.getElementById('modal_post_thumb');
    if (post.thumbnail_path) {
        thumbImg.src = post.thumbnail_path;
        thumbWrap.classList.remove('hidden');
    } else {
        thumbWrap.classList.add('hidden');
    }

    // Status Badge
    var badge = document.getElementById('modal_post_status_badge');
    if (post.status === 'scheduled') {
        badge.className = 'font-bold px-2.5 py-0.5 rounded-full text-[10px] bg-blue-100 text-blue-800';
        badge.innerText = 'مجدول للنشر';
    } else if (post.status === 'published') {
        badge.className = 'font-bold px-2.5 py-0.5 rounded-full text-[10px] bg-emerald-100 text-emerald-800';
        badge.innerText = 'تم النشر بنجاح';
    } else if (post.status === 'failed') {
        badge.className = 'font-bold px-2.5 py-0.5 rounded-full text-[10px] bg-rose-100 text-rose-800';
        badge.innerText = 'فشل النشر';
    } else {
        badge.className = 'font-bold px-2.5 py-0.5 rounded-full text-[10px] bg-slate-200 text-slate-700';
        badge.innerText = 'مسودة';
    }

    // Prefill reschedule date/time
    if (post.scheduled_at) {
        var dParts = post.scheduled_at.split(' ');
        document.getElementById('resched_date').value = dParts[0] || '';
        document.getElementById('resched_time').value = (dParts[1] || '').substring(0, 5);
    }

    document.getElementById('modal_edit_link').href = 'social_post_create.php?edit=' + post.id;
    openModal('calendar-post-modal');
}

function submitQuickReschedule() {
    if (!currentModalPostId) return;
    var d = document.getElementById('resched_date').value;
    var t = document.getElementById('resched_time').value;

    if (!d || !t) {
        alert('يرجى تحديد التاريخ والوقت بدقة.');
        return;
    }

    var newSched = d + ' ' + t + ':00';
    var fd = new FormData();
    fd.append('action', 'reschedule_post');
    fd.append('csrf_token', csrfToken);
    fd.append('post_id', currentModalPostId);
    fd.append('scheduled_at', newSched);

    fetch('social_ajax.php', { method: 'POST', body: fd })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (data.success) {
            alert('تم تغيير موعد جدولة المنشور بنجاح!');
            window.location.reload();
        } else {
            alert('فشل إعادة الجدولة: ' + (data.error || 'خطأ غير معروف'));
        }
    });
}
</script>

<?php include_once __DIR__ . '/includes/footer.php'; ?>
