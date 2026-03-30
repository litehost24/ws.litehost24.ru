<?php

namespace App\Services\VpnAgent;

class VpnAgentMtlsPathResolver
{
    private const PROD_APP_ROOT = '/home/admin/web/ws.litehost24.ru/public_html/';

    public function resolve(?string $path): string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return '';
        }

        if (is_file($path)) {
            return $path;
        }

        if (!str_starts_with($path, self::PROD_APP_ROOT)) {
            return $path;
        }

        $relativePath = ltrim(substr($path, strlen(self::PROD_APP_ROOT)), '/');
        if ($relativePath === '') {
            return $path;
        }

        $localPath = base_path($relativePath);

        return is_file($localPath) ? $localPath : $path;
    }
}
