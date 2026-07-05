<?php

namespace ST_system\API\Drivers\Geo;

use ST_system\API\IntegrationDriver;
use ST_system\Storage\File;

abstract class GeoDriver extends IntegrationDriver {

    protected static function getDefaultConfig(): array {
        return [
            'cache'   => ['use' => false],
            'mode'    => 'auto',
            'db_path' => '',
            'db_url'  => '',
        ];
    }

    protected function __init(): void {
        $this->on('__construct', fn(string $credentials = '') => $this->bootCredentials($credentials));

        $this->on('build_url', function(&$request_url, $endpoint, $method, &$params) {
            $ip = (string)($params['ip'] ?? '');
            unset($params['ip']);
            $request_url = $this->apiUrl($ip);
        });

        $this->on('before_curl_init', function($url, $method, $params, &$config) {
            if (($header = $this->apiAuthHeader()) !== null && $header !== '')
                $config['headers']['Authorization'] = $header;
        });

        $this->registerMethodsMap([
            'lookup' => ['params' => ['ip' => 'string|required'], 'cache_ttl' => -1],
        ]);
    }

    public function getDetails(string $ip): array {
        $mode = (string)static::config('mode');

        if ($mode !== 'api') {
            $path = $this->resolveDbPath();

            if ($path === null && $mode !== 'local' && $this->update())
                $path = $this->resolveDbPath();

            if ($path !== null)    return $this->lookupLocal($ip, $path);
            if ($mode === 'local') return [];
        }

        $resp = $this->call('lookup', ['ip' => $ip]);

        return $this->normalizeApiResponse(is_array($resp) ? $resp : []);
    }

    public function update(): bool {
        $url = $this->downloadUrl();
        if ($url === null || $url === '') return false;

        try {
            $src = File::fetch($url, true)->getRealPath();
            if (!$src || !is_file($src)) return false;

            $target = $this->extract($src, $this->dataDir());
            if ($target === null || !is_file($target)) return false;

            $target = realpath($target) ?: $target;
            File::make($target)->setMeta(['geo_version' => $this->dbVersion($target), 'fetched_at' => time()]);

            return true;
        } catch (\Throwable $th) {
            return false;
        }
    }

    public function version(): ?string {
        $path = $this->resolveDbPath();
        if ($path === null) return null;

        $meta = File::make($path)->getMeta();

        return !empty($meta['geo_version']) ? (string)$meta['geo_version'] : $this->dbVersion($path);
    }

    protected function dataDir(): string {
        $assets = realpath(__DIR__ . '/../../../../assets/geo');
        if ($assets !== false && is_dir($assets) && is_writable($assets)) return $assets;

        $dir = sys_get_temp_dir() . '/st_system_geo';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);

        return $dir;
    }

    protected function resolveDbPath(): ?string {
        $file = $this->dbFilename();
        if ($file === '') return null;

        $configured = (string)static::config('db_path');

        $candidates = $configured !== ''
            ? [$configured]
            : [__DIR__ . '/../../../../assets/geo/' . $file, $this->dataDir() . '/' . $file];

        foreach ($candidates as $candidate)
            if (is_file($candidate)) return realpath($candidate) ?: $candidate;

        return null;
    }

    protected function bootCredentials(string $credentials): void {}

    protected function apiUrl(string $ip): string {
        return rtrim($this->getEndpoint(), '/') . '/' . $ip;
    }

    protected function apiAuthHeader(): ?string {
        return null;
    }

    protected function normalizeApiResponse(array $resp): array {
        return [];
    }

    protected function downloadUrl(): ?string {
        return (string)static::config('db_url') ?: null;
    }

    protected function extract(string $archivePath, string $targetDir): ?string {
        return null;
    }

    protected function dbFilename(): string {
        return '';
    }

    protected function lookupLocal(string $ip, string $path): array {
        return [];
    }

    protected function dbVersion(string $path): ?string {
        return null;
    }
}
