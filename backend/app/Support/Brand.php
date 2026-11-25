<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/** Company identity used on bills, the web panel, the app and the download guide. */
class Brand
{
    public static function name(): string
    {
        return (string) Features::setting('company.name', 'Sufra Food Ltd');
    }

    public static function logoPath(): ?string
    {
        $p = (string) Features::setting('company.logo', '');

        return $p !== '' && Storage::disk('public')->exists($p) ? $p : null;
    }

    /** Absolute URL of any uploaded setting picture (e.g. the bKash QR), or null. */
    public static function fileUrl(?string $path, ?string $base = null): ?string
    {
        $p = (string) $path;
        if ($p === '' || ! Storage::disk('public')->exists($p)) {
            return null;
        }

        return rtrim($base ?? request()->getSchemeAndHttpHost(), '/').'/storage/'.$p.'?v='.substr(md5($p), 0, 6);
    }

    /** Absolute URL of the uploaded logo, or null when none is set. */
    public static function logoUrl(?string $base = null): ?string
    {
        $p = static::logoPath();
        if (! $p) {
            return null;
        }
        $base = rtrim($base ?? request()->getSchemeAndHttpHost(), '/');

        return $base.'/storage/'.$p.'?v='.substr(md5($p), 0, 6);
    }

    /** Logo as a data: URI, so the bill prints even if the printer PC is offline from /storage. */
    public static function logoData(): ?string
    {
        $p = static::logoPath();
        if (! $p) {
            return null;
        }
        $mime = Storage::disk('public')->mimeType($p) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode(Storage::disk('public')->get($p));
    }

    /** First letters for the fallback badge, e.g. "Sufra Food Ltd" → "সু" when a Bangla name exists, else "SF". */
    public static function initials(): string
    {
        $bn = trim((string) Features::setting('company.name_bn', ''));
        if ($bn !== '') {
            return mb_substr($bn, 0, 2);
        }
        $w = preg_split('/\s+/', static::name());

        return strtoupper(mb_substr($w[0] ?? 'G', 0, 1).mb_substr($w[1] ?? '', 0, 1));
    }

    /** The server address phones should type into the app. */
    public static function serverUrl(): string
    {
        $set = trim((string) Features::setting('app.server_url', ''));
        if ($set !== '') {
            return rtrim($set, '/');
        }
        $host = request()->getHost();
        // When opened as localhost on the server PC, show the LAN address phones can reach.
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            $ip = static::lanIp();
            if ($ip) {
                return request()->getScheme().'://'.$ip.':'.request()->getPort();
            }
        }

        return request()->getSchemeAndHttpHost();
    }

    public static function lanIp(): ?string
    {
        $ip = gethostbyname(gethostname());

        return $ip && ! str_starts_with($ip, '127.') && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $ip : null;
    }

    public static function apkExists(): bool
    {
        return file_exists(public_path('downloads/sufra-groupops.apk'));
    }

    public static function apkSizeMb(): ?float
    {
        return static::apkExists() ? round(filesize(public_path('downloads/sufra-groupops.apk')) / 1048576, 1) : null;
    }
}
