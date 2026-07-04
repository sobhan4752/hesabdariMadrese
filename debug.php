<?php
/**
 * صفحهٔ دیباگ و سلامت سیستم
 * مسیر: /debug.php
 */
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/functions.php';

$page_title = 'دیباگ سیستم';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>دیباگ سیستم</title>
    <style>
        body { font-family: Tahoma; direction: rtl; padding: 20px; background: #f5f5f5; }
        .card { background: white; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .ok { color: green; } .err { color: red; } .info { color: blue; }
        table { border-collapse: collapse; width: 100%; }
        td, th { padding: 6px 10px; border: 1px solid #ddd; text-align: right; }
        th { background: #eee; }
    </style>
</head>
<body>
<h1>🛠️ دیباگ سیستم</h1>

<div class="card">
    <h3>اطلاعات سرور</h3>
    <p>نسخهٔ PHP: <b><?= phpversion() ?></b></p>
    <p>حافظهٔ مجاز: <b><?= ini_get('memory_limit') ?></b></p>
    <p>حداکثر زمان اجرا: <b><?= ini_get('max_execution_time') ?> ثانیه</b></p>
</div>

<div class="card">
    <h3>اتصال به پایگاه داده</h3>
    <?php
    try {
        $pdo_master->query("SELECT 1");
        echo "<p class='ok'>✅ پایگاه دادهٔ اصلی (master) متصل است.</p>";
    } catch (\Exception $e) {
        echo "<p class='err'>❌ خطا در اتصال به پایگاه دادهٔ اصلی: {$e->getMessage()}</p>";
    }
    try {
        $active_id = get_active_fiscal_year_id();
        echo "<p>سال مالی فعال: <b>$active_id</b></p>";
        if ($active_id > 0) {
            $pdo->query("SELECT 1");
            echo "<p class='ok'>✅ پایگاه دادهٔ سال جاری (year_$active_id) متصل است.</p>";
        } else {
            echo "<p class='err'>❌ سال مالی فعالی وجود ندارد.</p>";
        }
    } catch (\Exception $e) {
        echo "<p class='err'>❌ خطا در اتصال به پایگاه دادهٔ سال جاری: {$e->getMessage()}</p>";
    }
    ?>
</div>

<div class="card">
    <h3>آمار کلی</h3>
    <?php
    try {
        $total_stu = $pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
        $total_pay = $pdo->query("SELECT COUNT(*) FROM payments")->fetchColumn();
        $total_disc = $pdo->query("SELECT COUNT(*) FROM student_discounts")->fetchColumn();
        echo "<p>تعداد دانش‌آموزان: <b>$total_stu</b></p>";
        echo "<p>تعداد پرداخت‌ها: <b>$total_pay</b></p>";
        echo "<p>تعداد تخفیف‌ها: <b>$total_disc</b></p>";
    } catch (\Exception $e) {
        echo "<p class='err'>خطا در خواندن آمار: {$e->getMessage()}</p>";
    }
    ?>
</div>

<div class="card">
    <h3>وضعیت کش</h3>
    <?php
    $cache_dir = PRIVATE_PATH . '/cache';
    if (!is_dir($cache_dir)) {
        echo "<p class='err'>❌ پوشهٔ cache وجود ندارد.</p>";
    } else {
        $files = glob($cache_dir . '/*.json');
        if (empty($files)) {
            echo "<p>کشی موجود نیست.</p>";
        } else {
            echo "<table><tr><th>فایل</th><th>آخرین بروزرسانی</th></tr>";
            foreach ($files as $f) {
                $name = basename($f);
                $time = date('Y/m/d H:i:s', filemtime($f));
                echo "<tr><td>$name</td><td>$time</td></tr>";
            }
            echo "</table>";
        }
    }
    ?>
</div>

<div class="card">
    <h3>توابع ضروری</h3>
    <?php
    $funcs = ['e', 'format_money', 'normalize_search_term', 'get_tuition', 'get_total_payments', 'persian_alphabet_order', 'get_students_with_balances_cached'];
    foreach ($funcs as $fn) {
        if (function_exists($fn)) {
            echo "<p class='ok'>✅ $fn()</p>";
        } else {
            echo "<p class='err'>❌ $fn() تعریف نشده</p>";
        }
    }
    ?>
</div>
</body>
</html>