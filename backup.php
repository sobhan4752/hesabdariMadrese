<?php
/**
 * مدیریت بک‌آپ
 * مسیر: /backup.php
 */
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/functions.php';

$page_title = 'بک‌آپ سیستم';

// ---------- تنظیم کلید محرمانه ----------
$backup_secret = get_setting('backup_secret', '');
if (empty($backup_secret)) {
    $backup_secret = bin2hex(random_bytes(16));
    $pdo_master->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('backup_secret', ?)")->execute([$backup_secret]);
}

// ---------- حالت cron (بدون نیاز به لاگین) ----------
if (isset($_GET['cron_key']) && $_GET['cron_key'] === $backup_secret) {
    perform_backup();
    echo 'Backup completed at ' . date('Y-m-d H:i:s');
    exit;
}

// ---------- درخواست دانلود ----------
if (isset($_GET['download'])) {
    $filename = basename($_GET['download']);
    $filepath = PRIVATE_PATH . '/backups/' . $filename;
    if (file_exists($filepath)) {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit;
    } else {
        die('فایل یافت نشد.');
    }
}

// ---------- حذف بک‌آپ ----------
if (isset($_GET['delete'])) {
    $filename = basename($_GET['delete']);
    $filepath = PRIVATE_PATH . '/backups/' . $filename;
    if (file_exists($filepath)) {
        unlink($filepath);
    }
    header('Location: backup.php');
    exit;
}

// ---------- عملیات دستی (POST) ----------
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    perform_backup();
    $message = 'بک‌آپ جدید با موفقیت ایجاد شد.';
}

// ---------- تابع بک‌آپ (کل فایل‌های پروژه) ----------
function perform_backup() {
    $backup_dir = PRIVATE_PATH . '/backups';
    if (!is_dir($backup_dir)) mkdir($backup_dir, 0755, true);

    $zipname = $backup_dir . '/full_backup_' . date('Y-m-d_H-i-s') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipname, ZipArchive::CREATE) !== TRUE) {
        die('خطا در ایجاد فایل ZIP');
    }

    // اضافه کردن کل فایل‌های پروژه (ROOT_PATH) به‌جز پوشه‌های زیر:
    $exclude = [
        'private/backups',    // بک‌آپ‌های قبلی
        'private/cache',      // کش سیستم
        'assets/tmp',         // فایل‌های موقت پیش‌نمایش PDF
        '.git',               // اگر گیت دارید
        'node_modules',       // اگر داشتید
    ];

    $root = ROOT_PATH;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        $local = str_replace($root . '/', '', $file->getPathname());
        $local = str_replace('\\', '/', $local);

        // بررسی استثناها
        $excluded = false;
        foreach ($exclude as $ex) {
            if (strpos($local, $ex) === 0) {
                $excluded = true;
                break;
            }
        }
        if ($excluded) continue;

        if ($file->isFile()) {
            $zip->addFile($file->getPathname(), $local);
        } elseif ($file->isDir()) {
            $zip->addEmptyDir($local);
        }
    }

    $zip->close();

    // حذف بک‌آپ‌های قدیمی (نگه‌داشتن حداکثر ۵ بک‌آپ اخیر)
    $files = glob($backup_dir . '/full_backup_*.zip');
    if (count($files) > 5) {
        usort($files, function($a, $b) { return filemtime($a) - filemtime($b); });
        $to_delete = array_slice($files, 0, count($files) - 5);
        foreach ($to_delete as $f) unlink($f);
    }
}

// ---------- لیست بک‌آپ‌ها ----------
$backup_dir = PRIVATE_PATH . '/backups';
$backups = [];
if (is_dir($backup_dir)) {
    $files = glob($backup_dir . '/full_backup_*.zip');
    foreach ($files as $f) {
        $backups[] = [
            'name' => basename($f),
            'size' => round(filesize($f) / 1024, 1) . ' KB',
            'time' => filemtime($f)
        ];
    }
    usort($backups, function($a, $b) { return $b['time'] - $a['time']; });
}

include INCLUDES_PATH . '/header.php';
?>

<div class="max-w-5xl mx-auto">
    <h1 class="text-3xl font-extrabold text-slate-800 mb-6">بک‌آپ سیستم</h1>

    <?php if ($message): ?>
        <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-4 mb-6">
            <p class="text-emerald-800"><?= e($message) ?></p>
        </div>
    <?php endif; ?>

    <div class="bg-white rounded-2xl border border-slate-200 p-6 mb-6">
        <h2 class="text-lg font-bold text-slate-800 mb-3">ایجاد بک‌آپ دستی</h2>
        <form method="post">
            <button type="submit" class="px-6 py-3 bg-indigo-600 text-white rounded-xl font-bold btn-pulse">همین حالا بک‌آپ کامل بگیر</button>
        </form>
        <p class="text-xs text-slate-400 mt-3">
            کلید خودکار: <code class="bg-slate-100 px-2 py-0.5 rounded"><?= e($backup_secret) ?></code><br>
            برای cron: <code>wget -q -O /dev/null "<?= BASE_URL ?>backup.php?cron_key=<?= e($backup_secret) ?>"</code>
        </p>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 p-6">
        <h2 class="text-lg font-bold text-slate-800 mb-4">بک‌آپ‌های موجود</h2>
        <?php if (empty($backups)): ?>
            <p class="text-slate-400">هنوز بک‌آپی وجود ندارد.</p>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-4 py-3 text-right">نام فایل</th>
                            <th class="px-4 py-3 text-right">حجم</th>
                            <th class="px-4 py-3 text-right">تاریخ</th>
                            <th class="px-4 py-3 text-center">دانلود</th>
                            <th class="px-4 py-3 text-center">حذف</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($backups as $b): ?>
                            <tr class="border-b hover:bg-slate-50">
                                <td class="px-4 py-3"><?= e($b['name']) ?></td>
                                <td class="px-4 py-3"><?= $b['size'] ?></td>
                                <td class="px-4 py-3"><?= date('Y/m/d H:i:s', $b['time']) ?></td>
                                <td class="px-4 py-3 text-center">
                                    <a href="?download=<?= urlencode($b['name']) ?>" class="text-indigo-600 hover:underline">دانلود</a>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <a href="?delete=<?= urlencode($b['name']) ?>" class="text-red-600 hover:underline" onclick="return confirm('آیا از حذف این بک‌آپ اطمینان دارید؟')">حذف</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include INCLUDES_PATH . '/footer.php'; ?>