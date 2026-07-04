<?php
/**
 * تنظیمات – نسخه ۲.۴ با امکان حذف سال مالی
 * مسیر: /settings.php
 */
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/functions.php';

csrf_verify();

$page_title = 'تنظیمات';
$errors = [];
$success = [];
$tab = $_GET['tab'] ?? 'general';
$unit = get_setting('currency_unit', 'rial');

// ========= حذف سال مالی =========
if (isset($_GET['delete_fy']) && is_numeric($_GET['delete_fy'])) {
    $delete_id = (int)$_GET['delete_fy'];
    $active = get_active_fiscal_year_id();
    if ($delete_id === $active) {
        $errors[] = 'نمی‌توانید سال مالی فعال را حذف کنید. ابتدا سال فعال را تغییر دهید.';
    } else {
        try {
            // حذف از جدول اصلی
            $stmt = $pdo_master->prepare("DELETE FROM fiscal_years WHERE id = ?");
            $stmt->execute([$delete_id]);

            // حذف فایل دیتابیس سال
            $year_db = PRIVATE_PATH . '/year_' . $delete_id . '.sqlite';
            if (file_exists($year_db)) {
                unlink($year_db);
            }
            $success[] = 'سال مالی و پایگاه دادهٔ آن با موفقیت حذف شدند.';
        } catch (\Exception $e) {
            $errors[] = 'خطا در حذف: ' . $e->getMessage();
        }
    }
}

// ========= ذخیره تنظیمات عمومی =========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    try {
        $pdo_master->beginTransaction();
        $stmt = $pdo_master->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)");
        $stmt->execute(['school_name', $_POST['school_name'] ?? '']);
        $stmt->execute(['currency_unit', $_POST['currency_unit'] ?? 'rial']);
        $stmt->execute(['date_input_method', $_POST['date_input_method'] ?? 'dropdown']);
        $stmt->execute(['valid_years', $_POST['valid_years'] ?? '["1404","1405"]']);
        $stmt->execute(['login_captcha', $_POST['login_captcha'] ?? 'enabled']);
        $stmt->execute(['check_previous_balance', $_POST['check_previous_balance'] ?? 'disabled']);
        $stmt->execute(['payment_notes_enabled', $_POST['payment_notes_enabled'] ?? 'enabled']);
        $new_password = $_POST['new_password'] ?? '';
        if (!empty($new_password)) {
            $hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt->execute(['admin_password', $hash]);
            $success[] = 'رمز عبور با موفقیت تغییر کرد.';
        }

        $pdo_master->commit();
        $success[] = 'تنظیمات عمومی ذخیره شد.';
    } catch (\Exception $e) {
        $pdo_master->rollBack();
        $errors[] = 'خطا: ' . $e->getMessage();
    }
}

// ========= ذخیره نگاشت کلاس به پایه =========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_mapping'])) {
    $mapping_json = $_POST['class_grade_mapping'] ?? '';
    $mapping = json_decode($mapping_json, true);
    if ($mapping === null) {
        $errors[] = 'فرمت JSON نامعتبر است.';
    } else {
        try {
            $stmt = $pdo_master->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)");
            $stmt->execute(['class_grade_mapping', $mapping_json]);
            $success[] = 'نگاشت کلاس به پایه ذخیره شد.';
        } catch (\Exception $e) {
            $errors[] = 'خطا: ' . $e->getMessage();
        }
    }
}

// ========= ذخیره سال مالی فعال =========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_active_fiscal'])) {
    $active_id = (int)($_POST['active_fiscal_year'] ?? 0);
    if ($active_id > 0) {
        $year_db = PRIVATE_PATH . '/year_' . $active_id . '.sqlite';
        if (!file_exists($year_db)) {
            try {
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
            } catch (\Exception $e) {
                $errors[] = 'خطا در ایجاد پایگاه داده سال جدید: ' . $e->getMessage();
            }
        }
        if (empty($errors)) {
            $stmt = $pdo_master->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)");
            $stmt->execute(['active_fiscal_year', $active_id]);
            $success[] = 'سال مالی فعال با موفقیت تغییر کرد.';
        }
    }
}

// ========= ذخیره سال مالی (با sort_order) =========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_fiscal_year'])) {
    $fy_id = (int)($_POST['fy_id'] ?? 0);
    $fy_name = trim($_POST['fy_name'] ?? '');
    $fy_start = trim($_POST['fy_start'] ?? '');
    $fy_end = trim($_POST['fy_end'] ?? '');
    $fy_tuition = (int)str_replace(',', '', $_POST['fy_tuition'] ?? '0');
    $fy_closed = isset($_POST['fy_closed']) ? 1 : 0;
    $fy_sort_order = (int)($_POST['fy_sort_order'] ?? 0);

    if (empty($fy_name)) $errors[] = 'نام سال مالی الزامی است.';
    if ($fy_tuition <= 0) $errors[] = 'شهریه باید بزرگتر از صفر باشد.';

    if (empty($errors)) {
        try {
            if ($fy_id > 0) {
                $stmt = $pdo_master->prepare("UPDATE fiscal_years SET name=?, start_date=?, end_date=?, tuition_amount=?, is_closed=?, sort_order=? WHERE id=?");
                $stmt->execute([$fy_name, $fy_start, $fy_end, $fy_tuition, $fy_closed, $fy_sort_order, $fy_id]);
                $new_fy_id = $fy_id;
            } else {
                $stmt = $pdo_master->prepare("INSERT INTO fiscal_years (name, start_date, end_date, tuition_amount, is_closed, sort_order) VALUES (?,?,?,?,?,?)");
                $stmt->execute([$fy_name, $fy_start, $fy_end, $fy_tuition, $fy_closed, $fy_sort_order]);
                $new_fy_id = (int)$pdo_master->lastInsertId();
            }

            // ایجاد خودکار دیتابیس سال جدید
            $year_db = PRIVATE_PATH . '/year_' . $new_fy_id . '.sqlite';
            if (!file_exists($year_db)) {
                $pdo_year = new PDO('sqlite:' . $year_db);
                $pdo_year->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo_year->exec("CREATE TABLE IF NOT EXISTS students ( national_id TEXT(10) PRIMARY KEY, first_name TEXT NOT NULL, last_name TEXT NOT NULL, father_name TEXT, birth_date TEXT, birth_cert_serial TEXT, birth_cert_digit TEXT, birth_cert_letter TEXT, issuing_place TEXT, gender TEXT DEFAULT 'پسر', class_name TEXT NOT NULL DEFAULT '', mobile TEXT, phone TEXT, address TEXT, postal_code TEXT, ledger_number TEXT, transfer_dropout INTEGER DEFAULT 0, deceased INTEGER DEFAULT 0, row_index INTEGER, created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')) )");
                $pdo_year->exec("CREATE TABLE IF NOT EXISTS student_discounts ( id INTEGER PRIMARY KEY AUTOINCREMENT, student_id TEXT(10) NOT NULL, fiscal_year_id INTEGER NOT NULL, amount INTEGER NOT NULL DEFAULT 0, description TEXT, created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')), FOREIGN KEY (student_id) REFERENCES students(national_id) ON DELETE CASCADE )");
                $pdo_year->exec("CREATE TABLE IF NOT EXISTS payments ( id INTEGER PRIMARY KEY AUTOINCREMENT, student_id TEXT(10) NOT NULL, fiscal_year_id INTEGER NOT NULL, amount INTEGER NOT NULL, payment_method TEXT NOT NULL CHECK(payment_method IN ('cash','card','transfer')), card_last4 TEXT(4), payment_date TEXT NOT NULL, notes TEXT, created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')), FOREIGN KEY (student_id) REFERENCES students(national_id) ON DELETE CASCADE )");
                $pdo_year->exec("CREATE TABLE IF NOT EXISTS audit_log ( id INTEGER PRIMARY KEY AUTOINCREMENT, table_name TEXT NOT NULL, record_id INTEGER, action TEXT NOT NULL CHECK(action IN ('INSERT','UPDATE','DELETE')), old_data TEXT, new_data TEXT, changed_at TEXT NOT NULL DEFAULT (datetime('now','localtime')) )");
                $pdo_year->exec("CREATE INDEX IF NOT EXISTS idx_payments_student ON payments(student_id)");
                $pdo_year->exec("CREATE INDEX IF NOT EXISTS idx_payments_date ON payments(payment_date)");
                $pdo_year->exec("CREATE INDEX IF NOT EXISTS idx_discounts_student ON student_discounts(student_id)");
                $pdo_year->exec("CREATE INDEX IF NOT EXISTS idx_students_class ON students(class_name)");
                $pdo_year->exec("CREATE INDEX IF NOT EXISTS idx_students_name ON students(last_name, first_name)");
                $pdo_year = null;
                $success[] = 'سال مالی جدید ایجاد شد و پایگاه دادهٔ آن نیز ساخته شد.';
            } else {
                $success[] = 'سال مالی با موفقیت ذخیره شد.';
            }
        } catch (\Exception $e) {
            $errors[] = 'خطا: ' . $e->getMessage();
        }
    }
}

$fiscal_years = $pdo_master->query("SELECT * FROM fiscal_years ORDER BY sort_order ASC, id ASC")->fetchAll();
$school_name = get_setting('school_name', '');
$currency_unit = get_setting('currency_unit', 'rial');
$date_method = get_setting('date_input_method', 'dropdown');
$valid_years_str = get_setting('valid_years', '["1404","1405"]');
$class_mapping = get_setting('class_grade_mapping', '{"101":"هفتم","102":"هشتم","103":"نهم"}');
$active_fiscal = get_active_fiscal_year_id();
$captcha_enabled = get_setting('login_captcha', 'enabled') === 'enabled';
$check_previous_balance = get_setting('check_previous_balance', 'enabled');

$edit_fy = null;
if (isset($_GET['edit_fy'])) {
    $stmt = $pdo_master->prepare("SELECT * FROM fiscal_years WHERE id = ?");
    $stmt->execute([(int)$_GET['edit_fy']]);
    $edit_fy = $stmt->fetch();
}

include INCLUDES_PATH . '/header.php';
?>

<div class="max-w-5xl mx-auto">
    <div class="mb-8">
        <h1 class="text-3xl font-extrabold text-slate-800">تنظیمات</h1>
        <p class="text-slate-500 mt-2">مدیریت سال‌های مالی، شهریه پایه، تنظیمات عمومی و نگاشت کلاس‌ها.</p>
    </div>

    <?php if ($errors): ?><div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-6"><?php foreach ($errors as $e): ?><p class="text-red-800 text-sm"><?= e($e) ?></p><?php endforeach; ?></div><?php endif; ?>
    <?php if ($success): ?><div class="bg-emerald-50 border border-emerald-200 rounded-xl p-4 mb-6"><?php foreach ($success as $s): ?><p class="text-emerald-800 text-sm"><?= e($s) ?></p><?php endforeach; ?></div><?php endif; ?>

    <div class="flex flex-wrap gap-1 mb-6 bg-slate-100 rounded-xl p-1 w-fit">
        <a href="?tab=general" class="px-5 py-2.5 rounded-lg text-sm font-medium transition-all <?= $tab === 'general' ? 'bg-white shadow text-indigo-700' : 'text-slate-600 hover:text-slate-800' ?>">عمومی</a>
        <a href="?tab=active_fiscal" class="px-5 py-2.5 rounded-lg text-sm font-medium transition-all <?= $tab === 'active_fiscal' ? 'bg-white shadow text-indigo-700' : 'text-slate-600 hover:text-slate-800' ?>">سال فعال</a>
        <a href="?tab=mapping" class="px-5 py-2.5 rounded-lg text-sm font-medium transition-all <?= $tab === 'mapping' ? 'bg-white shadow text-indigo-700' : 'text-slate-600 hover:text-slate-800' ?>">نگاشت کلاس‌ها</a>
        <a href="?tab=fiscal" class="px-5 py-2.5 rounded-lg text-sm font-medium transition-all <?= $tab === 'fiscal' ? 'bg-white shadow text-indigo-700' : 'text-slate-600 hover:text-slate-800' ?>">سال‌های مالی</a>
    </div>

    <?php if ($tab === 'general'): ?>
        <div class="bg-white rounded-2xl border border-slate-200 p-6">
            <form method="post" class="space-y-5">
                <?= csrf_field() ?>
                <div><label class="block text-sm font-semibold text-slate-700 mb-2">نام مدرسه</label><input type="text" name="school_name" value="<?= e($school_name) ?>" class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400 outline-none"></div>
                <div><label class="block text-sm font-semibold text-slate-700 mb-2">واحد پولی پیش‌فرض</label><div class="flex gap-4"><label class="flex items-center gap-2 cursor-pointer"><input type="radio" name="currency_unit" value="rial" <?= $currency_unit === 'rial' ? 'checked' : '' ?> class="accent-indigo-600"><span class="text-sm">ریال</span></label><label class="flex items-center gap-2 cursor-pointer"><input type="radio" name="currency_unit" value="toman" <?= $currency_unit === 'toman' ? 'checked' : '' ?> class="accent-indigo-600"><span class="text-sm">تومان</span></label></div></div>
                <div><label class="block text-sm font-semibold text-slate-700 mb-2">روش ورود تاریخ</label><div class="flex flex-wrap gap-4"><?php foreach (['picker'=>'تقویم بصری','dropdown'=>'سه منوی کشویی','manual'=>'دستی (یک فیلد)','manual_separate'=>'دستی (سه فیلد جدا)'] as $val=>$label): ?><label class="flex items-center gap-2 cursor-pointer"><input type="radio" name="date_input_method" value="<?=$val?>" <?= $date_method===$val?'checked':'' ?> class="accent-indigo-600"><span class="text-sm"><?=$label?></span></label><?php endforeach; ?></div></div>
                <div><label class="block text-sm font-semibold text-slate-700 mb-2">سال‌های مجاز (JSON)</label><textarea name="valid_years" rows="2" class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400 outline-none font-mono text-sm"><?= e($valid_years_str) ?></textarea><p class="text-xs text-slate-400 mt-1">مثال: ["1404","1405"]</p></div>
                <div><label class="block text-sm font-semibold text-slate-700 mb-2">رمز عبور جدید (خالی بگذارید تا تغییر نکند)</label><input type="password" name="new_password" class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400 outline-none"></div>
                <div><label class="block text-sm font-semibold text-slate-700 mb-2">کپچای ورود</label><div class="flex gap-4"><label class="flex items-center gap-2 cursor-pointer"><input type="radio" name="login_captcha" value="enabled" <?= $captcha_enabled ? 'checked' : '' ?> class="accent-indigo-600"><span class="text-sm">فعال</span></label><label class="flex items-center gap-2 cursor-pointer"><input type="radio" name="login_captcha" value="disabled" <?= !$captcha_enabled ? 'checked' : '' ?> class="accent-indigo-600"><span class="text-sm">غیرفعال</span></label></div></div>
                <div>
                <div>
    <label class="block text-sm font-semibold text-slate-700 mb-2">بررسی بدهی سال قبل</label>
    <input type="hidden" name="check_previous_balance" value="disabled">
    <label class="flex items-center gap-2 cursor-pointer">
        <input type="checkbox" name="check_previous_balance" value="enabled"
               <?= $check_previous_balance === 'enabled' ? 'checked' : '' ?>
               class="accent-indigo-600">
        <span class="text-sm">هشدار بدهی سال قبل نمایش داده شود</span>
    </label>
    <p class="text-xs text-slate-400 mt-1">در صورت فعال بودن، در کارت حساب هر دانش‌آموز بدهی سال مالی قبل در صورت وجود نمایش داده می‌شود.</p>
</div>
                <!-- افزودن تنظیم جدید: فعال/غیرفعال بودن توضیحات در ثبت پرداخت -->
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-2">فیلد توضیحات در ثبت پرداخت</label>
                    <input type="hidden" name="payment_notes_enabled" value="disabled">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="payment_notes_enabled" value="enabled"
                               <?= (get_setting('payment_notes_enabled', 'enabled') === 'enabled') ? 'checked' : '' ?>
                               class="accent-indigo-600">
                        <span class="text-sm">فعال بودن توضیحات</span>
                    </label>
                    <p class="text-xs text-slate-400 mt-1">در صورت غیرفعال بودن، فیلد توضیحات از صفحهٔ ثبت پرداخت حذف می‌شود.</p>
                </div>
                <button type="submit" name="save_settings" class="px-6 py-3 bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-xl shadow-lg shadow-indigo-200 transition-all btn-pulse">ذخیره تنظیمات</button>
            </form>
        </div>

    <?php elseif ($tab === 'active_fiscal'): ?>
        <div class="bg-white rounded-2xl border border-slate-200 p-6">
            <form method="post" class="space-y-4">
                <?= csrf_field() ?>
                <div><label class="block text-sm font-semibold text-slate-700 mb-2">سال مالی فعال</label><select name="active_fiscal_year" class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400 outline-none"><?php foreach ($fiscal_years as $fy): ?><option value="<?= $fy['id'] ?>" <?= $fy['id'] == $active_fiscal ? 'selected' : '' ?>><?= e($fy['name']) ?> <?= $fy['is_closed'] ? '(بسته)' : '' ?></option><?php endforeach; ?></select><p class="text-xs text-slate-400 mt-1">تمامی لیست‌ها، گزارش‌ها و پرداخت‌ها بر اساس این سال نمایش داده می‌شوند.</p></div>
                <button type="submit" name="save_active_fiscal" class="px-6 py-3 bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-xl shadow-lg shadow-indigo-200 transition-all btn-pulse">ذخیره</button>
            </form>
        </div>

    <?php elseif ($tab === 'mapping'): ?>
        <div class="bg-white rounded-2xl border border-slate-200 p-6">
            <form method="post" class="space-y-4">
                <?= csrf_field() ?>
                <div><label class="block text-sm font-semibold text-slate-700 mb-2">نگاشت کلاس به پایه (JSON)</label><textarea name="class_grade_mapping" rows="8" class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400 outline-none font-mono text-sm" placeholder='{"101":"هفتم","102":"هشتم"}'><?= e($class_mapping) ?></textarea></div>
                <button type="submit" name="save_mapping" class="px-6 py-3 bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-xl shadow-lg shadow-indigo-200 transition-all btn-pulse">ذخیره نگاشت</button>
            </form>
        </div>

    <?php elseif ($tab === 'fiscal'): ?>
        <div class="bg-white rounded-2xl border border-slate-200 p-6 mb-6">
            <h2 class="text-lg font-bold text-slate-800 mb-4"><?= $edit_fy ? 'ویرایش سال مالی' : 'افزودن سال مالی جدید' ?></h2>
            <form method="post" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="fy_id" value="<?= $edit_fy['id'] ?? 0 ?>">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div><label class="block text-sm font-semibold text-slate-700 mb-2">نام سال مالی</label><input type="text" name="fy_name" value="<?= e($edit_fy['name'] ?? '') ?>" placeholder="مثال: ۱۴۰۴-۱۴۰۳" class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400 outline-none"></div>
                    <div><label class="block text-sm font-semibold text-slate-700 mb-2">شهریه پایه</label><input type="text" name="fy_tuition" value="<?= isset($edit_fy) ? number_format($edit_fy['tuition_amount']) : '' ?>" class="money-input w-full p-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400 outline-none" placeholder="مبلغ شهریه"></div>
                    <div><label class="block text-sm font-semibold text-slate-700 mb-2">ترتیب (اختیاری)</label><input type="number" name="fy_sort_order" value="<?= e($edit_fy['sort_order'] ?? '') ?>" class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400 outline-none" placeholder="مثلاً 1"></div>
                    <div><label class="block text-sm font-semibold text-slate-700 mb-2">تاریخ شروع</label><input type="text" name="fy_start" value="<?= e($edit_fy['start_date'] ?? '') ?>" class="datepicker-jalali w-full p-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400 outline-none" placeholder="1403/07/01" readonly></div>
                    <div><label class="block text-sm font-semibold text-slate-700 mb-2">تاریخ پایان</label><input type="text" name="fy_end" value="<?= e($edit_fy['end_date'] ?? '') ?>" class="datepicker-jalali w-full p-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400 outline-none" placeholder="1404/06/31" readonly></div>
                </div>
                <div class="flex items-center gap-2"><input type="checkbox" name="fy_closed" id="fy_closed" <?= (isset($edit_fy) && $edit_fy['is_closed']) ? 'checked' : '' ?> class="accent-indigo-600"><label for="fy_closed" class="text-sm text-slate-700">این سال مالی بسته شود</label></div>
                <div class="flex gap-3">
                    <button type="submit" name="save_fiscal_year" class="px-6 py-3 bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-xl shadow-lg shadow-indigo-200 transition-all btn-pulse"><?= $edit_fy ? 'بروزرسانی' : 'افزودن' ?></button>
                    <?php if ($edit_fy): ?><a href="?tab=fiscal" class="px-6 py-3 bg-slate-100 hover:bg-slate-200 text-slate-700 font-medium rounded-xl transition-colors btn-pulse">انصراف</a><?php endif; ?>
                </div>
            </form>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden">
            <div class="px-6 py-4 border-b bg-slate-50"><h3 class="font-bold text-slate-800">سال‌های مالی ثبت‌شده</h3></div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="bg-slate-50"><th class="px-4 py-3">نام</th><th class="px-4 py-3">شهریه</th><th class="px-4 py-3">ترتیب</th><th class="px-4 py-3">تاریخ شروع</th><th class="px-4 py-3">تاریخ پایان</th><th class="px-4 py-3">وضعیت</th><th class="text-center px-4 py-3">عملیات</th></tr></thead>
                    <tbody>
                        <?php foreach ($fiscal_years as $fy): ?>
                            <tr class="border-b hover:bg-slate-50">
                                <td class="px-4 py-3 font-medium"><?= e($fy['name']) ?></td>
                                <td class="px-4 py-3"><?= format_money($fy['tuition_amount'], $unit) ?></td>
                                <td class="px-4 py-3"><?= e($fy['sort_order'] ?? '') ?></td>
                                <td class="px-4 py-3"><?= e($fy['start_date']) ?></td>
                                <td class="px-4 py-3"><?= e($fy['end_date']) ?></td>
                                <td class="px-4 py-3"><span class="px-2.5 py-1 rounded-full text-xs font-medium <?= $fy['is_closed'] ? 'bg-slate-100 text-slate-600' : 'bg-emerald-50 text-emerald-700' ?>"><?= $fy['is_closed'] ? 'بسته' : 'فعال' ?></span></td>
                                <td class="px-4 py-3 text-center">
                                    <a href="?tab=fiscal&edit_fy=<?= $fy['id'] ?>" class="px-3 py-1.5 bg-amber-50 hover:bg-amber-100 text-amber-700 rounded-lg text-xs font-medium transition-colors">ویرایش</a>
                                    <?php if ($fy['id'] != $active_fiscal): ?>
                                        <a href="?tab=fiscal&delete_fy=<?= $fy['id'] ?>" onclick="return confirm('آیا از حذف این سال مالی و تمام اطلاعات آن اطمینان دارید؟ این عملیات غیرقابل بازگشت است.')" class="px-3 py-1.5 bg-red-50 hover:bg-red-100 text-red-700 rounded-lg text-xs font-medium transition-colors">حذف</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include INCLUDES_PATH . '/footer.php'; ?>