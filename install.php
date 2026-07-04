<?php
/**
 * نصب پایگاه داده – نسخهٔ کاملاً مستقل
 * مسیر: /install.php
 */
define('ROOT_PATH', dirname(__DIR__));
define('PRIVATE_PATH', '/home/xsmdyryt/privates');

// ایجاد پوشه خصوصی اگر وجود ندارد
if (!is_dir(PRIVATE_PATH)) {
    mkdir(PRIVATE_PATH, 0755, true);
}

$master_db = PRIVATE_PATH . '/school_finance.sqlite';
$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$errors = [];
$success = [];

// تابع کمکی برای خروجی
function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

if ($step === 2) {
    try {
        // اتصال به پایگاه داده اصلی (اگر وجود نداشته باشد ساخته می‌شود)
        $pdo_master = new PDO('sqlite:' . $master_db);
        $pdo_master->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo_master->exec('PRAGMA foreign_keys = OFF');

        // جداول اصلی
        $pdo_master->exec("CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT
        )");
        $pdo_master->exec("CREATE TABLE IF NOT EXISTS fiscal_years (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            start_date TEXT,
            end_date TEXT,
            tuition_amount INTEGER NOT NULL DEFAULT 0,
            is_closed INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        )");
        $pdo_master->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            ip TEXT PRIMARY KEY,
            attempts INTEGER NOT NULL DEFAULT 1,
            last_attempt TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        )");

        // تنظیمات پیش‌فرض
        $stmt = $pdo_master->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)");
        $stmt->execute(['currency_unit', 'rial']);
        $stmt->execute(['school_name', 'دبیرستان نمونه']);
        $stmt->execute(['system_version', '2.2.0']);
        $stmt->execute(['valid_years', '["1404","1405"]']);
        $stmt->execute(['date_input_method', 'dropdown']);
        $stmt->execute(['login_captcha', 'enabled']);
        $stmt->execute(['class_grade_mapping', '{"101":"هفتم","102":"هشتم","103":"نهم"}']);

        // ایجاد سال مالی پیش‌فرض (اختیاری)
        $fy_check = $pdo_master->query("SELECT COUNT(*) FROM fiscal_years")->fetchColumn();
        if ($fy_check == 0) {
            $stmt = $pdo_master->prepare("INSERT INTO fiscal_years (name, start_date, end_date, tuition_amount) VALUES (?, ?, ?, ?)");
            $stmt->execute(['۱۴۰۴-۱۴۰۵', '1404/07/01', '1405/06/31', 230000000]);
            $active_fy_id = $pdo_master->lastInsertId();
            $stmt->execute(['active_fiscal_year', $active_fy_id]);
        }

        // ایجاد دیتابیس سال جاری (اگر active_fiscal_year تنظیم شده)
        $active_id = $pdo_master->query("SELECT value FROM settings WHERE key = 'active_fiscal_year'")->fetchColumn();
        if ($active_id) {
            $year_db = PRIVATE_PATH . '/year_' . $active_id . '.sqlite';
            if (!file_exists($year_db)) {
                $pdo_year = new PDO('sqlite:' . $year_db);
                $pdo_year->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                
                $pdo_year->exec("CREATE TABLE IF NOT EXISTS students (
                    national_id TEXT(10) PRIMARY KEY,
                    first_name TEXT NOT NULL,
                    last_name TEXT NOT NULL,
                    father_name TEXT,
                    birth_date TEXT,
                    birth_cert_serial TEXT,
                    birth_cert_digit TEXT,
                    birth_cert_letter TEXT,
                    issuing_place TEXT,
                    gender TEXT DEFAULT 'پسر',
                    class_name TEXT NOT NULL DEFAULT '',
                    mobile TEXT,
                    phone TEXT,
                    address TEXT,
                    postal_code TEXT,
                    ledger_number TEXT,
                    transfer_dropout INTEGER DEFAULT 0,
                    deceased INTEGER DEFAULT 0,
                    row_index INTEGER,
                    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
                )");

                $pdo_year->exec("CREATE TABLE IF NOT EXISTS student_discounts (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    student_id TEXT(10) NOT NULL,
                    fiscal_year_id INTEGER NOT NULL,
                    amount INTEGER NOT NULL DEFAULT 0,
                    description TEXT,
                    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
                    FOREIGN KEY (student_id) REFERENCES students(national_id) ON DELETE CASCADE
                )");

                $pdo_year->exec("CREATE TABLE IF NOT EXISTS payments (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    student_id TEXT(10) NOT NULL,
                    fiscal_year_id INTEGER NOT NULL,
                    amount INTEGER NOT NULL,
                    payment_method TEXT NOT NULL CHECK(payment_method IN ('cash','card','transfer')),
                    card_last4 TEXT(4),
                    payment_date TEXT NOT NULL,
                    notes TEXT,
                    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
                    FOREIGN KEY (student_id) REFERENCES students(national_id) ON DELETE CASCADE
                )");

                $pdo_year->exec("CREATE TABLE IF NOT EXISTS audit_log (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    table_name TEXT NOT NULL,
                    record_id INTEGER,
                    action TEXT NOT NULL CHECK(action IN ('INSERT','UPDATE','DELETE')),
                    old_data TEXT,
                    new_data TEXT,
                    changed_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
                )");

                $pdo_year->exec("CREATE INDEX IF NOT EXISTS idx_payments_student ON payments(student_id)");
                $pdo_year->exec("CREATE INDEX IF NOT EXISTS idx_payments_date ON payments(payment_date)");
                $pdo_year->exec("CREATE INDEX IF NOT EXISTS idx_discounts_student ON student_discounts(student_id)");
                $pdo_year->exec("CREATE INDEX IF NOT EXISTS idx_students_class ON students(class_name)");
                $pdo_year->exec("CREATE INDEX IF NOT EXISTS idx_students_name ON students(last_name, first_name)");

                $pdo_year = null;
            }
        }

        $success[] = 'پایگاه داده با موفقیت ایجاد شد.';
    } catch (PDOException $e) {
        $errors[] = 'خطا: ' . $e->getMessage();
    }
}

// ======================== خروجی HTML ========================
$db_exists = file_exists($master_db);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>نصب سیستم حسابداری شهریه</title>
    <style>
        body { font-family: 'Vazirmatn', sans-serif; background: #f1f5f9; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .card { background: white; border-radius: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); width: 100%; max-width: 500px; overflow: hidden; }
        .header { background: linear-gradient(135deg, #4f46e5, #6366f1); padding: 24px; color: white; }
        .content { padding: 24px; }
        .btn { display: block; width: 100%; background: #4f46e5; color: white; text-align: center; padding: 14px; border-radius: 12px; text-decoration: none; font-weight: bold; transition: background 0.2s; }
        .btn:hover { background: #4338ca; }
        .alert { padding: 12px; border-radius: 8px; margin-bottom: 16px; }
        .alert-error { background: #fee2e2; color: #991b1b; }
        .alert-success { background: #d1fae5; color: #065f46; }
    </style>
</head>
<body>
    <div class="card">
        <div class="header">
            <h1 style="margin:0; font-size: 1.5rem;">نصب سیستم حسابداری شهریه</h1>
            <p style="margin:4px 0 0; opacity:0.8;">نسخه ۲.۲.۰</p>
        </div>
        <div class="content">
            <?php if ($step === 1): ?>
                <p style="color: #475569; margin-bottom: 20px;">پایگاه داده اصلی و جداول سال جاری ایجاد خواهند شد.</p>
                <a href="?step=2" class="btn">شروع نصب</a>
            <?php else: ?>
                <?php if ($errors): ?>
                    <div class="alert alert-error">
                        <?php foreach ($errors as $e): ?><p style="margin:0;"><?= h($e) ?></p><?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <?php foreach ($success as $s): ?><p style="margin:0;"><?= h($s) ?></p><?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <a href="/calc/index.php" class="btn">ورود به سیستم</a>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>