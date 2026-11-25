<?php
/*
 * One-time deploy helper (uploaded by GitHub Actions into public/ as _deploy.php, with the release zip
 * next to the app as _release.zip). GitHub calls it once over HTTPS with a random token; it unpacks the
 * release over the site, removes files dropped from the app, updates the database when it is set up,
 * then deletes the zip and itself. Without the right token (or after 1 hour) it does nothing.
 */
const TOKEN_SHA256 = '__TOKEN_SHA256__';
const BUILT_AT = __BUILT_AT__;
const ZIP_NAME = '__ZIP_NAME__'; // random name per deploy, so the zip can't be guessed while it sits on the server

header('Content-Type: application/json');
header('Cache-Control: no-store');
if (! hash_equals(TOKEN_SHA256, hash('sha256', (string) ($_GET['t'] ?? ''))) || time() - BUILT_AT > 3600) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}
@set_time_limit(900);
@ini_set('memory_limit', '512M');
ignore_user_abort(true);

$root = dirname(__DIR__);
$zipPath = $root.'/'.ZIP_NAME;
$out = ['ok' => false, 'php' => PHP_VERSION, 'root' => basename($root)];

function rrmdir(string $d): void
{
    if (! is_dir($d)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() && ! $f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($d);
}

try {
    if (version_compare(PHP_VERSION, '8.3.0', '<')) {
        throw new RuntimeException('PHP '.PHP_VERSION.' is too old: choose PHP 8.3 in hPanel → Advanced → PHP Configuration.');
    }
    if (! class_exists(ZipArchive::class)) {
        throw new RuntimeException('The PHP zip extension is off: tick "zip" in hPanel → PHP Configuration → PHP extensions.');
    }
    $z = new ZipArchive();
    if ($z->open($zipPath) !== true) {
        throw new RuntimeException('Cannot open the release zip (upload incomplete?).');
    }
    $files = [];
    for ($i = 0; $i < $z->numFiles; $i++) {
        $name = $z->getNameIndex($i);
        if (str_contains($name, '..') || str_starts_with($name, '/')) {
            throw new RuntimeException('Unsafe path in the zip: '.$name);
        }
        if (! str_ends_with($name, '/')) $files[] = $name;
    }
    if (! $z->extractTo($root)) {
        throw new RuntimeException('Unpacking failed (disk full or no permission?).');
    }
    $z->close();
    $out['files'] = count($files);

    // files that were in the last release but not in this one (never the server's own .env, uploads, logs …)
    $manifest = $root.'/storage/app/.deploy-manifest';
    $old = is_file($manifest) ? file($manifest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    $keep = array_flip($files);
    $removed = 0;
    foreach ($old as $f) {
        if (isset($keep[$f]) || str_contains($f, '..') || preg_match('#^(\.env$|public/storage/|public/downloads/|storage/)#', $f)) continue;
        if (is_file("$root/$f") && @unlink("$root/$f")) $removed++;
    }
    @file_put_contents($manifest, implode("\n", $files));
    $out['removed_old_files'] = $removed;

    // leftover from the first FTP attempt: a copy of the app inside public_html/public_html
    if (is_file($root.'/public_html/artisan') && is_dir($root.'/public_html/vendor')) {
        rrmdir($root.'/public_html');
        $out['cleaned'] = 'removed the stray public_html/public_html copy';
    }

    $out['env'] = is_file($root.'/.env');
    if (! $out['env']) {
        $out['next'] = 'No .env on the server yet: add the APP_KEY, APP_URL and DB_* secrets in GitHub (or create .env in File Manager), then deploy again.';
    } else {
        // the database: update it only when it has been set up (import backend/database/sufra-groupops-database.sql first)
        require $root.'/vendor/autoload.php';
        $app = require $root.'/bootstrap/app.php';
        $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
        $kernel->bootstrap();
        try {
            $kernel->call('optimize:clear');
            if (Illuminate\Support\Facades\Schema::hasTable('migrations')) {
                $kernel->call('migrate', ['--force' => true]);
                $out['database'] = trim($kernel->output()) ?: 'up to date';
            } else {
                $out['database'] = 'empty: import backend/database/sufra-groupops-database.sql in phpMyAdmin, then deploy again';
            }
        } catch (Throwable $e) {
            $out['database'] = 'not reachable: '.$e->getMessage().' (check DB_DATABASE / DB_USERNAME / DB_PASSWORD)';
        }
    }
    $out['ok'] = true;
} catch (Throwable $e) {
    $out['error'] = $e->getMessage();
}

@unlink($zipPath);
foreach (glob($root.'/_release-*.zip') ?: [] as $old) @unlink($old); // zips left by earlier failed deploys
@unlink(__FILE__);
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
