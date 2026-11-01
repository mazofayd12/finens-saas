<?php
require_once __DIR__ . '/config/app.php';

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        $error = 'يرجى إدخال اسم المستخدم وكلمة المرور';
    } else {
        $result = loginUser($username, $password);
        if ($result === true) {
            header('Location: dashboard.php');
            exit;
        } else {
            $error = is_string($result) ? $result : 'فشل تسجيل الدخول';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#4f46e5">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <title>تسجيل الدخول | <?= htmlspecialchars(APP_NAME) ?></title>
    
    <link rel="manifest" href="manifest.json">
    <link rel="icon" type="image/png" sizes="192x192" href="assets/icons/icon-192x192.png">
    <link rel="apple-touch-icon" href="assets/icons/icon-192x192.png">
    
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            500: '#6366f1',
                            600: '#4f46e5',
                            700: '#4338ca',
                            800: '#3730a3'
                        }
                    }
                }
            }
        }
    </script>
    <!-- FontAwesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="bg-slate-900 text-slate-100 min-h-screen flex items-center justify-center p-4">

    <div class="w-full max-w-md">
        <!-- Logo & Header -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-gradient-to-tr from-brand-600 to-indigo-400 text-white shadow-2xl mb-4">
                <i class="fa-solid fa-coins text-3xl"></i>
            </div>
            <h1 class="text-2xl font-black text-white tracking-wide">منظومة فينانس المالية</h1>
            <p class="text-xs text-slate-400 mt-1 font-medium">لوحة تحكم الخزينة والمصروفات متعددة الشركات (SaaS)</p>
        </div>

        <!-- Login Card -->
        <div class="bg-slate-800/90 border border-slate-700/80 rounded-3xl p-6 sm:p-8 shadow-2xl backdrop-blur-md">
            <?php if (!empty($error)): ?>
                <div class="mb-5 bg-red-500/10 border border-red-500/30 text-red-300 text-xs font-bold p-3.5 rounded-xl flex items-center space-x-2 space-x-reverse">
                    <i class="fa-solid fa-triangle-exclamation text-base"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php" class="space-y-4">
                <div>
                    <label class="block text-xs font-bold text-slate-300 mb-1.5">اسم المستخدم</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 right-0 flex items-center pr-3.5 text-slate-400 pointer-events-none">
                            <i class="fa-solid fa-user text-sm"></i>
                        </span>
                        <input type="text" id="username" name="username" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" placeholder="أدخل اسم المستخدم" class="w-full bg-slate-900/80 border border-slate-700 rounded-xl pr-10 pl-3 py-2.5 text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-300 mb-1.5">كلمة المرور</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 right-0 flex items-center pr-3.5 text-slate-400 pointer-events-none">
                            <i class="fa-solid fa-lock text-sm"></i>
                        </span>
                        <input type="password" id="password" name="password" required placeholder="••••••••" class="w-full bg-slate-900/80 border border-slate-700 rounded-xl pr-10 pl-3 py-2.5 text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition">
                    </div>
                </div>

                <button type="submit" class="w-full mt-2 bg-gradient-to-r from-brand-600 to-indigo-600 hover:from-brand-700 hover:to-indigo-700 text-white font-black py-3 px-4 rounded-xl shadow-lg shadow-indigo-600/30 hover:shadow-indigo-600/50 transition transform active:scale-98 text-sm">
                    دخول إلى لوحة التحكم
                </button>
            </form>
        </div>

        <!-- PWA install hint on login screen -->
        <div class="text-center mt-6">
            <button id="pwa-install-btn" onclick="triggerPwaInstall()" class="hidden inline-flex items-center text-xs font-semibold text-slate-400 hover:text-white bg-slate-800/80 px-4 py-2 rounded-full border border-slate-700 transition">
                <i class="fa-solid fa-download ml-2 text-brand-400"></i>
                تثبيت التطبيق على الشاشة الرئيسية للهاتف
            </button>
        </div>
    </div>

    <script src="assets/js/app.js"></script>
</body>
</html>
