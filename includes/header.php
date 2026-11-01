<?php
require_once __DIR__ . '/../config/app.php';
requireLogin();

$currentUser = getCurrentUser();
$activeCompanyId = $currentUser['active_company_id'] ?? null;
$pageTitle = $pageTitle ?? 'لوحة التحكم';

// Fetch safe balance for active company if treasury module is accessible
$mainSafe = null;
if (!empty($activeCompanyId)) {
    $mainSafe = getCompanyMainSafe((int)$activeCompanyId);
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#ffffff">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="<?= htmlspecialchars(APP_SHORT_NAME) ?>">
    <link rel="manifest" href="manifest.json">
    <link rel="icon" type="image/png" sizes="192x192" href="assets/icons/icon-192x192.png">
    <link rel="apple-touch-icon" href="assets/icons/icon-192x192.png">

    <title><?= htmlspecialchars($pageTitle) ?> | <?= htmlspecialchars(APP_NAME) ?></title>

    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#eef2ff',
                            100: '#e0e7ff',
                            500: '#6366f1',
                            600: '#4f46e5',
                            700: '#4338ca',
                            800: '#3730a3',
                            900: '#312e81',
                        }
                    }
                }
            }
        }
    </script>

    <!-- FontAwesome 6 CDN -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen flex flex-col font-sans antialiased selection:bg-brand-500 selection:text-white">

    <!-- Mobile PWA Install Banner -->
    <div id="pwa-install-banner" class="hidden no-print bg-gradient-to-r from-brand-600 to-indigo-700 text-white px-4 py-2.5 shadow-md flex items-center justify-between z-50 text-xs sm:text-sm">
        <div class="flex items-center space-x-2 space-x-reverse">
            <i class="fa-solid fa-mobile-screen-button text-lg animate-bounce"></i>
            <div>
                <p class="font-bold">تثبيت التطبيق على هاتفك</p>
                <p class="text-[11px] opacity-90">وصول سريع وبدون إنترنت كـ تطبيق موبايل أصلي</p>
            </div>
        </div>
        <div class="flex items-center space-x-2 space-x-reverse">
            <button onclick="triggerPwaInstall()" class="bg-white text-brand-700 font-bold px-3 py-1 rounded-lg text-xs shadow hover:bg-slate-100 transition">
                تثبيت الآن
            </button>
            <button onclick="document.getElementById('pwa-install-banner').classList.add('hidden')" class="text-white/80 hover:text-white text-sm px-1">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
    </div>

    <!-- Layout Container -->
    <div class="flex-1 flex flex-col md:flex-row">
        <!-- Sidebar Navigation -->
        <?php include_once __DIR__ . '/sidebar.php'; ?>

        <!-- Main Content Area -->
        <div class="flex-1 flex flex-col min-w-0">
            <!-- Top Navbar -->
            <?php include_once __DIR__ . '/navbar.php'; ?>

            <!-- Page Body -->
            <main class="flex-1 p-4 sm:p-6 lg:p-8 max-w-7xl mx-auto w-full app-main-content">
                <!-- Flash Messages -->
                <?php $flashes = getFlashes(); if (!empty($flashes)): ?>
                    <div class="mb-5 space-y-2">
                        <?php foreach ($flashes as $flash): ?>
                            <div class="flash-alert flex items-center justify-between p-4 rounded-xl shadow-sm text-sm font-medium border <?= $flash['type'] === 'error' ? 'bg-red-50 text-red-800 border-red-200' : ($flash['type'] === 'warning' ? 'bg-amber-50 text-amber-800 border-amber-200' : 'bg-emerald-50 text-emerald-800 border-emerald-200') ?>">
                                <div class="flex items-center space-x-2 space-x-reverse">
                                    <i class="fa-solid <?= $flash['type'] === 'error' ? 'fa-circle-xmark text-red-500' : ($flash['type'] === 'warning' ? 'fa-triangle-exclamation text-amber-500' : 'fa-circle-check text-emerald-500') ?> text-lg"></i>
                                    <span><?= htmlspecialchars($flash['message']) ?></span>
                                </div>
                                <button onclick="this.parentElement.remove()" class="text-slate-400 hover:text-slate-600">
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
