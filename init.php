<?php
/**
 * هسته مرکزی سیستم - نسخه ۲.۲ با پایگاه داده مجزا برای هر سال مالی
 * مسیر: /includes/init.php
 */
define('ROOT_PATH', dirname(__DIR__));
define('PRIVATE_PATH', '/home/xsmdyryt/privates'); // مسیر مطلق هاست
define('INCLUDES_PATH', ROOT_PATH . '/includes');
define('ASSETS_PATH', ROOT_PATH . '/assets');

// تشخیص خودکار BASE_URL
$script_dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
define('BASE_URL', $script_dir . '/');
define('ASSETS_URL', BASE_URL . 'assets/');

// ثابت نسخه
define('ASSETS_VERSION', '2.2.0');

// تنظیم محیط
define('ENVIRONMENT', 'production'); // development | production
if (ENVIRONMENT === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// شروع سشن امن
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 1 : 0);
    ini_set('session.use_only_cookies', 1);
    session_start();
}

// ================== اتصال به پایگاه داده اصلی (تنظیمات و سال‌های مالی) ==================
try {
    $master_db = PRIVATE_PATH . '/school_finance.sqlite';
    if (!file_exists($master_db) && basename($_SERVER['SCRIPT_NAME']) !== 'install.php') {
        die('پایگاه داده اصلی یافت نشد. لطفاً ابتدا نصب را اجرا کنید: <a href="' . BASE_URL . 'install.php">نصب</a>');
    }
    $pdo_master = new PDO('sqlite:' . $master_db);
    $pdo_master->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo_master->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo_master->exec('PRAGMA journal_mode = WAL');
    $pdo_master->exec('PRAGMA foreign_keys = ON');
    $pdo_master->exec('PRAGMA busy_timeout = 5000');

    // بررسی و افزودن ستون sort_order به fiscal_years
    $col_check = $pdo_master->query("PRAGMA table_info(fiscal_years)")->fetchAll(PDO::FETCH_ASSOC);
    $has_sort = false;
    foreach ($col_check as $col) {
        if ($col['name'] === 'sort_order') { $has_sort = true; break; }
    }
    if (!$has_sort) {
        $pdo_master->exec("ALTER TABLE fiscal_years ADD COLUMN sort_order INTEGER DEFAULT 0");
    }
} catch (PDOException $e) {
    if (ENVIRONMENT === 'development') {
        die('خطای اتصال به پایگاه داده اصلی: ' . $e->getMessage());
    } else {
        die('خطای سیستمی. لطفاً با مدیر فنی تماس بگیرید.');
    }
}

// ================== تعیین سال مالی فعال ==================
function get_active_fiscal_year_id(): int {
    global $pdo_master;
    $active = get_setting_from_master('active_fiscal_year', 0);
    if ($active > 0) {
        $stmt = $pdo_master->prepare("SELECT id FROM fiscal_years WHERE id = ?");
        $stmt->execute([$active]);
        if ($stmt->fetch()) return (int)$active;
    }
    // در صورت نبودن، اولین سال باز
    $stmt = $pdo_master->query("SELECT id FROM fiscal_years WHERE is_closed = 0 ORDER BY id DESC LIMIT 1");
    $row = $stmt->fetch();
    return $row ? (int)$row['id'] : 0;
}

function get_active_fiscal_year(): array|null {
    global $pdo_master;
    $active_id = get_active_fiscal_year_id();
    if ($active_id > 0) {
        $stmt = $pdo_master->prepare("SELECT * FROM fiscal_years WHERE id = ?");
        $stmt->execute([$active_id]);
        return $stmt->fetch() ?: null;
    }
    return null;
}

/**
 * تابع کمکی برای خواندن تنظیمات از دیتابیس اصلی (بدون نیاز به functions.php)
 */
function get_setting_from_master(string $key, $default = null) {
    global $pdo_master;
    try {
        $stmt = $pdo_master->prepare("SELECT value FROM settings WHERE key = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? $row['value'] : $default;
    } catch (\Exception $e) {
        return $default;
    }
}

$active_fiscal_id = get_active_fiscal_year_id();
$year_db = PRIVATE_PATH . '/year_' . $active_fiscal_id . '.sqlite';

// ================== اتصال به پایگاه داده سال جاری ==================
try {
    $needs_init = !file_exists($year_db);
    $pdo = new PDO('sqlite:' . $year_db);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    if ($needs_init && basename($_SERVER['SCRIPT_NAME']) !== 'install.php') {
        // ایجاد جداول سال جدید
        $pdo->exec("CREATE TABLE IF NOT EXISTS students (
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

        $pdo->exec("CREATE TABLE IF NOT EXISTS student_discounts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id TEXT(10) NOT NULL,
            fiscal_year_id INTEGER NOT NULL,
            amount INTEGER NOT NULL DEFAULT 0,
            description TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (student_id) REFERENCES students(national_id) ON DELETE CASCADE
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS payments (
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

        $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            table_name TEXT NOT NULL,
            record_id INTEGER,
            action TEXT NOT NULL CHECK(action IN ('INSERT','UPDATE','DELETE')),
            old_data TEXT,
            new_data TEXT,
            changed_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        )");

        // ایندکس‌ها
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_payments_student ON payments(student_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_payments_date ON payments(payment_date)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_discounts_student ON student_discounts(student_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_students_class ON students(class_name)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_students_name ON students(last_name, first_name)");
    }
} catch (PDOException $e) {
    if (ENVIRONMENT === 'development') {
        die('خطای اتصال به پایگاه داده سال جاری: ' . $e->getMessage());
    } else {
        die('خطای سیستمی. لطفاً با مدیر فنی تماس بگیرید.');
    }
}

// ================== CSRF Protection ==================
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function csrf_token(): string {
    return $_SESSION['csrf_token'];
}
function csrf_field(): string {
    return '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}
function csrf_verify(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['_csrf_token'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'], $token)) {
            http_response_code(403);
            die('درخواست نامعتبر - CSRF validation failed');
        }
    }
}

// ================== توابع کمکی اولیه ==================
function e(?string $string): string {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}
function redirect(string $url): never {
    header("Location: $url");
    exit;
}
function base_url(string $path = ''): string {
    return BASE_URL . ltrim($path, '/');
}

// ================== مدیریت IP مسدود (برای لاگین) ==================
function is_ip_blocked(): bool {
    global $pdo_master;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    try {
        $stmt = $pdo_master->prepare("SELECT attempts, last_attempt FROM login_attempts WHERE ip = ?");
        $stmt->execute([$ip]);
        $row = $stmt->fetch();
        if (!$row) return false;
        $last = strtotime($row['last_attempt']);
        if (time() - $last > 300) {
            $pdo_master->prepare("DELETE FROM login_attempts WHERE ip = ?")->execute([$ip]);
            return false;
        }
        return (int)$row['attempts'] >= 3;
    } catch (\Exception $e) {
        return false;
    }
}

function record_failed_login(): void {
    global $pdo_master;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    try {
        $stmt = $pdo_master->prepare("INSERT INTO login_attempts (ip, attempts, last_attempt) VALUES (?, 1, datetime('now','localtime'))
                                       ON CONFLICT(ip) DO UPDATE SET attempts = attempts + 1, last_attempt = datetime('now','localtime')");
        $stmt->execute([$ip]);
    } catch (\Exception $e) {}
}

function clear_login_attempts(): void {
    global $pdo_master;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    try {
        $pdo_master->prepare("DELETE FROM login_attempts WHERE ip = ?")->execute([$ip]);
    } catch (\Exception $e) {}
}

// ================== بررسی لاگین ==================
$current_page = basename($_SERVER['SCRIPT_NAME']);
if (!in_array($current_page, ['login.php', 'install.php']) && (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true)) {
    redirect('login.php');
}