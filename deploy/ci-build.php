<?php
/*
 * Build the folder that is uploaded into Hostinger's public_html (used by .github/workflows/deploy.yml).
 *
 *   php deploy/ci-build.php build          (run after `composer install --no-dev` in backend/)
 *
 * public_html then holds the Laravel app (app/, public/, vendor/ …) with a root .htaccess that sends
 * every request into public/ and hides everything else.
 *
 * .env: written only when the APP_KEY, APP_URL, DB_DATABASE, DB_USERNAME and DB_PASSWORD environment
 * variables are set (GitHub secrets). Otherwise no .env is uploaded and the one on the server is kept.
 */
$root = dirname(__DIR__);
$out = $root.'/'.($argv[1] ?? 'build');

function rrmdir(string $d): void
{
    if (! is_dir($d)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() && ! $f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($d);
}

// never uploaded: dev files, local secrets/logs/caches, the demo SQL (import it yourself), and folders the
// server owns (uploaded photos/logo in public/storage, the APK in public/downloads)
$skip = [
    '#^tests(/|$)#', '#^phpunit\.xml$#', '#^\.phpunit\.(result\.)?cache(/|$)#', '#^\.env($|\.)#', '#^node_modules/#', '#^CLAUDE\.md$#', '#^AGENTS\.md$#',
    '#^storage/logs/.+\.log$#', '#^storage/framework/(cache/data|sessions|views)/.+#', '#^storage/app/(public|private)/.+#',
    '#^bootstrap/cache/.+\.php$#', '#^database/.+\.(sql|sqlite)$#', '#^public/storage(/|$)#', '#^public/downloads/.+#', '#^public/hot$#',
];

rrmdir($out);
mkdir($out, 0777, true);
$src = $root.'/backend';
$n = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($it as $f) {
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($src) + 1));
    foreach ($skip as $re) if (preg_match($re, $rel)) continue 2;
    if ($f->isDir()) { @mkdir("$out/$rel", 0777, true); continue; }
    @mkdir(dirname("$out/$rel"), 0777, true);
    copy($f->getPathname(), "$out/$rel");
    $n++;
}

// root of public_html: send everything into public/, force HTTPS, hide .env
copy($root.'/deploy/hostinger/root.htaccess', "$out/.htaccess");

// folders Laravel needs on the server (FTP does not create empty folders, so each gets a small .keep file)
foreach (['storage/app/public', 'storage/app/private', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views',
    'storage/logs', 'bootstrap/cache', 'public/storage', 'public/downloads'] as $d) {
    @mkdir("$out/$d", 0777, true);
    file_put_contents("$out/$d/.keep", '');
}

// .env from GitHub secrets (optional)
$need = ['APP_KEY', 'APP_URL', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'];
$missing = array_filter($need, fn ($k) => (string) getenv($k) === '');
if (! $missing) {
    $q = fn (string $v) => '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $v).'"';
    $env = file_get_contents($root.'/deploy/hostinger/env.hostinger');
    $env = str_replace('__APP_KEY__', getenv('APP_KEY'), $env);
    $env = preg_replace('/^APP_URL=.*$/m', 'APP_URL='.rtrim(getenv('APP_URL'), '/'), $env);
    $env = preg_replace('/^DB_HOST=.*$/m', 'DB_HOST='.(getenv('DB_HOST') ?: 'localhost'), $env);
    foreach (['DB_DATABASE', 'DB_USERNAME'] as $k) $env = preg_replace("/^$k=.*$/m", "$k=".getenv($k), $env);
    $env = preg_replace_callback('/^DB_PASSWORD=.*$/m', fn () => 'DB_PASSWORD='.$q(getenv('DB_PASSWORD')), $env);
    $host = parse_url(getenv('APP_URL'), PHP_URL_HOST) ?: 'YOUR-DOMAIN.com';
    $env = str_replace('YOUR-DOMAIN.com', $host, $env);
    file_put_contents("$out/.env", $env);
    echo "• .env written from GitHub secrets\n";
} else {
    echo "• No .env uploaded (secrets missing: ".implode(', ', $missing)."). The .env already on the server is kept.\n";
}

echo "✓ $out ready ($n files)\n";
