<?php

namespace ST_system\Storage;

use ST_system\Traits\HasConfig;
use ST_system\Main;
use ST_system\Cache\Manager as Cache;
use ST_system\Storage\Mimes;

final class File {

    use HasConfig;

    protected static function getDefaultConfig(): array {
        return [
            'cache' => [
                'dir' => '~/cache/',
                'ttl' => 3600,
            ],
            'mimes' => [
                'extensions' => [
                    'js' => 'application/javascript',
                    'json' => 'application/json',
                    'css' => 'text/css',
                    'woff2' => 'font/woff2',
                    'woff' => 'font/woff',
                    'ttf' => 'font/ttf',
                    'eot' => 'font/eot',
                    'otf' => 'font/otf',
                    'svg' => 'image/svg+xml'
                ],
                'services' => [
                    'image/svg+xml' => Mimes\SvgMime::class,
                    'text/plain' => Mimes\TextPlainMime::class,
                    'text/css' => Mimes\CssMime::class,
                    'application/javascript' => Mimes\JavaScriptMime::class,
                    'application/json' => Mimes\JsonMime::class,
                    'font/' => Mimes\FontMime::class,
                    'image/' => Mimes\ImageMime::class,
                ]
            ],
            'fetch' => [
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
        ];
    }

    public static function make(string $path) { return new static($path); }

    private bool $isUri;
    private \SplFileInfo $info;
    private array $info_data = [];
    private Mimes\Mime $mime;
    private array $mime_data = [];
    private Cache $cache;
    private $original = null;

    private function __construct(string $path, $original = null) {
        $this->original = $original;
        $this->isUri = (bool)filter_var($path, FILTER_VALIDATE_URL);
        $this->info = new \SplFileInfo($this->isUri ? $path : Main::preparePath($path, 2));

        $cache_filename = $this->getFilename();

        if ($this->isUri) {
            $q     = strpos($cache_filename, '?');
            $base  = $q !== false ? substr($cache_filename, 0, $q) : $cache_filename;
            $query = $q !== false ? substr($cache_filename, $q + 1) : '';
            $dot   = strrpos($base, '.');

            $cache_filename = ($dot !== false ? substr($base, 0, $dot) : $base) . ($query !== '' ? '_' . md5($query) : '') . ($dot !== false ? substr($base, $dot): '');
        }

        $this->cache = Cache::make($this->getPathname(), [
            'driver' => 'filesystem',
            'dir' => static::config('cache.dir'),
            'ttl' => static::config('cache.ttl'),
            'file' => $cache_filename
        ]);

        $mime = $this->getMime();

        $this->mime = (
            ($matched = array_filter(
                static::config('mimes.services'),
                fn($r, $m) => strpos($mime, $m) !== false,
                ARRAY_FILTER_USE_BOTH
            ))
                ? new (reset($matched))($this)
                : new class($this) extends Mimes\Mime {}
        );
    }

    public static function __callStatic(string $name, array $args) {
        switch ($name) {
            case 'fetch':
                return static::make($args[0])->fetch($args[1] ?? false);
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
                return new static($args[0], $this);
            case 'find':
                return $this->isUri()
                    ? []
                    : static::find(array_map(fn($i) => $this->fetch()->getDirectory().'/'.ltrim($i, '/'), is_array($args[0]) ? $args[0] : [$args[0]]), $args[1] ?? []);
            case 'getType':
                if ($this->isUri()) return 'uri';
                break;
            case 'getBasename':
                if (empty($args)) {
                    $key = Main::hash(['getBasename', []]);
                    if (!array_key_exists($key, $this->info_data))
                        $this->info_data[$key] = $this->info->getBasename('.'.$this->getExtension());
                    return $this->info_data[$key];
                }
        }

        if (is_callable([$this->info, $name])) {
            $key = Main::hash([$name, $args]);
            if (!array_key_exists($key, $this->info_data))
                $this->info_data[$key] = $this->info->{$name}(...$args);
            return $this->info_data[$key];
        }

        if (isset($this->mime) && is_callable([$this->mime, $name])) {
            $key = Main::hash([$name, $args]);
            if (!array_key_exists($key, $this->mime_data))
                $this->mime_data[$key] = $this->mime->{$name}(...$args);
            return $this->mime_data[$key];
        }

        throw new \Exception("Method {$name} not found");
    }

    public function getOriginal(bool $force = false) {
        $instance = $this;

        if ($force) {
            while ($original = $instance->original)
                $instance = $original;

            return $instance;
        }

        return $instance->original;
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

        $results = [];
        array_walk($input, function ($i) use (&$results, $config, &$input_cache) {
            $i = $input_cache[$i] ??= Main::preparePath($i, 5);

            if (!preg_match('/[\\(\\[\\{\\|\\?\\+\\*]/', $i)) {
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
                $dir_prefix = substr($i, 0, preg_match('/[\\(\\[\\{\\|\\?\\+\\*]/', $i, $m, PREG_OFFSET_CAPTURE)
                    ? $m[0][1]
                    : 0
                );

                $start_dir = $dir_prefix == ''
                    ? $i
                    : (is_dir($dir_prefix)
                        ? rtrim($dir_prefix, '/')
                        : dirname($dir_prefix)
                );

                if (!is_dir($start_dir) || !is_readable($start_dir))
                    throw new \InvalidArgumentException("Start directory '{$start_dir}' does not exist or is not readable.");

                $pattern = str_replace('\\', '/', $i);

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
                        if (@preg_match($pattern, $file->getPathname()) === 1) {
                            $results[$file->getPathname()] = $file->getPathname();
                            if ($config['max_files'] > 0 && count($results) >= $config['max_files'] )
                                break;
                        }
                    }
                }
            }
        });

        return array_map(fn($file) => new static($file), array_filter($results));
    }

    private static function getTtl(array $headers = []): int {
        $ttl = static::config('cache.ttl');

        if (!empty($headers['cache-control'])) {
            if (preg_match('/\bmax-age\s*=\s*(\d+)/i', $headers['cache-control'], $m))
                $ttl = (int)$m[1];
            elseif (preg_match('/\bs-maxage\s*=\s*(\d+)/i', $headers['cache-control'], $m))
                $ttl = (int)$m[1];
            elseif (preg_match('/\b(no-cache|no-store)\b/i', $headers['cache-control']))
                $ttl = 0;
        } elseif (!empty($headers['expires'])) {
            $expires = strtotime($headers['expires']);

            if ($expires !== false)
                $ttl = max($expires - time(), 0);
        }

        return (int)$ttl;
    }

    public function getMeta(bool $force = false): array {
        if (!$this->isUri()) return [];

        $url = $this->getPathname();
        $meta = $this->cache->getMeta();
        
        if (!$this->cache->isExpired('headers_cache_expires_in') && !$force)
            return $meta;

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CONNECTTIMEOUT => static::config('fetch.connect_timeout'),
            CURLOPT_TIMEOUT => min(30, static::config('fetch.timeout')),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0
        ]);

        $response = curl_exec($curl);
        $error = curl_errno($curl);
        $info = curl_getinfo($curl);

        curl_close($curl);

        if (!$error) {
            $headers = [];
            foreach (preg_split('#\r\n#', trim(explode("\r\n\r\n", ($response ?? '')."\r\n\r\n", 2)[0])) as $line) {
                if (strpos($line, ':') !== false) {
                    [$k, $v] = explode(':', $line, 2);
                    $headers[strtolower(trim($k))] = trim($v);
                } else
                    $headers['status-line'] = $line;
            }

            $meta = array_merge(
                $meta,
                $headers,
                [
                    'http_code' => $info['http_code'] ?? null,
                    'effective_url' => $info['url'] ?? $url,
                    'headers_cache_expires_in' => time() + static::getTtl($headers),
                    'content_length' => ($info['download_content_length'] ?? 0) > 0 
                        ? $info['download_content_length']
                        : $headers['content_length'] ?? 0
                ]
            );

            if (($meta['http_code'] ?? null) != 200 && is_file($this->cache->file))
                $meta['expires_in'] = time() + static::getTtl($meta);

            $this->cache->setMeta($meta, 0, false);
        }

        return $meta;
    }

    
    private function fetch(bool $force = false): self {
        $this->info_data = [];
        $this->mime_data = [];

        if (!$this->isUri()) return $this;

        $url = $this->getPathname();

        if (!$force) {
            $meta = $this->getMeta();

            if (
                is_file($this->cache->file) &&
                (
                    !$this->cache->isExpired() ||
                    ($meta['http_code'] ?? null) != 200
                )
            ) return new static($this->cache->file, $this);
        }

        $attempt = 0;
        while ($attempt < static::config('fetch.max_attempts')) {
            try {
                $fopen = fopen($this->cache->file.'.temp', 'w');
                if (!$fopen) throw new \Exception();

                $curl = curl_init();
                curl_setopt_array($curl, [
                    CURLOPT_URL => $url,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_RETURNTRANSFER => false,
                    CURLOPT_HEADER => false,
                    CURLOPT_CONNECTTIMEOUT => static::config('fetch.connect_timeout'),
                    CURLOPT_TIMEOUT => static::config('fetch.timeout'),
                    CURLOPT_FILE => $fopen,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
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
                    @unlink($this->cache->file.'.temp');
                    throw new \RuntimeException ("cURL request failed for URL {$url} with error #{$error}: ".curl_strerror($error));
                }

                rename($this->cache->file.'.temp', $this->cache->file);

                $ttl = static::getTtl($headers);
                $cache_expires_in = time() + $ttl;

                $meta = array_merge(
                    $headers,
                    [
                        'effective_url' => $headers['effective_url'] ?? $url,
                        'content_length' => $headers['content_length'] ?? filesize($this->cache->file),
                        'original_url' => $url,
                        'fetched_at' => time(),
                        'cache_ttl' => $ttl,
                        'expires_in' => $cache_expires_in,
                        'headers_cache_expires_in' => $cache_expires_in,
                    ]
                );

                $this->cache->setMeta($meta, 0, false);

                return new static($this->cache->file, $this);
            } catch (\Throwable $th) {
                $attempt++;
            }
        }

        throw $th;
    }

    
    public function getSize(string $unit = 'b') {
        $bytes = $this->isUri()
            ? (int)$this->getMeta()['content-length']
            : $this->info->getSize();

        return ($divisor = ['kb' => 1024, 'mb' => 1048576, 'gb' => 1073741824][strtolower($unit)] ?? null)
            ? $bytes / $divisor
            : $bytes;
    }

    public function isUri(): bool {
        return $this->isUri;
    }

    public static function purgeAllCache(): void {
        Cache::make('', [
            'dir' => static::config('cache.dir'),
            'driver' => 'filesystem',
        ])->purgeBase();
    }

    
    public function purgeCache(): self {
        $this->cache->purge();

        if ($original = $this->getOriginal())
            $original->purgeCache();
                
        return $original ?? $this;
    }

    public function getMime(): string {
        $extension = $this->getExtension();
        $path = $this->getPathname();

        if (isset(static::config('mimes.extensions')[$extension]))
            return static::config('mimes.extensions')[$extension];

        if ($this->isUri())
            return $this->getMeta()['content-type'] ?: '';

        $mime_type = '';

        if (function_exists('finfo_open')) {
            $f = finfo_open(FILEINFO_MIME_TYPE);
            if ($f) {
                $mime_type = finfo_file($f, $path);
                finfo_close($f);
            }
        }
        
        if ($mime_type == '')
            $mime_type = @mime_content_type($path) ?: '';

        return $mime_type;
    }

    public function getServiceName(): string {
        return (new \ReflectionClass($this->mime))->isAnonymous()
            ? 'Default'
            : get_class($this->mime);
    }

    public function getRelativePath(string $root = ''): string {
        if ($this->isUri())
            return $this->getPathname();

        return str_replace(Main::preparePath('~'.$root, 1), '', $this->getPathname());
    }

    private function exists(): bool {
        return $this->isUri()
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

    public function getRaw() {
        $instance = $this->isUri()
            ? $this->fetch()
            : $this;

        return file_get_contents($instance->getRealPath());
    }

    public function getContents() {
        $raw = $this->getRaw();

        return $this->mime->get($raw);
    }

    public function putContents($raw, int $flags = 0) {
        $instance = $this->isUri()
            ? $this->fetch()
            : $this;

        $data = $instance->mime->set($raw, $flags);
        
        $dir = $instance->getDirectory();

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);

            if (!is_dir($dir))
                throw new \RuntimeException("Cannot create cache directory");
        }

        return file_put_contents($instance->getRealPath() ?: $instance->getPathname(), $data, $flags);
    }

}
