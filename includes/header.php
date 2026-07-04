<?php
/**
 * هدر و سایدبار – نسخه نهایی کامل با jQuery همیشگی
 * مسیر: /includes/header.php
 */
if (!defined('ROOT_PATH')) require_once __DIR__ . '/init.php';
$current_page = basename($_SERVER['PHP_SELF']);
$school_name = get_setting('school_name', 'دبیرستان نمونه');
$date_method = get_setting('date_input_method', 'dropdown');
$valid_years_str = get_setting('valid_years', '["1404","1405"]');
?><!DOCTYPE html>
<html lang="fa" dir="rtl" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($page_title) ? e($page_title) . ' - ' : '' ?><?= e($school_name) ?> | سیستم حسابداری شهریه</title>
    <link rel="stylesheet" href="<?= ASSETS_URL ?>css/tailwind.min.css?v=<?= ASSETS_VERSION ?>">

    <!-- jQuery همیشه بارگذاری شود -->
    <script src="<?= ASSETS_URL ?>persianDatepicker-0.1.0/js/jquery-1.10.1.min.js?v=<?= ASSETS_VERSION ?>"></script>

    <?php if ($date_method === 'picker'): ?>
        <!-- تقویم بصری فقط در صورت انتخاب این روش -->
        <link rel="stylesheet" href="<?= ASSETS_URL ?>persianDatepicker-0.1.0/css/persianDatepicker-default.css?v=<?= ASSETS_VERSION ?>">
        <script src="<?= ASSETS_URL ?>persianDatepicker-0.1.0/js/persianDatepicker.min.js?v=<?= ASSETS_VERSION ?>"></script>
    <?php endif; ?>

    <style>
        @media print {
            .no-print, #sidebar, header, .btn-pulse, button, a[href*="payment"] { display: none !important; }
            body { background: white; }
            .lg\:pr-72 { padding-right: 0 !important; }
            table { font-size: 12px; }
        }
    </style>
    <script>
        const DATE_INPUT_METHOD = '<?= e($date_method) ?>';
        const VALID_YEARS = <?= $valid_years_str ?: '[]' ?>;
    </script>
</head>
<body class="h-full bg-slate-100 text-slate-800 antialiased">
    <!-- Mobile overlay -->
    <div id="sidebar-overlay" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-40 hidden lg:hidden sidebar-transition opacity-0 no-print" onclick="toggleSidebar()"></div>

    <!-- Sidebar -->
    <aside id="sidebar" class="no-print fixed top-0 right-0 z-50 h-full w-72 bg-white border-l border-slate-200 shadow-xl shadow-slate-200/50 flex flex-col sidebar-transition translate-x-full lg:translate-x-0">
        <div class="flex items-center justify-between px-6 py-5 border-b border-slate-100">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-gradient-to-br from-indigo-600 to-blue-700 rounded-xl flex items-center justify-center shadow-lg shadow-indigo-200">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6m-1.5 12V10.332A48.36 48.36 0 0 0 12 9.75c-2.551 0-5.056.2-7.5.582V21M3 21h18M12 6.75h.008v.008H12V6.75Z"/>
                    </svg>
                </div>
                <div>
                    <h2 class="text-sm font-bold text-slate-800"><?= e($school_name) ?></h2>
                    <p class="text-xs text-slate-400">مدیریت شهریه</p>
                </div>
            </div>
            <button onclick="toggleSidebar()" class="lg:hidden p-2 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18 18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <nav class="flex-1 overflow-y-auto px-4 py-4 space-y-1">
            <?php
            $menu_items = [
                ['url' => 'index.php', 'icon' => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-4 0a1 1 0 01-1-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 01-1 1', 'label' => 'پیشخوان'],
                ['url' => 'students.php', 'icon' => 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z', 'label' => 'دانش‌آموزان'],
                ['url' => 'upload.php', 'icon' => 'M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5', 'label' => 'بارگذاری اکسل'],
                ['url' => 'payment.php', 'icon' => 'M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z', 'label' => 'ثبت پرداخت'],
                ['url' => 'discounts.php', 'icon' => 'M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z', 'label' => 'تخفیف‌ها'],
                ['url' => 'reports.php', 'icon' => 'M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z', 'label' => 'گزارش‌ها'],
                ['url' => 'duplicates.php', 'icon' => 'M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z', 'label' => 'تشخیص مشابهت'],
                ['url' => 'receipt_list.php', 'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z', 'label' => 'رسید پرداخت'],
                ['url' => 'settings.php', 'icon' => 'M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z', 'label' => 'تنظیمات'],
                ['url' => 'backup.php', 'icon' => 'M4 7v14a2 2 0 002 2h12a2 2 0 002-2V7', 'label' => 'بک‌آپ سیستم'],
            ];
            foreach ($menu_items as $item):
                $is_active = $current_page === $item['url'];
                $base_classes = "flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium transition-all duration-200 btn-pulse";
                $active_classes = "bg-indigo-50 text-indigo-700 shadow-sm";
                $inactive_classes = "text-slate-600 hover:bg-slate-50 hover:text-slate-900";
            ?>
                <a href="<?= e($item['url']) ?>" class="<?= $base_classes ?> <?= $is_active ? $active_classes : $inactive_classes ?>">
                    <svg class="w-5 h-5 flex-shrink-0 <?= $is_active ? 'text-indigo-600' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="<?= $item['icon'] ?>"/>
                    </svg>
                    <?= $item['label'] ?>
                    <?php if ($is_active): ?>
                        <span class="mr-auto w-1.5 h-6 bg-indigo-600 rounded-full"></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="px-4 py-4 border-t border-slate-100">
            <div class="flex items-center gap-3 px-3 py-3 bg-slate-50 rounded-xl">
                <div class="w-9 h-9 bg-gradient-to-br from-slate-400 to-slate-500 rounded-lg flex items-center justify-center">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/>
                    </svg>
                    
                </div>
                    <div class="px-4 py-2 border-t border-slate-100">
    <a href="logout.php" class="flex items-center gap-2 px-3 py-2 text-sm text-red-600 hover:bg-red-50 rounded-xl transition-colors">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
        خروج
    </a>
</div>
                <div>
                    <p class="text-sm font-medium text-slate-700">مدیر سیستم</p>
                    <p class="text-xs text-slate-400">ادمین</p>
                </div>
            </div>
        </div>
    </aside>

    <!-- Main content -->
    <div class="lg:pr-72 min-h-full">
        <header class="no-print sticky top-0 z-30 bg-white/80 backdrop-blur-md border-b border-slate-200 px-4 sm:px-6 lg:px-8 py-4">
            <div class="flex items-center justify-between">
                <button onclick="toggleSidebar()" class="lg:hidden p-2 -mr-2 text-slate-500 hover:text-slate-700 hover:bg-slate-100 rounded-lg transition-colors">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/></svg>
                </button>
                <div class="flex items-center gap-4">
                    <span class="text-xs text-slate-400 hidden sm:block"><?= gregorian_to_jalali(date('Y-m-d')) ?></span>
                </div>
            </div>
        </header>
        <main class="px-4 sm:px-6 lg:px-8 py-6 animate-fade-in-up">