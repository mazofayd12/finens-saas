<?php
// BUILD Real Estate Development - Official Landing Page (Under Construction / Coming Soon)
$pageTitle = "بيلد للتطوير العقاري | BUILD Real Estate Development";
$companyNameAr = "بيلد للتطوير العقاري";
$companyNameEn = "BUILD Real Estate Development";
$phone1 = "+966 549 831 030";
$phone1Raw = "966549831030";
$phone2 = "+966 555 148 076";
$phone2Raw = "966555148076";
$email = "contact@buildesa.com";
$crNumber = "7010389802";
$certNumber = "CER-2609-1024";
$gmName = "محمد بن خالد المشيقح";
$gmTitle = "رئيس مجلس الإدارة - المدير العام";
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - قيد التطوير والإنشاء</title>
    <meta name="description" content="الموقع الرسمي لشركة بيلد للتطوير العقاري بالمملكة العربية السعودية. مشاريع سكنية وتجارية بمعايير عالمية وجودة تنفيذ استثنائية.">
    <meta name="keywords" content="بيلد, للتطوير العقاري, عقارات السعودية, بريدة, القصيم, الرياض, مشاريع سكنية, BUILD">
    
    <!-- Open Graph -->
    <meta property="og:title" content="<?= $pageTitle ?>">
    <meta property="og:description" content="نبتكر معالم الغد ونبني بثقة وإتقان. مشاريع سكنية وتجارية بمعايير عالمية.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://buildesa.com/">

    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#fbf8f3',
                            100: '#f6eedf',
                            200: '#eddcbF',
                            300: '#e1c397',
                            400: '#d3a36d',
                            500: '#c5864c',
                            600: '#b46d3f',
                            700: '#955435',
                            800: '#794430',
                            900: '#64392b',
                        },
                        gold: {
                            400: '#fbbf24',
                            500: '#f59e0b',
                            600: '#d97706',
                        },
                        dark: {
                            950: '#070a11',
                            900: '#0b101d',
                            850: '#101726',
                            800: '#162033',
                            700: '#223049'
                        }
                    },
                    fontFamily: {
                        sans: ['Tajawal', 'sans-serif'],
                    }
                }
            }
        }
    </script>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800;900&display=swap" rel="stylesheet">

    <!-- FontAwesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <style>
        body {
            font-family: 'Tajawal', sans-serif;
            background-color: #070a11;
            color: #f1f5f9;
        }
        .glow-effect {
            box-shadow: 0 0 50px -10px rgba(217, 119, 6, 0.25);
        }
        .glass-card {
            background: rgba(16, 23, 38, 0.7);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
        .glass-card-hover:hover {
            border-color: rgba(245, 158, 11, 0.35);
            transform: translateY(-4px);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .gold-gradient-text {
            background: linear-gradient(135deg, #fef3c7 0%, #f59e0b 50%, #d97706 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .gold-gradient-bg {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        }
        @keyframes pulse-slow {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.85; transform: scale(1.02); }
        }
        .animate-pulse-slow {
            animation: pulse-slow 4s infinite ease-in-out;
        }
    </style>
</head>
<body class="selection:bg-gold-500 selection:text-black overflow-x-hidden min-h-screen flex flex-col">

    <!-- Ambient Lighting / Background Glow -->
    <div class="fixed inset-0 pointer-events-none z-0 overflow-hidden">
        <div class="absolute -top-40 right-1/4 w-96 h-96 bg-amber-600/10 rounded-full blur-3xl"></div>
        <div class="absolute top-1/3 left-10 w-80 h-80 bg-blue-600/10 rounded-full blur-3xl"></div>
        <div class="absolute bottom-10 right-10 w-96 h-96 bg-amber-500/10 rounded-full blur-3xl"></div>
    </div>

    <!-- Header / Navbar -->
    <header class="relative z-50 sticky top-0 bg-dark-950/80 backdrop-blur-md border-b border-white/5 transition-all">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-20 flex items-center justify-between">
            <!-- Brand Logo -->
            <a href="index.php" class="flex items-center gap-3.5 group">
                <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-amber-400 to-amber-600 p-0.5 shadow-lg shadow-amber-500/20 group-hover:scale-105 transition-transform">
                    <div class="w-full h-full bg-dark-900 rounded-[10px] flex items-center justify-center">
                        <svg class="w-7 h-7 text-amber-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M3 21h18M5 21V7l8-4v18M13 21V3l6 4v14" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M9 10h.01M9 14h.01M9 18h.01M17 10h.01M17 14h.01M17 18h.01" stroke-width="2.5" stroke-linecap="round"/>
                        </svg>
                    </div>
                </div>
                <div class="flex flex-col">
                    <span class="text-2xl font-black tracking-wider text-white flex items-center gap-1.5">
                        BUILD <span class="text-xs px-2 py-0.5 rounded bg-amber-500/20 text-amber-400 font-medium">عقارات</span>
                    </span>
                    <span class="text-xs text-slate-400 font-medium tracking-wide">بيلد للتطوير العقاري</span>
                </div>
            </a>

            <!-- Desktop Nav Items -->
            <nav class="hidden md:flex items-center gap-8 text-sm font-medium text-slate-300">
                <a href="#about" class="hover:text-amber-400 transition-colors">عن الشركة</a>
                <a href="#projects" class="hover:text-amber-400 transition-colors">مشاريعنا</a>
                <a href="#services" class="hover:text-amber-400 transition-colors">الخدمات</a>
                <a href="#trust" class="hover:text-amber-400 transition-colors">الاعتمادات</a>
                <a href="#contact" class="hover:text-amber-400 transition-colors">تواصل معنا</a>
            </nav>

            <!-- Action Buttons -->
            <div class="flex items-center gap-3">
                <!-- ERP Portal Link -->
                <a href="/erp/" class="px-4 py-2.5 rounded-xl border border-amber-500/30 bg-amber-500/10 hover:bg-amber-500/20 text-amber-300 hover:text-amber-200 text-sm font-bold flex items-center gap-2 transition-all shadow-sm group" title="دخول الموظفين ونظام الإدارة">
                    <i class="fa-solid fa-layer-group text-amber-400 group-hover:rotate-12 transition-transform"></i>
                    <span>نظام الـ ERP</span>
                </a>

                <!-- WhatsApp Direct -->
                <a href="https://wa.me/<?= $phone1Raw ?>" target="_blank" class="hidden sm:flex items-center gap-2 px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-bold shadow-lg shadow-emerald-600/20 transition-all">
                    <i class="fa-brands fa-whatsapp text-lg"></i>
                    <span>واتساب</span>
                </a>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="relative z-10 flex-1">

        <!-- Hero Section -->
        <section class="relative pt-12 pb-20 md:pt-20 md:pb-32 overflow-hidden border-b border-white/5">
            <!-- Background Image with Overlay -->
            <div class="absolute inset-0 z-0">
                <img src="assets/hero_bg.jpg" alt="BUILD Architectural Projects" class="w-full h-full object-cover object-center opacity-25 filter blur-[1px] scale-105">
                <div class="absolute inset-0 bg-gradient-to-t from-dark-950 via-dark-950/90 to-dark-950/70"></div>
                <div class="absolute inset-0 bg-gradient-to-r from-dark-950 via-transparent to-dark-950"></div>
            </div>

            <div class="relative z-10 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
                <!-- Coming Soon Status Badge -->
                <div class="inline-flex items-center gap-2.5 px-4 py-2 rounded-full glass-card border-amber-500/30 text-amber-300 text-xs sm:text-sm font-semibold mb-8 animate-pulse-slow shadow-lg shadow-amber-500/10">
                    <span class="w-2.5 h-2.5 rounded-full bg-amber-400 animate-ping"></span>
                    <i class="fa-solid fa-compass-drafting text-amber-400"></i>
                    <span>الموقع الرسمي للشركة قيد التطوير والتحديث | Coming Soon</span>
                </div>

                <!-- Main Headline -->
                <h1 class="text-3xl sm:text-5xl lg:text-6xl font-black text-white leading-tight tracking-tight mb-6 max-w-4xl mx-auto">
                    نبتكر معالم الغد.. <br>
                    <span class="gold-gradient-text">ونبني بثقة وإتقان</span>
                </h1>

                <!-- Subtitle / Intro -->
                <p class="text-base sm:text-lg lg:text-xl text-slate-300 max-w-3xl mx-auto leading-relaxed mb-10 font-normal">
                    شركة سعودية رائدة متخصصة في التطوير العقاري. نطور مشاريع سكنية وتجارية بمعايير جودة عالية وتصاميم عصرية تواكب تطلعات عملائنا وشركائنا في المملكة العربية السعودية.
                </p>

                <!-- Digital Progress Bar Widget -->
                <div class="max-w-md mx-auto glass-card rounded-2xl p-5 mb-10 text-right">
                    <div class="flex items-center justify-between text-xs sm:text-sm font-bold text-slate-300 mb-2.5">
                        <span class="flex items-center gap-2 text-amber-400">
                            <i class="fa-solid fa-code text-sm"></i>
                            تجهيز المنصة الرقمية وبوابة الخدمات
                        </span>
                        <span class="text-amber-400 font-mono">85%</span>
                    </div>
                    <div class="w-full h-2.5 bg-dark-800 rounded-full overflow-hidden p-0.5 border border-white/5">
                        <div class="h-full rounded-full gold-gradient-bg transition-all duration-1000 shadow-sm" style="width: 85%"></div>
                    </div>
                    <div class="flex items-center justify-between text-[11px] text-slate-400 mt-2">
                        <span>التصميم المعماري الرقمي: مكتمل</span>
                        <span>الإطلاق الكامل: قريباً</span>
                    </div>
                </div>

                <!-- Hero CTA Buttons -->
                <div class="flex flex-wrap items-center justify-center gap-4">
                    <!-- Download Company Profile -->
                    <a href="BUILD_Company_Profile.pdf" download class="px-7 py-3.5 rounded-xl gold-gradient-bg hover:brightness-110 text-dark-950 font-bold text-sm sm:text-base flex items-center gap-2.5 shadow-xl shadow-amber-500/20 transition-all hover:scale-105">
                        <i class="fa-solid fa-file-pdf text-lg"></i>
                        <span>تحميل بروفايل الشركة (PDF)</span>
                    </a>

                    <!-- Direct Contact -->
                    <a href="#contact" class="px-7 py-3.5 rounded-xl glass-card hover:bg-white/10 text-white font-bold text-sm sm:text-base flex items-center gap-2.5 border border-white/15 transition-all">
                        <i class="fa-solid fa-headset text-amber-400"></i>
                        <span>تواصل مع الإدارة</span>
                    </a>

                    <!-- ERP Access -->
                    <a href="/erp/" class="px-6 py-3.5 rounded-xl bg-dark-800 hover:bg-dark-700 text-slate-200 font-bold text-sm sm:text-base flex items-center gap-2.5 border border-white/10 transition-all" title="بوابة تسجيل الدخول لنظام ERP">
                        <i class="fa-solid fa-shield-halved text-amber-400"></i>
                        <span>بوابة نظام الـ ERP</span>
                    </a>
                </div>
            </div>
        </section>

        <!-- Company Stats Section (الشركة بالأرقام) -->
        <section class="py-12 bg-dark-900/60 border-b border-white/5 relative z-10">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
                    <!-- Stat 1 -->
                    <div class="glass-card rounded-2xl p-6 text-center glass-card-hover transition-all">
                        <div class="w-12 h-12 mx-auto mb-3 rounded-xl bg-amber-500/10 flex items-center justify-center text-amber-400 text-xl">
                            <i class="fa-solid fa-award"></i>
                        </div>
                        <div class="text-3xl sm:text-4xl font-black text-white font-mono mb-1">+5</div>
                        <div class="text-xs sm:text-sm text-slate-400 font-medium">سنوات من الخبرة والريادة (منذ 2017)</div>
                    </div>

                    <!-- Stat 2 -->
                    <div class="glass-card rounded-2xl p-6 text-center glass-card-hover transition-all">
                        <div class="w-12 h-12 mx-auto mb-3 rounded-xl bg-amber-500/10 flex items-center justify-center text-amber-400 text-xl">
                            <i class="fa-solid fa-city"></i>
                        </div>
                        <div class="text-3xl sm:text-4xl font-black text-white font-mono mb-1">21+</div>
                        <div class="text-xs sm:text-sm text-slate-400 font-medium">مشروعاً عقارياً منجزاً وقيد التطوير</div>
                    </div>

                    <!-- Stat 3 -->
                    <div class="glass-card rounded-2xl p-6 text-center glass-card-hover transition-all">
                        <div class="w-12 h-12 mx-auto mb-3 rounded-xl bg-amber-500/10 flex items-center justify-center text-amber-400 text-xl">
                            <i class="fa-solid fa-heart"></i>
                        </div>
                        <div class="text-3xl sm:text-4xl font-black text-white font-mono mb-1">98%</div>
                        <div class="text-xs sm:text-sm text-slate-400 font-medium">نسبة رضا العملاء وثقة المستثمرين</div>
                    </div>

                    <!-- Stat 4 -->
                    <div class="glass-card rounded-2xl p-6 text-center glass-card-hover transition-all">
                        <div class="w-12 h-12 mx-auto mb-3 rounded-xl bg-amber-500/10 flex items-center justify-center text-amber-400 text-xl">
                            <i class="fa-solid fa-certificate"></i>
                        </div>
                        <div class="text-3xl sm:text-4xl font-black text-white font-mono mb-1">100%</div>
                        <div class="text-xs sm:text-sm text-slate-400 font-medium">امتثال وتراخيص معتمدة وموثقة</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- About Section (من نحن ورسالتنا) -->
        <section id="about" class="py-20 md:py-28 relative">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 lg:grid-cols-12 gap-12 items-center">
                    
                    <!-- Left: About Text & Values -->
                    <div class="lg:col-span-7 space-y-6">
                        <div class="inline-flex items-center gap-2 text-amber-400 text-sm font-bold tracking-wider">
                            <span class="w-8 h-[2px] bg-amber-400"></span>
                            عن شركة بيلد للتطوير العقاري
                        </div>
                        
                        <h2 class="text-2xl sm:text-4xl font-extrabold text-white leading-snug">
                            نجمع بين <span class="gold-gradient-text">الخبرة الهندسية</span> والإدارة الواعية والالتزام بالمواعيد
                        </h2>

                        <p class="text-slate-300 leading-relaxed text-base sm:text-lg">
                            بيلد شركة سعودية متخصصة في التطوير العقاري تأسست عام 1438 هـ (2017 م). نعمل على تطوير مشاريع سكنية وتجارية بمعايير جودة عالية وتصاميم عصرية تلبي احتياجات السوق، لنقدم لعملائنا منتجات عقارية موثوقة تحافظ على قيمتها واستدامتها على المدى الطويل.
                        </p>

                        <!-- Chairman Quote Card -->
                        <div class="glass-card rounded-2xl p-6 border-r-4 border-amber-500">
                            <p class="text-slate-200 italic mb-4 leading-relaxed text-sm sm:text-base">
                                "نؤمن بأن التطوير العقاري مسؤولية تجاه العملاء والمجتمع قبل أن يكون مشاريع تُنفّذ، لذلك نلتزم بجودة التنفيذ والشفافية في التعامل واحترام المواعيد وبناء شراكات طويلة الأمد."
                            </p>
                            <div class="flex items-center justify-between text-xs sm:text-sm">
                                <div>
                                    <div class="font-bold text-white"><?= $gmName ?></div>
                                    <div class="text-slate-400"><?= $gmTitle ?></div>
                                </div>
                                <div class="w-10 h-10 rounded-full bg-amber-500/20 text-amber-400 flex items-center justify-center font-bold">
                                    <i class="fa-solid fa-quote-left"></i>
                                </div>
                            </div>
                        </div>

                        <!-- Vision & Mission Grid -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2">
                            <div class="glass-card rounded-xl p-5 border border-white/5">
                                <div class="flex items-center gap-2.5 text-amber-400 font-bold mb-2">
                                    <i class="fa-regular fa-eye text-lg"></i>
                                    <h3>رؤيتنا</h3>
                                </div>
                                <p class="text-xs sm:text-sm text-slate-300 leading-relaxed">
                                    أن نكون من الشركات الرائدة في التطوير العقاري بالمملكة، بمشاريع تضيف قيمة حقيقية للمجتمع والعميل.
                                </p>
                            </div>

                            <div class="glass-card rounded-xl p-5 border border-white/5">
                                <div class="flex items-center gap-2.5 text-amber-400 font-bold mb-2">
                                    <i class="fa-solid fa-bullseye text-lg"></i>
                                    <h3>رسالتنا</h3>
                                </div>
                                <p class="text-xs sm:text-sm text-slate-300 leading-relaxed">
                                    تطوير مشاريع عقارية عالية الجودة بتصاميم عصرية مع التزام تام بالمواعيد وأعلى درجات الشفافية.
                                </p>
                            </div>
                        </div>

                        <!-- Values Badges -->
                        <div class="pt-2">
                            <div class="text-xs text-slate-400 font-bold mb-3">قيمنا المؤسسية:</div>
                            <div class="flex flex-wrap gap-2">
                                <span class="px-3 py-1.5 rounded-lg bg-dark-800 text-amber-300 text-xs font-semibold border border-white/5">الأمانة</span>
                                <span class="px-3 py-1.5 rounded-lg bg-dark-800 text-amber-300 text-xs font-semibold border border-white/5">الجودة الصارمة</span>
                                <span class="px-3 py-1.5 rounded-lg bg-dark-800 text-amber-300 text-xs font-semibold border border-white/5">الابتكار المعماري</span>
                                <span class="px-3 py-1.5 rounded-lg bg-dark-800 text-amber-300 text-xs font-semibold border border-white/5">الالتزام بالعقود</span>
                                <span class="px-3 py-1.5 rounded-lg bg-dark-800 text-amber-300 text-xs font-semibold border border-white/5">الاستدامة البيئية</span>
                                <span class="px-3 py-1.5 rounded-lg bg-dark-800 text-amber-300 text-xs font-semibold border border-white/5">الشراكة الدائمة</span>
                            </div>
                        </div>

                    </div>

                    <!-- Right: Visual Architecture Show -->
                    <div class="lg:col-span-5 space-y-4">
                        <div class="relative rounded-3xl overflow-hidden shadow-2xl border border-white/10 group">
                            <img src="assets/project_architecture.jpg" alt="Architecture" class="w-full h-80 sm:h-96 object-cover object-center group-hover:scale-105 transition-transform duration-500">
                            <div class="absolute inset-0 bg-gradient-to-t from-dark-950 via-dark-950/40 to-transparent"></div>
                            
                            <!-- Overlay Badge -->
                            <div class="absolute bottom-6 right-6 left-6 glass-card p-4 rounded-2xl border-white/10">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <div class="text-xs text-amber-400 font-bold">الهندسة والإشراف</div>
                                        <div class="text-sm font-bold text-white">معايير كود البناء السعودي</div>
                                    </div>
                                    <div class="text-xs bg-emerald-500/20 text-emerald-300 px-3 py-1 rounded-full font-bold">
                                        معتمد
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Mini stats banner -->
                        <div class="glass-card rounded-2xl p-4 flex items-center justify-between text-xs text-slate-300">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-xl bg-amber-500/20 text-amber-400 flex items-center justify-center font-bold">
                                    <i class="fa-solid fa-file-contract"></i>
                                </div>
                                <div>
                                    <div class="text-slate-400">السجل التجاري الرسمي</div>
                                    <div class="font-mono font-bold text-white text-sm"><?= $crNumber ?></div>
                                </div>
                            </div>
                            <div class="text-left font-mono text-amber-400 font-bold">
                                <?= $certNumber ?>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </section>

        <!-- Projects Showcase Section (أبرز المشاريع) -->
        <section id="projects" class="py-20 bg-dark-900/50 border-y border-white/5 relative">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                
                <!-- Section Header -->
                <div class="text-center max-w-3xl mx-auto mb-16">
                    <div class="inline-flex items-center gap-2 text-amber-400 text-sm font-bold mb-3">
                        <span class="w-6 h-[2px] bg-amber-400"></span>
                        سجل الإنجازات
                        <span class="w-6 h-[2px] bg-amber-400"></span>
                    </div>
                    <h2 class="text-2xl sm:text-4xl font-extrabold text-white mb-4">
                        مشاريع مميزة تترجم رؤيتنا على أرض الواقع
                    </h2>
                    <p class="text-slate-400 text-sm sm:text-base leading-relaxed">
                        نقدم نماذج سكنية وتجارية تجمع بين الفخامة وحسن استغلال المساحات وأعلى جودة للمواد والتشطيبات.
                    </p>
                </div>

                <!-- Projects Cards Grid -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                    
                    <!-- Project 1: Salman -->
                    <div class="glass-card rounded-3xl overflow-hidden glass-card-hover transition-all duration-300 flex flex-col">
                        <div class="relative h-64 overflow-hidden group">
                            <img src="assets/project_salman.jpg" alt="مشروع السلمان السكني" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500">
                            <div class="absolute inset-0 bg-gradient-to-t from-dark-950 via-dark-950/20 to-transparent"></div>
                            <div class="absolute top-4 right-4 bg-amber-500 text-dark-950 text-xs font-black px-3 py-1 rounded-full shadow-md">
                                سكني مودرن
                            </div>
                            <div class="absolute bottom-4 right-4 left-4 flex items-center justify-between text-white text-xs">
                                <span class="flex items-center gap-1.5"><i class="fa-solid fa-location-dot text-amber-400"></i> القصيم - بريدة</span>
                                <span class="px-2 py-0.5 rounded bg-emerald-500/20 text-emerald-300 font-bold">مكتمل</span>
                            </div>
                        </div>
                        <div class="p-6 flex-1 flex flex-col justify-between space-y-4">
                            <div>
                                <h3 class="text-xl font-bold text-white mb-2">مشروع السلمان السكني</h3>
                                <p class="text-slate-400 text-xs sm:text-sm leading-relaxed">
                                    يجسد مفهوم السكن الحديث من خلال تصميم معماري عصري (مودرن) يدمج الفخامة واللمسات الحجرية والخشبية الأنيقة مع توزيع ذكي للمساحات وإضاءة طبيعية رحبة.
                                </p>
                            </div>
                            <div class="pt-4 border-t border-white/5 flex items-center justify-between text-xs text-slate-300">
                                <span><i class="fa-solid fa-layer-group text-amber-400 ml-1"></i> وحدات سكنية متكاملة</span>
                                <span class="font-bold text-amber-400">تشطيب فاخر</span>
                            </div>
                        </div>
                    </div>

                    <!-- Project 2: Sultana -->
                    <div class="glass-card rounded-3xl overflow-hidden glass-card-hover transition-all duration-300 flex flex-col">
                        <div class="relative h-64 overflow-hidden group">
                            <img src="assets/project_sultana.jpg" alt="صالات تجارية سلطانة" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500">
                            <div class="absolute inset-0 bg-gradient-to-t from-dark-950 via-dark-950/20 to-transparent"></div>
                            <div class="absolute top-4 right-4 bg-blue-500 text-white text-xs font-black px-3 py-1 rounded-full shadow-md">
                                تجاري استثماري
                            </div>
                            <div class="absolute bottom-4 right-4 left-4 flex items-center justify-between text-white text-xs">
                                <span class="flex items-center gap-1.5"><i class="fa-solid fa-location-dot text-blue-400"></i> المدينة المنورة</span>
                                <span class="px-2 py-0.5 rounded bg-blue-500/20 text-blue-300 font-bold">معارض تجارية</span>
                            </div>
                        </div>
                        <div class="p-6 flex-1 flex flex-col justify-between space-y-4">
                            <div>
                                <h3 class="text-xl font-bold text-white mb-2">صالات تجارية سلطانة</h3>
                                <p class="text-slate-400 text-xs sm:text-sm leading-relaxed">
                                    وجهة تجارية عصرية مميزة على مساحة 1,200 م² بواجهات زجاجية واسعة تمنح إضاءة ورؤية واضحة للأنشطة المختلفة مع لمسات حجرية أنيقة تلائم كبرى العلامات.
                                </p>
                            </div>
                            <div class="pt-4 border-t border-white/5 flex items-center justify-between text-xs text-slate-300">
                                <span><i class="fa-solid fa-store text-amber-400 ml-1"></i> 4 صالات واسعة</span>
                                <span class="font-bold text-amber-400">موقع استراتيجي</span>
                            </div>
                        </div>
                    </div>

                    <!-- Project 3: Takhasosi -->
                    <div class="glass-card rounded-3xl overflow-hidden glass-card-hover transition-all duration-300 flex flex-col">
                        <div class="relative h-64 overflow-hidden group">
                            <img src="assets/project_development.jpg" alt="مشروع التخصصي" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500">
                            <div class="absolute inset-0 bg-gradient-to-t from-dark-950 via-dark-950/20 to-transparent"></div>
                            <div class="absolute top-4 right-4 bg-emerald-500 text-dark-950 text-xs font-black px-3 py-1 rounded-full shadow-md">
                                سكني عائلي
                            </div>
                            <div class="absolute bottom-4 right-4 left-4 flex items-center justify-between text-white text-xs">
                                <span class="flex items-center gap-1.5"><i class="fa-solid fa-location-dot text-amber-400"></i> القصيم - بريدة</span>
                                <span class="px-2 py-0.5 rounded bg-emerald-500/20 text-emerald-300 font-bold">منجز</span>
                            </div>
                        </div>
                        <div class="p-6 flex-1 flex flex-col justify-between space-y-4">
                            <div>
                                <h3 class="text-xl font-bold text-white mb-2">مشروع التخصصي</h3>
                                <p class="text-slate-400 text-xs sm:text-sm leading-relaxed">
                                    وحدات سكنية رحبة مصممة لتلبية احتياجات الأسرة السعودية بخصوصية تامة، منفذة تحت إشراف هندسي دقيق مع تطبيق كافة اشتراطات الاستدامة والعزل الحراري.
                                </p>
                            </div>
                            <div class="pt-4 border-t border-white/5 flex items-center justify-between text-xs text-slate-300">
                                <span><i class="fa-solid fa-house text-amber-400 ml-1"></i> فلل ووحدات سكنية</span>
                                <span class="font-bold text-amber-400">ضمانات ممتدة</span>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </section>

        <!-- Services Section (خدماتنا) -->
        <section id="services" class="py-20 md:py-28 relative">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="text-center max-w-3xl mx-auto mb-16">
                    <div class="inline-flex items-center gap-2 text-amber-400 text-sm font-bold mb-3">
                        <span class="w-6 h-[2px] bg-amber-400"></span>
                        أعمال الشركة
                        <span class="w-6 h-[2px] bg-amber-400"></span>
                    </div>
                    <h2 class="text-2xl sm:text-4xl font-extrabold text-white mb-4">
                        خدمات تطوير عقاري متكاملة من الفكرة حتى التسليم
                    </h2>
                    <p class="text-slate-400 text-sm sm:text-base">
                        نغطي دورة حياة المشروع العقاري بالكامل وفق أعلى معايير الحوكمة والرقابة الهندسية.
                    </p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <!-- Service 1 -->
                    <div class="glass-card rounded-2xl p-7 glass-card-hover transition-all">
                        <div class="w-14 h-14 rounded-2xl bg-amber-500/10 text-amber-400 flex items-center justify-center text-2xl mb-5">
                            <i class="fa-solid fa-map-location-dot"></i>
                        </div>
                        <h3 class="text-lg font-bold text-white mb-2.5">شراء وتطوير الأراضي</h3>
                        <p class="text-slate-400 text-sm leading-relaxed">
                            دراسات جدوى متخصصة واختيار دقيق للمواقع الحيوية وتخطيط الأراضي لتحقيق أقصى عائد استثماري وقيمة عمرانية.
                        </p>
                    </div>

                    <!-- Service 2 -->
                    <div class="glass-card rounded-2xl p-7 glass-card-hover transition-all">
                        <div class="w-14 h-14 rounded-2xl bg-amber-500/10 text-amber-400 flex items-center justify-center text-2xl mb-5">
                            <i class="fa-solid fa-drafting-compass"></i>
                        </div>
                        <h3 class="text-lg font-bold text-white mb-2.5">التصميم والتخطيط المعماري</h3>
                        <p class="text-slate-400 text-sm leading-relaxed">
                            تصاميم معمارية وإنشائية وكهروميكانيكية عصرية وفق أحدث كودات البناء مع مراعاة كفاءة استغلال المساحات.
                        </p>
                    </div>

                    <!-- Service 3 -->
                    <div class="glass-card rounded-2xl p-7 glass-card-hover transition-all">
                        <div class="w-14 h-14 rounded-2xl bg-amber-500/10 text-amber-400 flex items-center justify-center text-2xl mb-5">
                            <i class="fa-solid fa-list-check"></i>
                        </div>
                        <h3 class="text-lg font-bold text-white mb-2.5">إدارة المشاريع والإشراف الهندسي</h3>
                        <p class="text-slate-400 text-sm leading-relaxed">
                            متابعة ميدانية يومية للجدول الزمني ومراقبة صارمة لضبط الجودة ومطابقة المواصفات المعتمدة.
                        </p>
                    </div>

                    <!-- Service 4 -->
                    <div class="glass-card rounded-2xl p-7 glass-card-hover transition-all">
                        <div class="w-14 h-14 rounded-2xl bg-amber-500/10 text-amber-400 flex items-center justify-center text-2xl mb-5">
                            <i class="fa-solid fa-helmet-safety"></i>
                        </div>
                        <h3 class="text-lg font-bold text-white mb-2.5">التنفيذ والمقاولات</h3>
                        <p class="text-slate-400 text-sm leading-relaxed">
                            تنفيذ الأعمال الإنشائية والتشطيبات الفاخرة بواسطة كوادر فنية متخصصة ومقاولين معتمدين.
                        </p>
                    </div>

                    <!-- Service 5 -->
                    <div class="glass-card rounded-2xl p-7 glass-card-hover transition-all">
                        <div class="w-14 h-14 rounded-2xl bg-amber-500/10 text-amber-400 flex items-center justify-center text-2xl mb-5">
                            <i class="fa-solid fa-bullhorn"></i>
                        </div>
                        <h3 class="text-lg font-bold text-white mb-2.5">التسويق والمبيعات</h3>
                        <p class="text-slate-400 text-sm leading-relaxed">
                            خطط تسويق مبتكرة وعرض احترافي للوحدات وخدمة متميزة للعملاء وتسهيل حلول التمويل العقاري المعتمدة.
                        </p>
                    </div>

                    <!-- Service 6 -->
                    <div class="glass-card rounded-2xl p-7 glass-card-hover transition-all">
                        <div class="w-14 h-14 rounded-2xl bg-amber-500/10 text-amber-400 flex items-center justify-center text-2xl mb-5">
                            <i class="fa-solid fa-hand-holding-hand"></i>
                        </div>
                        <h3 class="text-lg font-bold text-white mb-2.5">إدارة الأملاك وخدمة ما بعد البيع</h3>
                        <p class="text-slate-400 text-sm leading-relaxed">
                            ضمانات شاملة وفريق صيانة سريع الاستجابة وإدارة متكاملة للمرافق للمحافظة على أصول عملائنا.
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Trust & Guarantees (الضمانات والامتثال) -->
        <section id="trust" class="py-16 bg-gradient-to-b from-dark-900/70 to-dark-950 border-t border-white/5">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="glass-card rounded-3xl p-8 sm:p-12 border border-amber-500/20 relative overflow-hidden">
                    <div class="absolute -right-20 -bottom-20 w-80 h-80 bg-amber-500/10 rounded-full blur-3xl pointer-events-none"></div>

                    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-center">
                        <div class="lg:col-span-8 space-y-4">
                            <span class="text-xs font-bold text-amber-400 bg-amber-500/10 px-3 py-1 rounded-full border border-amber-500/20">
                                راحة بالك أولويتنا
                            </span>
                            <h2 class="text-2xl sm:text-3xl font-extrabold text-white">
                                ضمانات شاملة واستجابة سريعة لخدمة ما بعد البيع
                            </h2>
                            <p class="text-slate-300 text-sm leading-relaxed">
                                نلتزم بأعلى معايير الحماية التعاقدية لجميع ملاك الوحدات مع تقديم باقات ضمان موثقة:
                            </p>

                            <!-- Guarantees Cards -->
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 pt-2">
                                <div class="bg-dark-900/80 rounded-xl p-4 border border-white/5">
                                    <div class="text-amber-400 font-bold text-xl font-mono mb-1">10 سنوات</div>
                                    <div class="text-xs text-slate-300 font-bold">ضمان الهيكل الإنشائي</div>
                                    <div class="text-[11px] text-slate-400">من تاريخ شهادة الإشغال</div>
                                </div>

                                <div class="bg-dark-900/80 rounded-xl p-4 border border-white/5">
                                    <div class="text-amber-400 font-bold text-xl font-mono mb-1">سنتان</div>
                                    <div class="text-xs text-slate-300 font-bold">الأعمال الكهربائية والصحية</div>
                                    <div class="text-[11px] text-slate-400">من تاريخ الاستلام الفعلي</div>
                                </div>

                                <div class="bg-dark-900/80 rounded-xl p-4 border border-white/5">
                                    <div class="text-amber-400 font-bold text-xl font-mono mb-1">24 ساعة</div>
                                    <div class="text-xs text-slate-300 font-bold">استجابة طوارئ الصيانة</div>
                                    <div class="text-[11px] text-slate-400">فريق مخصص لخدمة العملاء</div>
                                </div>
                            </div>
                        </div>

                        <!-- Right: Action Button -->
                        <div class="lg:col-span-4 text-center lg:text-left space-y-3">
                            <a href="BUILD_Company_Profile.pdf" download class="w-full sm:w-auto inline-flex items-center justify-center gap-2.5 px-6 py-4 rounded-xl gold-gradient-bg text-dark-950 font-extrabold text-sm shadow-xl shadow-amber-500/20 hover:scale-105 transition-all">
                                <i class="fa-solid fa-file-arrow-down text-lg"></i>
                                <span>تحميل كتيب الضمانات والملف التعريفي</span>
                            </a>
                            <div class="text-xs text-slate-400">ملف PDF رسمي (18 ميجابايت)</div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Contact Section (تواصل معنا) -->
        <section id="contact" class="py-20 md:py-28 relative">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                
                <div class="text-center max-w-3xl mx-auto mb-16">
                    <div class="inline-flex items-center gap-2 text-amber-400 text-sm font-bold mb-3">
                        <span class="w-6 h-[2px] bg-amber-400"></span>
                        يسعدنا تواصلكم
                        <span class="w-6 h-[2px] bg-amber-400"></span>
                    </div>
                    <h2 class="text-2xl sm:text-4xl font-extrabold text-white mb-4">
                        فريقنا جاهز للإجابة على استفساراتكم وحجز الوحدات
                    </h2>
                    <p class="text-slate-400 text-sm sm:text-base">
                        تواصل معنا عبر الهاتف، الواتساب، أو زيارة مقراتنا في الرياض والقصيم.
                    </p>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
                    
                    <!-- Contact Info Cards (Left) -->
                    <div class="lg:col-span-5 space-y-4">
                        
                        <!-- Phone 1 -->
                        <a href="tel:<?= $phone1Raw ?>" class="glass-card rounded-2xl p-5 flex items-center gap-4 glass-card-hover transition-all block">
                            <div class="w-12 h-12 rounded-xl bg-amber-500/10 text-amber-400 flex items-center justify-center text-xl shrink-0">
                                <i class="fa-solid fa-phone"></i>
                            </div>
                            <div class="flex-1">
                                <div class="text-xs text-slate-400 font-medium">الهاتف الرئيسي والمبيعات</div>
                                <div class="font-mono font-bold text-white text-base dir-ltr text-right"><?= $phone1 ?></div>
                            </div>
                            <span class="text-xs px-3 py-1 rounded bg-amber-500/20 text-amber-300 font-bold">اتصال</span>
                        </a>

                        <!-- Phone 2 -->
                        <a href="tel:<?= $phone2Raw ?>" class="glass-card rounded-2xl p-5 flex items-center gap-4 glass-card-hover transition-all block">
                            <div class="w-12 h-12 rounded-xl bg-amber-500/10 text-amber-400 flex items-center justify-center text-xl shrink-0">
                                <i class="fa-solid fa-mobile-screen"></i>
                            </div>
                            <div class="flex-1">
                                <div class="text-xs text-slate-400 font-medium">خدمة العملاء والإدارة</div>
                                <div class="font-mono font-bold text-white text-base dir-ltr text-right"><?= $phone2 ?></div>
                            </div>
                            <span class="text-xs px-3 py-1 rounded bg-amber-500/20 text-amber-300 font-bold">اتصال</span>
                        </a>

                        <!-- Email -->
                        <a href="mailto:<?= $email ?>" class="glass-card rounded-2xl p-5 flex items-center gap-4 glass-card-hover transition-all block">
                            <div class="w-12 h-12 rounded-xl bg-amber-500/10 text-amber-400 flex items-center justify-center text-xl shrink-0">
                                <i class="fa-solid fa-envelope"></i>
                            </div>
                            <div class="flex-1">
                                <div class="text-xs text-slate-400 font-medium">البريد الإلكتروني الرسمي</div>
                                <div class="font-mono font-bold text-white text-sm"><?= $email ?></div>
                            </div>
                            <span class="text-xs px-3 py-1 rounded bg-white/10 text-slate-300 font-bold">إرسال</span>
                        </a>

                        <!-- Offices -->
                        <div class="glass-card rounded-2xl p-5 space-y-4">
                            <div class="flex items-start gap-4">
                                <div class="w-12 h-12 rounded-xl bg-amber-500/10 text-amber-400 flex items-center justify-center text-xl shrink-0">
                                    <i class="fa-solid fa-location-dot"></i>
                                </div>
                                <div class="space-y-2 text-xs sm:text-sm">
                                    <div>
                                        <div class="font-bold text-white">المقر الرئيسي - القصيم:</div>
                                        <div class="text-slate-400">طريق الملك سليمان - المملكة العربية السعودية</div>
                                    </div>
                                    <div class="pt-2 border-t border-white/5">
                                        <div class="font-bold text-white">فرع الرياض:</div>
                                        <div class="text-slate-400">حي الصحابة - المملكة العربية السعودية</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>

                    <!-- Right: Direct WhatsApp Inquiry Box -->
                    <div class="lg:col-span-7">
                        <div class="glass-card rounded-3xl p-8 border border-white/10 relative">
                            <div class="flex items-center justify-between mb-6 pb-4 border-b border-white/5">
                                <div>
                                    <h3 class="text-lg font-bold text-white">إرسال استفسار مباشر</h3>
                                    <p class="text-xs text-slate-400">سيتم فتح محادثة واتساب فورية مع فريق المبيعات</p>
                                </div>
                                <div class="w-12 h-12 rounded-2xl bg-emerald-500/20 text-emerald-400 flex items-center justify-center text-2xl">
                                    <i class="fa-brands fa-whatsapp"></i>
                                </div>
                            </div>

                            <form id="inquiryForm" onsubmit="sendToWhatsApp(event)" class="space-y-4">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-bold text-slate-300 mb-2">الاسم الكريم</label>
                                        <input type="text" id="clientName" required placeholder="أدخل اسمك" class="w-full bg-dark-900 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-amber-500 transition-colors">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-slate-300 mb-2">رقم الجوال</label>
                                        <input type="tel" id="clientPhone" required placeholder="05xxxxxxxx" class="w-full bg-dark-900 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-amber-500 transition-colors text-right dir-ltr">
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-slate-300 mb-2">المشروع أو الخدمة المطلوبة</label>
                                    <select id="projectInterest" class="w-full bg-dark-900 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-amber-500 transition-colors">
                                        <option value="مشروع السلمان السكني">مشروع السلمان السكني (بريدة)</option>
                                        <option value="صالات تجارية سلطانة">صالات تجارية سلطانة (المدينة المنورة)</option>
                                        <option value="مشروع التخصصي">مشروع التخصصي (بريدة)</option>
                                        <option value="شراء وتطوير أراضي">شراء وتطوير أراضي واستثمار عقاري</option>
                                        <option value="استفسار عام">استفسار عام</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-slate-300 mb-2">تفاصيل الاستفسار (اختياري)</label>
                                    <textarea id="clientMsg" rows="3" placeholder="اكتب استفسارك هنا..." class="w-full bg-dark-900 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-amber-500 transition-colors"></textarea>
                                </div>

                                <button type="submit" class="w-full py-4 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-extrabold text-sm flex items-center justify-center gap-2.5 shadow-lg shadow-emerald-600/25 transition-all">
                                    <i class="fa-brands fa-whatsapp text-lg"></i>
                                    <span>إرسال الرسالة عبر الواتساب فوراً</span>
                                </button>
                            </form>
                        </div>
                    </div>

                </div>
            </div>
        </section>

    </main>

    <!-- Footer -->
    <footer class="relative z-10 bg-dark-950 border-t border-white/10 py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex flex-col md:flex-row items-center justify-between gap-6 pb-8 border-b border-white/5">
                
                <!-- Brand -->
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-amber-500/20 text-amber-400 flex items-center justify-center font-bold">
                        <i class="fa-solid fa-building text-lg"></i>
                    </div>
                    <div>
                        <div class="font-bold text-white text-base">بيلد للتطوير العقاري | BUILD</div>
                        <div class="text-xs text-slate-400">سجل تجاري: <?= $crNumber ?> | شهادة تأهيل: <?= $certNumber ?></div>
                    </div>
                </div>

                <!-- Footer Links -->
                <div class="flex flex-wrap items-center gap-6 text-xs text-slate-400 font-medium">
                    <a href="#about" class="hover:text-amber-400 transition-colors">عن الشركة</a>
                    <a href="#projects" class="hover:text-amber-400 transition-colors">المشاريع</a>
                    <a href="#services" class="hover:text-amber-400 transition-colors">الخدمات</a>
                    <a href="#contact" class="hover:text-amber-400 transition-colors">اتصل بنا</a>
                    <a href="/erp/" class="text-amber-400 hover:text-amber-300 font-bold flex items-center gap-1.5 px-3 py-1 rounded bg-amber-500/10 border border-amber-500/20">
                        <i class="fa-solid fa-lock text-[10px]"></i>
                        بوابة الموظفين (ERP)
                    </a>
                </div>

            </div>

            <div class="pt-8 flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-slate-400 text-center sm:text-right">
                <p>© <?= date('Y') ?> شركة بيلد للتطوير العقاري. جميع الحقوق محفوظة.</p>
                <p class="flex items-center gap-1 text-slate-400">
                    المملكة العربية السعودية - القصيم - الرياض
                </p>
            </div>
        </div>
    </footer>

    <!-- Interactive Script -->
    <script>
        function sendToWhatsApp(e) {
            e.preventDefault();
            const name = document.getElementById('clientName').value.trim();
            const phone = document.getElementById('clientPhone').value.trim();
            const interest = document.getElementById('projectInterest').value;
            const msg = document.getElementById('clientMsg').value.trim();

            let text = `*استفسار جديد من موقع buildesa.com*\n\n`;
            text += `👤 *الاسم:* ${name}\n`;
            text += `📱 *رقم الجوال:* ${phone}\n`;
            text += `🏗️ *المشروع / الخدمة:* ${interest}\n`;
            if (msg) {
                text += `📝 *ملاحظات:* ${msg}\n`;
            }

            const encoded = encodeURIComponent(text);
            const waUrl = `https://wa.me/<?= $phone1Raw ?>?text=${encoded}`;
            window.open(waUrl, '_blank');
        }
    </script>
</body>
</html>
