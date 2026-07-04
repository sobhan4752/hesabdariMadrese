<?php
/**
 * صفحه ورود ادمین – نسخه نهایی با ظاهر Tailwind
 * مسیر: /login.php
 */
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/functions.php';

// اگر قبلاً وارد شده، به پیشخوان برو
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    redirect('index.php');
}

$error = '';
$admin_password_hash = get_setting('admin_password', '');
$captcha_enabled = get_setting('login_captcha', 'enabled') === 'enabled';

// اگر رمز عبوری تنظیم نشده، یک رمز پیش‌فرض ایجاد کن
if (empty($admin_password_hash)) {
    $default_pass = password_hash('admin123', PASSWORD_DEFAULT);
    $stmt = $pdo_master->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)");
    $stmt->execute(['admin_password', $default_pass]);
    $admin_password_hash = $default_pass;
}

// ======================== تولید کپچای جدید (فقط برای GET) ========================
if ($captcha_enabled && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $captcha_a = rand(1, 9);
    $captcha_b = rand(1, 9);
    $_SESSION['captcha_answer'] = $captcha_a + $captcha_b;
} elseif (!$captcha_enabled) {
    $captcha_a = 0;
    $captcha_b = 0;
}

// بررسی مسدود بودن IP
if (is_ip_blocked()) {
    $error = 'دسترسی شما به دلیل تلاش‌های ناموفق برای ۵ دقیقه مسدود شده است. لطفاً بعداً تلاش کنید یا از فایل رفع انسداد استفاده نمایید.';
}

// ======================== پردازش ورود ========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !is_ip_blocked()) {
    $pass = $_POST['password'] ?? '';
    $captcha_input = (int)($_POST['captcha'] ?? 0);

    // اعتبارسنجی کپچا
    if ($captcha_enabled) {
        $expected_answer = $_SESSION['captcha_answer'] ?? -1;
        if ($captcha_input !== $expected_answer) {
            $error = 'پاسخ کپچا نادرست است. لطفاً دقت کنید.';
        }
    }

    // اگر کپچا درست بود، رمز عبور چک شود
    if (empty($error)) {
        if (password_verify($pass, $admin_password_hash)) {
            clear_login_attempts();
            $_SESSION['admin_logged_in'] = true;
            redirect('index.php');
        } else {
            record_failed_login();
            $error = 'رمز عبور نادرست است.';
        }
    }

    // بعد از پردازش، کپچای جدید برای دفعهٔ بعد
    if ($captcha_enabled) {
        $captcha_a = rand(1, 9);
        $captcha_b = rand(1, 9);
        $_SESSION['captcha_answer'] = $captcha_a + $captcha_b;
    }
}

$school_name = get_setting('school_name', 'سیستم حسابداری');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ورود مدیر - <?= e($school_name) ?></title>
    <link rel="stylesheet" href="assets/css/tailwind.min.css?v=2.2.0">
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-sm">
        <div class="bg-white rounded-2xl shadow-xl border border-slate-200 p-8">
            <h2 class="text-2xl font-extrabold text-slate-800 text-center mb-6">ورود مدیر</h2>
            <?php if ($error): ?>
                <div class="bg-red-50 border border-red-200 rounded-lg p-3 mb-4 text-sm text-red-800"><?= e($error) ?></div>
            <?php endif; ?>
            <form method="post" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">رمز عبور</label>
                    <input type="password" name="password" required autocomplete="current-password"
                           class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400 outline-none">
                </div>
                <?php if ($captcha_enabled && !is_ip_blocked()): ?>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">
                            حاصل عبارت زیر را وارد کنید:
                            <span class="font-bold text-indigo-600"><?= $captcha_a ?> + <?= $captcha_b ?> = ؟</span>
                        </label>
                        <input type="number" name="captcha" required class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400 outline-none" placeholder="پاسخ">
                    </div>
                <?php endif; ?>
                <button type="submit" class="w-full py-3 bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-xl transition-colors">ورود</button>
            </form>
        </div>
    </div>
</body>
</html>