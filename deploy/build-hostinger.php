<?php
/*
 * Build the Hostinger upload zip:   php deploy/build-hostinger.php path/to/database.sql
 *
 *  - copies the backend files that are in git (no .env, no logs, no tests)
 *  - installs the PHP libraries without dev tools (composer install --no-dev)
 *  - adds the root .htaccess, a production .env with a fresh APP_KEY, the guide, the APK and the database file
 *  - zips it to dist/sufra-groupops-hostinger.zip
 */
$root = dirname(__DIR__);
$sql = isset($argv[1]) ? (realpath($argv[1]) ?: null) : null; // resolve now: the script changes folder later
$php = PHP_BINARY;
$composer = getenv('COMPOSER_PHAR') ?: 'C:/laragon/bin/composer/composer.phar';
$out = $root.'/dist';
$stage = $root.'/deploy/.stage';

function rrmdir(string $d): void
{
    if (! is_dir($d)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() && ! $f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
    rmdir($d);
}
function step(string $m): void { echo "• $m\n"; }

rrmdir($stage);
@mkdir($stage, 0777, true);

step('Copying backend files tracked by git');
chdir($root);
$files = array_filter(explode("\0", shell_exec('git ls-files -z --cached --others --exclude-standard backend')));
$skip = ['backend/tests/', 'backend/phpunit.xml', 'backend/CLAUDE.md', 'backend/AGENTS.md', 'backend/.env.example', 'backend/package.json', 'backend/vite.config.js', 'backend/resources/css/', 'backend/resources/js/'];
$n = 0;
foreach ($files as $f) {
    if (! is_file($f)) continue;
    foreach ($skip as $s) if (str_starts_with($f, $s)) continue 2;
    $to = $stage.'/'.substr($f, strlen('backend/'));
    @mkdir(dirname($to), 0777, true);
    copy($f, $to);
    $n++;
}
echo "  $n files\n";

step('Installing PHP libraries (production, no dev tools)');
chdir($stage);
passthru(escapeshellarg($php).' '.escapeshellarg($composer).' install --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist 2>&1', $code);
if ($code !== 0) { fwrite(STDERR, "composer failed\n"); exit(1); }
foreach (glob($stage.'/bootstrap/cache/*.php') as $c) unlink($c); // built on this PC: let the server rebuild them

step('Adding .htaccess, .env, guide, upload folders');
copy($root.'/deploy/hostinger/root.htaccess', $stage.'/.htaccess');
$key = 'base64:'.base64_encode(random_bytes(32));
file_put_contents($stage.'/.env', str_replace('__APP_KEY__', $key, file_get_contents($root.'/deploy/hostinger/env.hostinger')));
copy($root.'/deploy/hostinger/DEPLOY-HOSTINGER.md', $stage.'/DEPLOY-HOSTINGER.md');
foreach (['public/storage', 'public/downloads', 'storage/app/public', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $d) {
    @mkdir($stage.'/'.$d, 0777, true);
}
file_put_contents($stage.'/public/storage/.gitkeep', '');
file_put_contents($stage.'/storage/logs/.gitkeep', '');
// the demo data points at a few demo photos (purchase receipts, a task proof)
foreach (glob($root.'/backend/storage/app/public/{receipts,proofs}/demo-*.jpg', GLOB_BRACE) as $img) {
    $to = $stage.'/public/storage/'.basename(dirname($img)).'/'.basename($img);
    @mkdir(dirname($to), 0777, true);
    copy($img, $to);
}
$apk = $root.'/backend/public/downloads/sufra-groupops.apk';
if (is_file($apk)) {
    step('Adding the Android app (APK)');
    copy($apk, $stage.'/public/downloads/sufra-groupops.apk');
}
if ($sql && is_file($sql)) {
    step('Adding the database file');
    copy($sql, $stage.'/database/sufra-groupops-database.sql');
}

step('Zipping');
$zipPath = $out.'/sufra-groupops-hostinger.zip';
@unlink($zipPath);
$zip = new ZipArchive();
$zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
$count = 0;
foreach ($it as $f) {
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($stage) + 1));
    if ($f->isDir()) { $zip->addEmptyDir($rel); continue; }
    $zip->addFile($f->getPathname(), $rel);
    if (str_ends_with($rel, '.apk')) $zip->setCompressionName($rel, ZipArchive::CM_STORE); // already compressed
    $count++;
}
$zip->close();
printf("✓ %s  (%d files, %.1f MB)\n", $zipPath, $count, filesize($zipPath) / 1048576);

// tidy up the build folder
rrmdir($stage);
