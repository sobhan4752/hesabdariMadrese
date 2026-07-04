<?php
/**
 * توابع کمکی - نسخه ۲.۲ با پشتیبانی از دو پایگاه داده
 * مسیر: /includes/functions.php
 */
require_once ROOT_PATH . '/vendor/autoload.php';

use Morilog\Jalali\Jalalian;

// ======================== تابع mb_trim ========================
if (!function_exists('mb_trim')) {
    function mb_trim(string $string, string $charlist = " \t\n\r\0\x0B\xC2\xA0"): string {
        return preg_replace('/^[' . preg_quote($charlist, '/') . ']+/u', '', 
               preg_replace('/[' . preg_quote($charlist, '/') . ']+$/u', '', $string));
    }
}

// ======================== ۱. تبدیل تاریخ ========================
function jalali_to_gregorian(?string $jalali_date): ?string {
    if (empty($jalali_date)) return null;
    $jalali_date = str_replace('-', '/', $jalali_date);
    $parts = explode('/', $jalali_date);
    if (count($parts) !== 3) return null;
    try {
        $jalalian = Jalalian::fromFormat('Y/m/d', sprintf('%04d/%02d/%02d', $parts[0], $parts[1], $parts[2]));
        return $jalalian->toCarbon()->format('Y-m-d');
    } catch (\Exception $e) {
        return null;
    }
}

function gregorian_to_jalali(?string $gregorian_date): ?string {
    if (empty($gregorian_date)) return null;
    try {
        $carbon = new \Carbon\Carbon($gregorian_date);
        $jalalian = Jalalian::fromCarbon($carbon);
        return $jalalian->format('Y/m/d');
    } catch (\Exception $e) {
        return null;
    }
}

// ======================== ۲. مبلغ به حروف ========================
function number_to_words(int $number, string $unit = 'rial'): string {
    if ($number == 0) return 'صفر ' . ($unit === 'toman' ? 'تومان' : 'ریال');
    $units = ['', 'یک', 'دو', 'سه', 'چهار', 'پنج', 'شش', 'هفت', 'هشت', 'نه'];
    $teens = ['ده', 'یازده', 'دوازده', 'سیزده', 'چهارده', 'پانزده', 'شانزده', 'هفده', 'هجده', 'نوزده'];
    $tens = ['', '', 'بیست', 'سی', 'چهل', 'پنجاه', 'شصت', 'هفتاد', 'هشتاد', 'نود'];
    $hundreds = ['', 'صد', 'دویست', 'سیصد', 'چهارصد', 'پانصد', 'ششصد', 'هفتصد', 'هشتصد', 'نهصد'];
    $major = ['', 'هزار', 'میلیون', 'میلیارد'];
    $words = ''; $num = $number; $major_index = 0;
    while ($num > 0) {
        $segment = $num % 1000;
        if ($segment > 0) {
            $segment_words = '';
            if ($segment >= 100) { $segment_words .= $hundreds[floor($segment / 100)] . ' '; $segment %= 100; }
            if ($segment >= 20) { $segment_words .= $tens[floor($segment / 10)] . ' '; $segment %= 10; if ($segment > 0) $segment_words .= 'و ' . $units[$segment] . ' '; }
            elseif ($segment >= 10) { $segment_words .= $teens[$segment - 10] . ' '; }
            elseif ($segment > 0) { $segment_words .= $units[$segment] . ' '; }
            $segment_words .= $major[$major_index] . ' ';
            $words = $segment_words . $words;
        }
        $num = floor($num / 1000); $major_index++;
    }
    $suffix = ($unit === 'toman') ? ' تومان' : ' ریال';
    return trim($words) . $suffix;
}

// ======================== ۳. فرمت پول ========================
function format_money(int $rial_amount, string $unit = 'rial'): string {
    if ($unit === 'toman') { $amount = $rial_amount / 10; $suffix = ' تومان'; }
    else { $amount = $rial_amount; $suffix = ' ریال'; }
    return number_format($amount, 0, '.', ',') . $suffix;
}

// ======================== ۴. توابع پایگاه داده (با $pdo_master و $pdo) ========================

/**
 * دریافت تنظیمات از دیتابیس اصلی
 */
function get_setting(string $key, $default = null): mixed {
    global $pdo_master;
    try {
        $stmt = $pdo_master->prepare("SELECT value FROM settings WHERE key = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? $row['value'] : $default;
    } catch (\PDOException $e) { return $default; }
}

/**
 * دریافت شهریه سال (از دیتابیس اصلی)
 */
function get_tuition(int $fiscal_year_id): int {
    global $pdo_master;
    $stmt = $pdo_master->prepare("SELECT tuition_amount FROM fiscal_years WHERE id = ?");
    $stmt->execute([$fiscal_year_id]);
    $row = $stmt->fetch();
    return $row ? (int)$row['tuition_amount'] : 0;
}

/**
 * دریافت لیست دانش‌آموزان با موجودی (از دیتابیس سال جاری)
 */
function get_students_with_balances(int $fiscal_year_id): array {
    global $pdo;
    $tuition = get_tuition($fiscal_year_id);
    $sql = "SELECT s.*,
                   COALESCE(d.total_discount, 0) AS discount,
                   COALESCE(p.total_paid, 0) AS paid
            FROM students s
            LEFT JOIN (SELECT student_id, SUM(amount) AS total_discount FROM student_discounts GROUP BY student_id) d ON s.national_id = d.student_id
            LEFT JOIN (SELECT student_id, SUM(amount) AS total_paid FROM payments GROUP BY student_id) p ON s.national_id = p.student_id
            ORDER BY s.last_name, s.first_name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    $result = ['students' => [], 'balances' => []];
    foreach ($rows as $row) {
        $result['students'][] = $row;
        $result['balances'][$row['national_id']] = [
            'discount' => (int)$row['discount'],
            'paid'     => (int)$row['paid'],
            'balance'  => $tuition - (int)$row['discount'] - (int)$row['paid'],
        ];
    }
    return $result;
}

function calculate_balance(string $student_id, int $fiscal_year_id): int {
    $tuition = get_tuition($fiscal_year_id);
    $discount = (int)get_total_discount($student_id, $fiscal_year_id);
    $payments = (int)get_total_payments($student_id, $fiscal_year_id);
    return $tuition - $discount - $payments;
}

function get_total_discount(string $student_id, int $fiscal_year_id): int {
    global $pdo;
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM student_discounts WHERE student_id = ?");
    $stmt->execute([$student_id]);
    return (int)$stmt->fetchColumn();
}

function get_total_payments(string $student_id, int $fiscal_year_id): int {
    global $pdo;
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE student_id = ?");
    $stmt->execute([$student_id]);
    return (int)$stmt->fetchColumn();
}

// ======================== ۵. سال مالی قبل ========================
function get_previous_fiscal_year_id(): int {
    global $pdo_master;
    $active = get_active_fiscal_year();
    if (!$active) return 0;

    // اگر حداقل یک سال دارای sort_order > 0 باشد، از sort_order استفاده کن
    $has_sort = $pdo_master->query("SELECT COUNT(*) FROM fiscal_years WHERE sort_order > 0")->fetchColumn();
    if ($has_sort > 0) {
        $active_sort = (int)($active['sort_order'] ?? 0);
        if ($active_sort <= 0) return 0;
        $stmt = $pdo_master->prepare("SELECT id FROM fiscal_years WHERE sort_order > 0 AND sort_order < ? ORDER BY sort_order DESC LIMIT 1");
        $stmt->execute([$active_sort]);
    } else {
        // در غیر این صورت، مثل قبل از start_date
        $active_start = $active['start_date'];
        $stmt = $pdo_master->prepare("SELECT id FROM fiscal_years WHERE start_date < ? ORDER BY start_date DESC LIMIT 1");
        $stmt->execute([$active_start]);
    }
    $prev = $stmt->fetch();
    return $prev ? (int)$prev['id'] : 0;
}
/**
 * محاسبه ماندهٔ دانش‌آموز در سال مالی قبل
 */
function get_previous_year_balance(string $student_id): ?int {
    $prev_id = get_previous_fiscal_year_id();
    if ($prev_id === 0) return null;

    $prev_db = PRIVATE_PATH . '/year_' . $prev_id . '.sqlite';
    if (!file_exists($prev_db)) return null;

    try {
        $pdo_prev = new PDO('sqlite:' . $prev_db);
        $pdo_prev->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // ===== بخش اضافه شده =====
        // بررسی وجود دانش‌آموز در سال قبل
        $check = $pdo_prev->prepare("SELECT COUNT(*) FROM students WHERE national_id = ?");
        $check->execute([$student_id]);
        if ($check->fetchColumn() == 0) {
            $pdo_prev = null;
            return null;   // دانش‌آموز در آن سال نبوده → هشدار نشان نده
        }
        // ========================

        $tuition = get_tuition($prev_id);

        $stmt = $pdo_prev->prepare("SELECT COALESCE(SUM(amount), 0) FROM student_discounts WHERE student_id = ?");
        $stmt->execute([$student_id]);
        $discount = (int)$stmt->fetchColumn();

        $stmt = $pdo_prev->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE student_id = ?");
        $stmt->execute([$student_id]);
        $payments = (int)$stmt->fetchColumn();

        $balance = $tuition - $discount - $payments;
        $pdo_prev = null;
        return $balance;
    } catch (\Exception $e) {
        return null;
    }
}
if (!function_exists('normalize_search_term')) {
    function normalize_search_term($str) {
        return str_replace(['ی', 'ک'], ['ي', 'ك'], $str);
    }
}
// ======================== کش هوشمند ترازنامه‌ها ========================
function get_students_with_balances_cached(int $fiscal_year_id): array {
    $cache_dir = PRIVATE_PATH . '/cache';
    if (!is_dir($cache_dir)) {
        mkdir($cache_dir, 0755, true);
    }
    $cache_file = $cache_dir . "/students_balances_{$fiscal_year_id}.json";
    $ttl = 60; // ثانیه – زمان زنده بودن کش

    // اگر فایل کش معتبر باشد
    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $ttl) {
        $data = json_decode(file_get_contents($cache_file), true);
        if ($data !== null && isset($data['students'], $data['balances'])) {
            return $data;
        }
    }

    // در غیر این‌صورت داده‌ها را از پایگاه داده بخوان
    $data = get_students_with_balances($fiscal_year_id);
    file_put_contents($cache_file, json_encode($data, JSON_UNESCAPED_UNICODE));
    return $data;
}

function invalidate_student_balances_cache(int $fiscal_year_id): void {
    $cache_file = PRIVATE_PATH . "/cache/students_balances_{$fiscal_year_id}.json";
    if (file_exists($cache_file)) {
        unlink($cache_file);
    }
}
if (!function_exists('persian_alphabet_order')) {
    function persian_alphabet_order($str) {
        $alphabet = ['ا'=>1,'آ'=>1,'ب'=>2,'پ'=>3,'ت'=>4,'ث'=>5,'ج'=>6,'چ'=>7,'ح'=>8,'خ'=>9,'د'=>10,'ذ'=>11,'ر'=>12,'ز'=>13,'ژ'=>14,'س'=>15,'ش'=>16,'ص'=>17,'ض'=>18,'ط'=>19,'ظ'=>20,'ع'=>21,'غ'=>22,'ف'=>23,'ق'=>24,'ک'=>25,'گ'=>26,'ل'=>27,'م'=>28,'ن'=>29,'و'=>30,'ه'=>31,'ی'=>32];
        $key = '';
        $len = mb_strlen($str, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $char = mb_substr($str, $i, 1, 'UTF-8');
            $key .= isset($alphabet[$char]) ? str_pad($alphabet[$char], 3, '0', STR_PAD_LEFT) : '999';
        }
        return $key;
    }
}