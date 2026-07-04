<?php
/**
 * بارگذاری اکسل – نسخه ۲.۲ بدون student_fiscal_years
 * مسیر: /upload.php
 */
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/functions.php';
require_once ROOT_PATH . '/vendor/autoload.php';

$page_title = 'بارگذاری اکسل';
$errors = [];
$success = [];
$preview_data = null;
$students_to_insert = [];
$uploaded_file_path = null;

// دریافت سال‌های مالی برای انتخاب
$fiscal_years = $pdo_master->query("SELECT * FROM fiscal_years ORDER BY id DESC")->fetchAll();
$active_year_id = get_active_fiscal_year_id();
$target_fiscal = (int)($_POST['target_fiscal'] ?? $active_year_id);

// ========= مرحله ۱: آپلود و پیش‌نمایش =========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file'])) {
    csrf_verify();
    $file = $_FILES['excel_file'];
    $allowed = ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel'];
    if ($file['error'] !== UPLOAD_ERR_OK) $errors[] = 'خطای آپلود: ' . $file['error'];
    elseif (!in_array($file['type'], $allowed)) $errors[] = 'فقط فایل‌های Excel (.xlsx, .xls) مجازند.';
    elseif ($file['size'] > 5 * 1024 * 1024) $errors[] = 'حداکثر حجم ۵ مگابایت.';
    else {
        $tmp_dir = PRIVATE_PATH . '/tmp';
        if (!is_dir($tmp_dir)) mkdir($tmp_dir, 0755, true);
        $tmp_name = $tmp_dir . '/' . uniqid('excel_') . '.' . pathinfo($file['name'], PATHINFO_EXTENSION);
        if (move_uploaded_file($file['tmp_name'], $tmp_name)) {
            $uploaded_file_path = $tmp_name;
        } else {
            $errors[] = 'خطا در ذخیره فایل.';
        }
    }

    if (empty($errors) && $uploaded_file_path) {
        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($uploaded_file_path);
            $sheet = $spreadsheet->getActiveSheet();
            $data = $sheet->toArray(null, true, true, true);
            if (count($data) < 4) $errors[] = 'فایل خالی یا ساختار نامناسب.';
            else {
                // تشخیص هدر
                $main_idx = null; $sub1 = null; $sub2 = null;
                foreach ($data as $idx => $row) {
                    if (isset($row['A']) && trim($row['A']) === 'ردیف') {
                        $main_idx = $idx; $sub1 = $idx + 1; $sub2 = $idx + 2; break;
                    }
                }
                if ($main_idx === null) $errors[] = 'ستون «ردیف» پیدا نشد.';
                else {
                    $col_count = count($data[$main_idx]);
                    $columns = [];
                    for ($i = 0, $letter = 'A'; $i < $col_count; $i++, $letter++) {
                        $c1 = trim($data[$main_idx][$letter] ?? '');
                        $c2 = trim($data[$sub1][$letter] ?? '');
                        $c3 = trim($data[$sub2][$letter] ?? '');
                        $name = $c1 ?: ($c2 ? (($i > 0 ? $data[$main_idx][chr(ord('A')+$i-1)] . ' - ' : '') . $c2) : 'col_'.$letter);
                        if ($c3) $name .= ' - ' . $c3;
                        $columns[$letter] = $name;
                    }
                    // نگاشت
                    $map = [];
                    $needed = [
                        'national_id' => ['کد ملی','کدملی','national'],
                        'first_name' => ['نام','نام و نام خانوادگی'],
                        'last_name' => ['نام خانوادگی'],
                        'father_name' => ['نام پدر','پدر'],
                        'birth_date' => ['تاریخ تولد','تولد'],
                        'birth_cert_serial' => ['سریال شناسنامه - شماره','شماره شناسنامه'],
                        'birth_cert_digit' => ['سریال شناسنامه - عدد'],
                        'birth_cert_letter' => ['سریال شناسنامه - حرف'],
                        'issuing_place' => ['محل صدور','صدور'],
                        'gender' => ['جنسیت'],
                        'class_name' => ['کلاس','پایه'],
                        'mobile' => ['تلفن همراه','موبایل'],
                        'phone' => ['تلفن ثابت','تلفن'],
                        'address' => ['آدرس'],
                        'postal_code' => ['کد پستی'],
                        'ledger_number' => ['شماره دفتر','دفترچه'],
                        'transfer_dropout' => ['ترک تحصیل'],
                        'deceased' => ['فوت شده','فوت'],
                    ];
                    foreach ($needed as $db => $aliases) {
                        foreach ($columns as $l => $col_name) {
                            foreach ($aliases as $alias) {
                                if (mb_stripos($col_name, $alias) !== false) { $map[$db] = $l; break 2; }
                            }
                        }
                    }
                    foreach (['national_id','first_name','last_name','class_name'] as $req) {
                        if (!isset($map[$req])) $errors[] = "ستون «{$req}» شناسایی نشد.";
                    }
                    if (empty($errors)) {
                        $start = $main_idx + 3;
                        $all = [];
                        for ($r = $start; $r <= count($data); $r++) {
                            $row = $data[$r];
                            if (empty($row['A']) && empty($row['B']) && empty($row['C'])) continue;
                            $s = [];
                            foreach ($map as $db => $l) $s[$db] = trim($row[$l] ?? '');

                            // جداسازی هوشمند نام و نام خانوادگی
                            if (!empty($s['first_name'])) {
                                $parts = explode(' ', $s['first_name']);
                                $count = count($parts);
                                if ($count > 2) {
                                    $s['first_name'] = array_pop($parts);
                                    $s['last_name'] = implode(' ', $parts);
                                } elseif ($count == 2) {
                                    $s['last_name'] = $parts[0];
                                    $s['first_name'] = $parts[1];
                                } else {
                                    $s['last_name'] = '';
                                    $s['first_name'] = $parts[0] ?? '';
                                }
                            }

                            if (!empty($s['birth_date']) && preg_match('/^\d{8}$/', $s['birth_date'])) {
                                $bd = $s['birth_date'];
                                $s['birth_date'] = substr($bd,0,4).'-'.substr($bd,4,2).'-'.substr($bd,6,2);
                            }
                            $all[] = $s;
                        }
                        $students_to_insert = $all;
                        $preview_data = array_slice($all, 0, 5);
                        $success[] = 'فایل تحلیل شد. تعداد رکورد: ' . count($all);
                    }
                }
            }
        } catch (\Exception $e) {
            $errors[] = 'خطای پردازش: ' . $e->getMessage();
        }
    }
}

// ========= مرحله ۲: تأیید نهایی =========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_upload'])) {
    csrf_verify();
    $json = json_decode($_POST['final_data'] ?? '', true);
    $target_fiscal = (int)$_POST['target_fiscal'];
    if (!$json) $errors[] = 'داده نامعتبر.';
    else {
        $pdo->beginTransaction();
        try {
            // درج/بروزرسانی دانش‌آموزان (سال جاری)
            $stmt = $pdo->prepare("INSERT OR REPLACE INTO students 
                (national_id, first_name, last_name, father_name, birth_date, birth_cert_serial, birth_cert_digit, birth_cert_letter, issuing_place, gender, class_name, mobile, phone, address, postal_code, ledger_number, transfer_dropout, deceased, row_index)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $ins = 0; $skip = 0;
            foreach ($json as $i => $s) {
                $nid = mb_substr(preg_replace('/[^0-9]/','',$s['national_id']),0,10);
                if (strlen($nid) !== 10) { $skip++; continue; }
                $stmt->execute([
                    $nid, $s['first_name'] ?? '', $s['last_name'] ?? '', $s['father_name'] ?? '',
                    $s['birth_date'] ?? null, $s['birth_cert_serial'] ?? null, $s['birth_cert_digit'] ?? null,
                    $s['birth_cert_letter'] ?? null, $s['issuing_place'] ?? null,
                    $s['gender'] ?? 'پسر', $s['class_name'] ?? '', $s['mobile'] ?? null,
                    $s['phone'] ?? null, $s['address'] ?? null, $s['postal_code'] ?? null,
                    $s['ledger_number'] ?? null,
                    isset($s['transfer_dropout']) && $s['transfer_dropout'] ? 1 : 0,
                    isset($s['deceased']) && $s['deceased'] ? 1 : 0,
                    $i + 1
                ]);
                $ins++;
            }
            $pdo->commit();
            $success[] = "ذخیره شد: {$ins} رکورد. " . ($skip ? "رد شده: {$skip}" : '');
            $students_to_insert = []; $preview_data = null;
        } catch (\Exception $e) {
            $pdo->rollBack();
            $errors[] = 'خطا در ذخیره: ' . $e->getMessage();
        }
    }
}

include INCLUDES_PATH . '/header.php';
?>

<div class="max-w-5xl mx-auto">
    <div class="mb-8">
        <h1 class="text-3xl font-extrabold text-slate-800">بارگذاری فایل اکسل</h1>
        <p class="text-slate-500 mt-2">فایل دانش‌آموزان را آپلود کنید. سیستم ستون‌ها را هوشمند تشخیص می‌دهد.</p>
    </div>

    <?php if ($errors): ?><div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-6"><?php foreach ($errors as $e): ?><p class="text-red-800 text-sm"><?= e($e) ?></p><?php endforeach; ?></div><?php endif; ?>
    <?php if ($success): ?><div class="bg-emerald-50 border border-emerald-200 rounded-xl p-4 mb-6"><?php foreach ($success as $s): ?><p class="text-emerald-800 text-sm"><?= e($s) ?></p><?php endforeach; ?></div><?php endif; ?>

    <?php if (!$preview_data && empty($students_to_insert)): ?>
        <div class="bg-white rounded-2xl border border-slate-200 p-8">
            <form method="post" enctype="multipart/form-data" class="space-y-6">
                <?= csrf_field() ?>
                <!-- انتخاب سال مالی -->
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-2">سال مالی مقصد</label>
                    <select name="target_fiscal" class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400 outline-none">
                        <?php foreach ($fiscal_years as $fy): ?>
                            <option value="<?= $fy['id'] ?>" <?= $fy['id'] == $active_year_id ? 'selected' : '' ?>><?= e($fy['name']) ?> <?= $fy['is_closed'] ? '(بسته)' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="border-2 border-dashed border-slate-300 rounded-xl p-8 text-center hover:border-indigo-400 transition-colors cursor-pointer" id="dropzone">
                    <svg class="mx-auto h-12 w-12 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5"/></svg>
                    <p class="mt-4 text-sm font-medium text-slate-600">فایل اکسل را اینجا بکشید یا کلیک کنید</p>
                    <p class="mt-1 text-xs text-slate-400">xlsx, xls - حداکثر ۵ مگابایت</p>
                    <input type="file" name="excel_file" id="excel_file" accept=".xlsx,.xls" required class="hidden">
                </div>
                <div class="flex justify-end">
                    <button type="submit" class="px-6 py-3 bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-xl shadow-lg shadow-indigo-200 transition-all btn-pulse">بارگذاری و پیش‌نمایش</button>
                </div>
            </form>
        </div>
    <?php elseif ($preview_data): ?>
        <div class="bg-white rounded-2xl border border-slate-200 p-8">
            <h2 class="text-xl font-bold mb-4">پیش‌نمایش (۵ ردیف اول) | سال مالی: <?= e($fiscal_years[array_search($target_fiscal, array_column($fiscal_years, 'id'))]['name'] ?? '') ?></h2>
            <div class="overflow-x-auto mb-6">
                <table class="w-full text-sm"><thead class="bg-slate-50"><tr><th class="px-3 py-2">کد ملی</th><th class="px-3 py-2">نام</th><th class="px-3 py-2">نام خانوادگی</th><th class="px-3 py-2">پدر</th><th class="px-3 py-2">تاریخ تولد</th><th class="px-3 py-2">کلاس</th><th class="px-3 py-2">موبایل</th></tr></thead>
                <tbody><?php foreach ($preview_data as $r): ?><tr class="border-b"><td class="px-3 py-2"><?= e($r['national_id'] ?? '') ?></td><td class="px-3 py-2"><?= e($r['first_name'] ?? '') ?></td><td class="px-3 py-2"><?= e($r['last_name'] ?? '') ?></td><td class="px-3 py-2"><?= e($r['father_name'] ?? '') ?></td><td class="px-3 py-2"><?= e($r['birth_date'] ?? '') ?></td><td class="px-3 py-2"><?= e($r['class_name'] ?? '') ?></td><td class="px-3 py-2"><?= e($r['mobile'] ?? '') ?></td></tr><?php endforeach; ?></tbody></table>
            </div>
            <p class="text-sm text-slate-500 mb-6">تعداد کل: <?= count($students_to_insert) ?></p>
            <div class="flex justify-between">
                <button onclick="window.history.back()" class="px-6 py-3 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-xl transition-colors btn-pulse">بازگشت</button>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="confirm_upload" value="1">
                    <input type="hidden" name="target_fiscal" value="<?= $target_fiscal ?>">
                    <input type="hidden" name="final_data" value="<?= e(json_encode($students_to_insert, JSON_UNESCAPED_UNICODE)) ?>">
                    <button type="submit" class="px-6 py-3 bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-xl shadow-lg shadow-emerald-200 transition-all btn-pulse">تأیید و ذخیره نهایی</button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
const dropZone = document.getElementById('dropzone');
const fileInput = document.getElementById('excel_file');
if (dropZone && fileInput) {
    dropZone.addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', e => {
        if (e.target.files.length) dropZone.querySelector('p:first-child').textContent = 'فایل: ' + e.target.files[0].name;
    });
    dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('border-indigo-400','bg-indigo-50'); });
    dropZone.addEventListener('dragleave', () => dropZone.classList.remove('border-indigo-400','bg-indigo-50'));
    dropZone.addEventListener('drop', e => {
        e.preventDefault();
        dropZone.classList.remove('border-indigo-400','bg-indigo-50');
        const files = e.dataTransfer.files;
        if (files.length) {
            fileInput.files = files;
            dropZone.querySelector('p:first-child').textContent = 'فایل: ' + files[0].name;
        }
    });
}
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>