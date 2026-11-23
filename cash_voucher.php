<?php
require_once __DIR__ . '/config/app.php';
requireLogin();

$type = trim($_GET['type'] ?? '');
$projectId = !empty($_GET['project_id']) ? (int)$_GET['project_id'] : null;
$param = $projectId ? '?project_id=' . $projectId : '';

if (in_array($type, ['receive', 'receipt', 'in', 'deposit', 'قبض'])) {
    header('Location: receipt_create.php' . $param);
    exit;
} elseif (in_array($type, ['pay', 'voucher', 'out', 'expense', 'صرف'])) {
    header('Location: voucher_create.php' . $param);
    exit;
}

$pageTitle = 'وصل استلام نقدي وسند مالي';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<div class="max-w-4xl mx-auto space-y-6 pb-24 lg:pb-12 pt-4">

    <!-- Header Banner -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm text-center space-y-2">
        <div class="w-16 h-16 rounded-3xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-3xl mx-auto mb-3 shadow-xs">
            <i class="fa-solid fa-receipt"></i>
        </div>
        <h1 class="text-2xl sm:text-3xl font-black text-slate-900">وصل استلام نقدي وسند مالي</h1>
        <p class="text-xs sm:text-sm text-slate-500 max-w-lg mx-auto">
            حدد اتجاه المعاملة المالية: هل تريد توثيق استلام مبلغ نقدية (قبض وارد) أم تسليم وصرف مبلغ نقدية (صرف صادر)؟
        </p>
    </div>

    <!-- 2 Main Interactive Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
        
        <!-- Option 1: استلام نقدية (سند قبض وارد) -->
        <a href="receipt_create.php<?= $param ?>" class="group block p-6 sm:p-7 rounded-3xl border-2 border-indigo-100 bg-gradient-to-br from-indigo-50/70 via-white to-white hover:border-indigo-600 hover:shadow-xl transition-all duration-200 active:scale-[0.99] flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-4">
                    <div class="w-14 h-14 rounded-2xl bg-indigo-600 text-white flex items-center justify-center text-2xl shadow-lg shadow-indigo-600/30 group-hover:scale-110 transition-transform">
                        <i class="fa-solid fa-arrow-down-left"></i>
                    </div>
                    <span class="text-xs font-black text-emerald-700 bg-emerald-100 px-3 py-1 rounded-full">
                        وارد للخزنة (+)
                    </span>
                </div>

                <h3 class="text-xl font-black text-slate-900 group-hover:text-indigo-600 transition mb-2">
                    استلام نقدية (سند قبض)
                </h3>
                <p class="text-xs sm:text-sm text-slate-600 leading-relaxed font-medium mb-4">
                    <strong>أنا استلمت فلوس من العميل:</strong> مقابل تقديم خدمة أو تحصيل دفعة مشروع عقاري، مع إصدار إيصال قبض رسمي معتمد وتوليد رابط توقيع إلكتروني للعميل فوراً.
                </p>
            </div>

            <div class="pt-4 border-t border-indigo-100/70 flex items-center justify-between text-indigo-600 font-black text-xs sm:text-sm">
                <span>متابعة لإصدار إيصال قبض</span>
                <i class="fa-solid fa-arrow-left group-hover:-translate-x-1.5 transition-transform"></i>
            </div>
        </a>

        <!-- Option 2: تسليم نقدية (سند صرف صادر) -->
        <a href="voucher_create.php<?= $param ?>" class="group block p-6 sm:p-7 rounded-3xl border-2 border-rose-100 bg-gradient-to-br from-rose-50/70 via-white to-white hover:border-rose-600 hover:shadow-xl transition-all duration-200 active:scale-[0.99] flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-4">
                    <div class="w-14 h-14 rounded-2xl bg-rose-600 text-white flex items-center justify-center text-2xl shadow-lg shadow-rose-600/30 group-hover:scale-110 transition-transform">
                        <i class="fa-solid fa-arrow-up-right"></i>
                    </div>
                    <span class="text-xs font-black text-rose-700 bg-rose-100 px-3 py-1 rounded-full">
                        صادر من الخزنة (-)
                    </span>
                </div>

                <h3 class="text-xl font-black text-slate-900 group-hover:text-rose-600 transition mb-2">
                    تسليم نقدية (سند صرف)
                </h3>
                <p class="text-xs sm:text-sm text-slate-600 leading-relaxed font-medium mb-4">
                    <strong>أنا طلعت أو سلّمت فلوس لعميل أو مورد:</strong> مقابل مستحقات أو مصروفات عقار أو مقاولات أو عهدة، مع استخراج سند صرف رسمي معتمد برابط توقيع إلكتروني للمستلم.
                </p>
            </div>

            <div class="pt-4 border-t border-rose-100/70 flex items-center justify-between text-rose-600 font-black text-xs sm:text-sm">
                <span>متابعة لإصدار سند صرف</span>
                <i class="fa-solid fa-arrow-left group-hover:-translate-x-1.5 transition-transform"></i>
            </div>
        </a>

    </div>

    <!-- Back to Dashboard -->
    <div class="text-center pt-2">
        <a href="dashboard.php" class="inline-flex items-center text-xs font-bold text-slate-500 hover:text-slate-800 transition">
            <i class="fa-solid fa-house ml-1.5"></i>
            العودة للوحة التحكم الرئيسية
        </a>
    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
