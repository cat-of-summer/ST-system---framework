<?php

namespace ST_system\Storage;

use ST_system\Main;
use ST_system\Cache\Manager as Cache;

final class File extends Resource {

    protected static function getDefaultConfig(): array {
        return array_merge(parent::getDefaultConfig(), [
            'cache' => [
                'dir' => '~/cache/',
                'ttl' => 3600,
            ],
            'request' => [
                'headers' => [],
                'follow_redirects' => true,
                'delay' => 0,
                'connect_timeout' => 10,
                'timeout' => 300,
                'max_attempts' => 15,
            ],
            'find' => [
                'max_files' => 50,
                'sym_links' => false,
                'recursive' => true,
                'hidden_files' => false,
            ]
        ]);
    }

    private ?Cache $cache = null;

    private static array $last_fetch_per_host = [];

    protected function __construct(string $path, array $options = [], $original = null) {
        $is_uri = (bool)filter_var($path, FILTER_VALIDATE_URL);
        $options['is_uri'] = $is_uri;

        $base = $original instanceof self && !$original->is_uri
            ? $original->getDirectory()
            : 2;

        $resolved = $is_uri ? $path : Main::preparePath($path, $base);

        parent::__construct([
            'name'     => $resolved,
            'id'       => $resolved,
            'original' => $original,
            'options'  => $options,
        ]);
    }

    private function cache(): Cache {
        if ($this->cache !== null) return $this->cache;

        $filename = $this->getFilename();

        if ($this->is_uri) {
            $q     = strpos($filename, '?');
            $base  = $q !== false ? substr($filename, 0, $q) : $filename;
            $query = $q !== false ? substr($filename, $q + 1) : '';
            $dot   = strrpos($base, '.');

            $filename = ($dot !== false ? substr($base, 0, $dot) : $base) . ($query !== '' ? '_' . md5($query) : '') . ($dot !== false ? substr($base, $dot): '');
        }

        return $this->cache = Cache::make($this->getPathname(), [
            'driver' => 'filesystem',
            'dir' => static::config('cache.dir'),
            'ttl' => static::config('cache.ttl'),
            'file' => $filename
        ]);
    }

    public static function __callStatic(string $name, array $args) {
        switch ($name) {
            case 'fetch':
                return static::make($args[0], $args[2] ?? [])->fetch($args[1] ?? false);
            case 'find':
                return static::find(...$args);
            case 'exists':
                return static::make($args[0])->exists();
            default:
                throw new \Exception("Method {$name} not found");
        }
    }

    public function __call(string $name, array $args) {
        switch ($name) {
            case 'fetch':
            case 'exists':
                return $this->{$name}(...$args);
            case 'make':
                return new static($args[0], $args[1] ?? [], $this);
            case 'find':
                if ($this->is_uri) return static::find($args[0], $args[1] ?? []);
                $dir = $this->fetch()->getDirectory();
                $items = array_map(function ($i) use ($dir) {
                    if ($i instanceof File) return $i;
                    if (!is_string($i) || $i === '') return $i;
                    if (filter_var($i, FILTER_VALIDATE_URL)) return $i;
                    if (strpos($i, '/') === 0 || strpos($i, '~') === 0) return $i;
                    return $dir.'/'.ltrim($i, '/');
                }, is_array($args[0]) ? $args[0] : [$args[0]]);
                return static::find($items, $args[1] ?? []);
            case 'getType':
                if ($this->is_uri) return 'uri';
                break;
        }

        return parent::__call($name, $args);
    }

    private static function getFilesystemIterator(string $start_dir, array $config): object {
        $flags = \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO | ($config['sym_links'] ? \FilesystemIterator::FOLLOW_SYMLINKS : 0);

        return $config['recursive']
            ? new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($start_dir, $flags),
                \RecursiveIteratorIterator::LEAVES_ONLY
            )
            : new \FilesystemIterator($start_dir, $flags);
    }

    private static function find($input, array $config = []): array {
        static $input_cache = [];

        $config = array_merge(
            static::config('find'),
            $config
        );

        if (isset($config['extension']) && !is_array($config['extension']))
            $config['extension'] = [$config['extension']];

        if (!is_array($input)) $input = [$input];

        $fallback = $config['fallback'] ?? null;
        $files    = [];
        $seen     = [];

        array_walk($input, function ($item) use (&$files, &$seen, $config, &$input_cache, $fallback) {
            if ($item instanceof self) {
                $p = $item->getPathname();
                if (isset($seen[$p])) return;
                $seen[$p] = true;
                $files[] = $item;
                return;
            }

            if (!is_string($item) || $item === '') return;

            if (filter_var($item, FILTER_VALIDATE_URL)) {
                if (isset($seen[$item])) return;
                $seen[$item] = true;
                $files[] = self::make($item);
                return;
            }

            $i = $input_cache[$item] ??= Main::preparePath($item, 5);
            $hasGlob = (bool)preg_match('/[\\(\\[\\{\\|\\?\\+\\*]/', $i, $glob_match, PREG_OFFSET_CAPTURE);
            $results = [];

            if (!$hasGlob) {
                if (file_exists($i)) {
                    if (is_dir($i)) {
                        $iterator = static::getFilesystemIterator($i, $config);

                        foreach ($iterator as $file)
                            if ($file->isFile() && (!isset($config['extension']) || in_array($file->getExtension(), $config['extension'], true))) {
                                $results[$file->getPathname()] = $file->getPathname();

                                if ($config['max_files'] > 0 && count($results) >= $config['max_files'] )
                                    break;
                            }
                    } else
                        $results[$i] = $i;
                }
            } else {
                $regex_offset = $glob_match[0][1];
                $dir_prefix   = substr($i, 0, $regex_offset);

                $start_dir = $dir_prefix == ''
                    ? $i
                    : (is_dir($dir_prefix)
                        ? rtrim($dir_prefix, '/')
                        : dirname($dir_prefix)
                );

                if (!is_dir($start_dir) || !is_readable($start_dir))
                    throw new \InvalidArgumentException("Start directory '{$start_dir}' does not exist or is not readable.");

                $pattern = str_replace('\\', '/', $dir_prefix) . substr($i, $regex_offset);

                $delimiter = '#';
                foreach (['#', '~', '%', '@', '!', '$', '`', ';', '|'] as $candidate)
                    if (strpos($pattern, $candidate) === false) {
                        $delimiter = $candidate;
                        break;
                    }

                $pattern = $delimiter.'^'.$pattern.'$'.$delimiter.'u';

                $iterator = static::getFilesystemIterator($start_dir, $config);

                foreach ($iterator as $file) {
                    if (!$config['hidden_files']) {
                        $parts = explode('/', str_replace('\\', '/', $iterator instanceof \RecursiveIteratorIterator ? $iterator->getSubPathname() : $iterator->getFilename()));
                        $skip = false;
                        foreach ($parts as $p)
                            if ($p != '' && strncmp($p, '.', 1) === 0) {
                                $skip = true;
                                break;
                            }

                        if ($skip)
                            continue;
                    }

                    if ($file->isFile() && (!isset($config['extension']) || in_array($file->getExtension(), $config['extension'], true))) {
                        if (@preg_match($pattern, str_replace('\\', '/', $file->getPathname())) === 1) {
                            $results[$file->getPathname()] = $file->getPathname();
                            if ($config['max_files'] > 0 && count($results) >= $config['max_files'] )
                                break;
                        }
                    }
                }
            }

            if (!$results) {
                if ($fallback === 'make' && !$hasGlob) {
                    if (isset($seen[$i])) return;
                    $seen[$i] = true;
                    $files[] = self::make($i);
                } elseif ($fallback === 'throw') {
                    throw new \InvalidArgumentException("File not found: {$item}");
                }
                return;
            }

            foreach ($results as $r) {
                if (isset($seen[$r])) continue;
                $seen[$r] = true;
                $files[] = new self($r);
            }
        });

        return $files;
    }

    protected function attributeMap(): array {
        return array_merge(parent::attributeMap(), [
            'real_path'   => ['getRealPath'],
            'directory'   => ['getDirectory'],
            'size'        => ['getSize'],
            'type'        => ['getType'],
            'exists'      => ['exists'],
            'ctime'       => ['getCTime'],
            'atime'       => ['getATime'],
            'is_dir'      => ['isDir'],
            'is_file'     => ['isFile'],
            'is_readable' => ['isReadable'],
            'is_writable' => ['isWritable'],
            'is_link'     => ['isLink'],
        ]);
    }

    public function purge(bool $storage = true): self {
        $path = $this->info()->getPathname();

        if (!$this->is_uri) clearstatcache(true, $path);

        if ($storage)                  $this->cache()->purge(true);
        elseif ($this->cache !== null) $this->cache->purge(false);

        $this->raw = null;

        return parent::purge($storage);
    }

    public function getMtimeAttribute(): int {
        if ($this->is_uri) return 0;
        $p = $this->getPathname();
        return is_file($p) ? (int)@filemtime($p) : 0;
    }

    public function setMeta(array $meta, bool $append = true): self {
        $this->cache()->setMeta($meta, 0, $append);
        return $this;
    }

    public function getMeta(bool $force = false): array {
        if (!$this->is_uri) return $this->cache()->getMeta();

        $url = $this->getPathname();
        $meta = $this->cache()->getMeta();

        if (!$this->cache()->isExpired('headers_cache_expires_in') && !$force)
            return $meta;

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => $this->follow_redirects,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CONNECTTIMEOUT => $this->connect_timeout,
            CURLOPT_TIMEOUT => min(30, $this->timeout),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER => $this->header_list,
        ]);

        $response = curl_exec($curl);
        $error = curl_errno($curl);
        $info = curl_getinfo($curl);

        curl_close($curl);

        if (!$error) {
            $headers = is_string($response)
                ? static::parseHttpHeaders($response)
                : (is_array($response) ? $response : []);
            $ttl = $this->resolveCacheTtl($headers);

            $meta = array_merge(
                $meta,
                $headers,
                [
                    'http_code' => $info['http_code'] ?? null,
                    'effective_url' => $info['url'] ?? $url,
                    'headers_cache_expires_in' => time() + $ttl,
                    'content_length' => ($info['download_content_length'] ?? 0) > 0
                        ? $info['download_content_length']
                        : $headers['content-length'] ?? 0
                ]
            );

            if (($meta['http_code'] ?? null) != 200 && is_file($this->cache()->file))
                $meta['expires_in'] = time() + $this->resolveCacheTtl($meta);

            $this->setMeta($meta, false);
        }

        return $meta;
    }


    private function fetch(bool $force = false): self {
        $this->purge(false);

        if (!$this->is_uri) return $this;

        $url = $this->getPathname();

        if (!$force) {
            if (is_file($this->cache()->file) && !$this->cache()->isExpired())
                return new static($this->cache()->file, [], $this);

            $meta = $this->getMeta();

            if (is_file($this->cache()->file) && ($meta['http_code'] ?? null) != 200)
                return new static($this->cache()->file, [], $this);
        }

        $attempt = 0;
        while ($attempt < $this->max_attempts) {
            try {
                $this->applyHostDelay($url);

                $fopen = fopen($this->cache()->file.'.temp', 'w');
                if (!$fopen) throw new \Exception();

                $curl = curl_init();
                curl_setopt_array($curl, [
                    CURLOPT_URL => $url,
                    CURLOPT_FOLLOWLOCATION => $this->follow_redirects,
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_RETURNTRANSFER => false,
                    CURLOPT_HEADER => false,
                    CURLOPT_CONNECTTIMEOUT => $this->connect_timeout,
                    CURLOPT_TIMEOUT => $this->timeout,
                    CURLOPT_FILE => $fopen,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_HTTPHEADER => $this->header_list,
                ]);

                $headers = [];
                curl_setopt($curl, CURLOPT_HEADERFUNCTION, function ($curl, $header_line) use (&$headers) {
                    $trim = trim($header_line);
                    if ($trim === '') return strlen($header_line);
                    $parts = explode(':', $trim, 2);
                    if (count($parts) === 2) $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                    else $headers['status-line'] = $trim;
                    return strlen($header_line);
                });

                $response = curl_exec($curl);
                $error = curl_errno($curl);
                $info = curl_getinfo($curl);

                curl_close($curl);
                fclose($fopen);

                if ($error) {
                    @unlink($this->cache()->file.'.temp');
                    throw new \RuntimeException ("cURL request failed for URL {$url} with error #{$error}: ".curl_strerror($error));
                }

                rename($this->cache()->file.'.temp', $this->cache()->file);

                $ttl = $this->resolveCacheTtl($headers);
                $cache_expires_in = time() + $ttl;

                $meta = array_merge(
                    $headers,
                    [
                        'http_code' => $info['http_code'] ?? null,
                        'effective_url' => $info['url'] ?? $url,
                        'content_length' => ($info['download_content_length'] ?? 0) > 0
                            ? $info['download_content_length']
                            : ($headers['content-length'] ?? filesize($this->cache()->file)),
                        'original_url' => $url,
                        'fetched_at' => time(),
                        'cache_ttl' => $ttl,
                        'expires_in' => $cache_expires_in,
                        'headers_cache_expires_in' => $cache_expires_in,
                    ]
                );

                $this->setMeta($meta, false);

                return new static($this->cache()->file, [], $this);
            } catch (\Throwable $th) {
                $attempt++;
            }
        }

        throw $th;
    }


    public function getSize(string $unit = 'b') {
        if ($this->is_uri) {
            $meta  = $this->getMeta();
            $bytes = (int)($meta['content-length'] ?? $meta['content_length'] ?? 0);
        } else
            $bytes = $this->info()->getSize();

        return Main::formatBytes($bytes, $unit);
    }

    protected function getHeadersAttribute(): array {
        return (array)($this->attributes['headers'] ?? static::config('request.headers'));
    }

    protected function getDelayAttribute(): int {
        return (int)($this->attributes['delay'] ?? static::config('request.delay'));
    }

    protected function getFollowRedirectsAttribute(): bool {
        return (bool)($this->attributes['follow_redirects'] ?? static::config('request.follow_redirects'));
    }

    protected function getConnectTimeoutAttribute(): int {
        return (int)($this->attributes['connect_timeout'] ?? static::config('request.connect_timeout'));
    }

    protected function getTimeoutAttribute(): int {
        return (int)($this->attributes['timeout'] ?? static::config('request.timeout'));
    }

    protected function getMaxAttemptsAttribute(): int {
        return (int)($this->attributes['max_attempts'] ?? static::config('request.max_attempts'));
    }

    protected function getHeaderListAttribute(): array {
        $list = [];
        foreach ($this->headers as $k => $v) {
            if (is_int($k) && is_string($v) && strpos($v, ':') !== false) {
                $list[] = $v;
                continue;
            }
            $list[] = "{$k}: {$v}";
        }
        return $list;
    }

    private static function parseHttpHeaders(string $raw): array {
        $parsed = [];
        foreach (preg_split('#\r\n#', trim(explode("\r\n\r\n", $raw."\r\n\r\n", 2)[0])) as $line) {
            if (strpos($line, ':') !== false) {
                [$k, $v] = explode(':', $line, 2);
                $parsed[strtolower(trim($k))] = trim($v);
            } elseif ($line !== '') {
                $parsed['status-line'] = $line;
            }
        }
        return $parsed;
    }

    private function resolveCacheTtl(array $headers): int {
        $min = (int)static::config('cache.ttl');

        if (!empty($headers['cache-control'])) {
            if (preg_match('/\bmax-age\s*=\s*(\d+)/i', $headers['cache-control'], $m))
                return max((int)$m[1], $min);
            if (preg_match('/\bs-maxage\s*=\s*(\d+)/i', $headers['cache-control'], $m))
                return max((int)$m[1], $min);
        }

        if (!empty($headers['expires'])) {
            $expires = strtotime($headers['expires']);
            if ($expires !== false) return max($expires - time(), $min);
        }

        return $min;
    }

    private function applyHostDelay(string $url): void {
        $delay_ms = $this->delay;
        if ($delay_ms <= 0) return;

        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) return;

        $last = self::$last_fetch_per_host[$host] ?? 0.0;
        if ($last > 0.0) {
            $elapsed_ms = (microtime(true) - $last) * 1000.0;
            if ($elapsed_ms < $delay_ms)
                usleep((int)(($delay_ms - $elapsed_ms) * 1000));
        }
        self::$last_fetch_per_host[$host] = microtime(true);
    }

    public static function diskFreeSpace(string $path = '~', string $format = 'b') {
        return Main::formatBytes(static::diskSpace('disk_free_space', $path), $format);
    }

    public static function diskTotalSpace(string $path = '~', string $format = 'b') {
        return Main::formatBytes(static::diskSpace('disk_total_space', $path), $format);
    }

    private static function diskSpace(string $fn, string $path): float {
        $dir = Main::preparePath($path, 2);
        if (is_file($dir)) $dir = dirname($dir);

        $bytes = @$fn($dir);

        if ($bytes === false)
            throw new \RuntimeException("Cannot read disk space for {$dir}");

        return $bytes;
    }

    public static function purgeAll(): void {
        Cache::make('', [
            'dir' => static::config('cache.dir'),
            'driver' => 'filesystem',
        ])->purgeBase();
    }

    public function getMime(): string {
        $mime = parent::getMime();
        if ($mime !== '') return $mime;

        if ($this->is_uri)
            return explode(';', $this->getMeta()['content-type'] ?? '', 2)[0] ?: '';

        if (($original = $this->getOriginal()) && $original->is_uri) {
            $ct = $original->getMeta()['content-type'] ?? '';
            if ($ct !== '') return explode(';', $ct, 2)[0];
        }

        $path = $this->getPathname();

        $mime_type = '';

        if (function_exists('finfo_open')) {

            static $finfo = null;
            if ($finfo === null) $finfo = finfo_open(FILEINFO_MIME_TYPE) ?: false;

            if ($finfo !== false)
                $mime_type = @finfo_file($finfo, $path);
        }

        if ($mime_type == '')
            $mime_type = @mime_content_type($path) ?: '';

        return $mime_type;
    }

    private function exists(): bool {
        return $this->is_uri
            ? false
            : file_exists($this->getPathname());
    }

    public function getDirectory(int $depth = 0): string {
        $dir = $this->isDir()
            ? $this->getPathname()
            : $this->getPath();

        for ($i = 0; $i < $depth; $i++)
            $dir = dirname($dir);

        return $dir;
    }

    public function getRaw(bool $force = false) {
        if (!$this->is_uri)
            return file_get_contents($this->getRealPath());

        if (!$force && $this->raw !== null)
            return $this->raw;

        $instance = $this->fetch();

        return $this->raw = file_get_contents($instance->getRealPath());
    }

    public function putContents($raw, int $flags = 0) {
        $instance = $this->is_uri
            ? $this->fetch()
            : $this;

        $data = $instance->mime()->set($raw, $flags);

        $dir = $instance->getDirectory();

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);

            if (!is_dir($dir))
                throw new \RuntimeException("Cannot create cache directory");
        }

        return file_put_contents($instance->getRealPath() ?: $instance->getPathname(), $data, $flags);
    }

}
