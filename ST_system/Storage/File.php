<?php

namespace ST_system\Storage;

use ST_system\Traits\HasConfig;
use ST_system\Storage\Mimes;

final class File {

    use HasConfig;

    private static array $CONFIG = [
        'cache_dir' => '~/cache/',
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
            'resolvers' => [
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
            'default_ttl' => 3600,
            'max_attempts' => 15,
        ],
        'find' => [
            'max_files' => 50,
            'sym_links' => false,
            'recursive' => true,
            'hidden_files' => false,
        ]
    ];

    public static function prepare_path(string $path): string {
        if (strpos($path, '~') === 0)
            $path = $_SERVER['DOCUMENT_ROOT'].'/'.trim($path, '/~');
        elseif (strpos($path, '/') !== 0)
            $path = __DIR__.'/'.trim($path, '/');
        else
            $path = rtrim($path, '/');

        return $path;
    }

    public static function make(string $path) { return new static($path); }

    private bool $isUri;
    private \SplFileInfo $info;
    private Mimes\Mime $mime_instance;
    private $original;

    private function __construct(string $path, $original = null) {
        static $is_cache_init = false;

        if (!$is_cache_init) {
            static::set_config([
                'cache_dir' => static::prepare_path(static::config('cache_dir'))
            ]);

            if (!is_dir(static::config('cache_dir'))) {
                @mkdir(static::config('cache_dir'), 0775, true);

                if (!is_dir(static::config('cache_dir')))
                    throw new \RuntimeException("Cannot create cache directory");
            }

            $is_cache_init = true;
        }

        $this->isUri = (bool)filter_var($path, FILTER_VALIDATE_URL);
        $this->info = new \SplFileInfo($this->isUri ? $path : static::prepare_path($path));
        $this->original = $original ?? $this;

        $mime = $this->getMime();

        $this->mime_instance = (
            ($matched = array_filter(
                static::config('mimes.resolvers'),
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
            default:
                throw new \Exception("Method {$name} not found");
        }
    }

    public function __call(string $name, array $args) {
        switch ($name) {
            case 'fetch':
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
            case 'getSize':
                if ($this->isUri()) return (int)$this->getHeaders()['content-length'];
                break;
            case 'getBasename':
                if (empty($args)) return $this->info->getBasename('.'.$this->getExtension());
        }

        return is_callable([$this->info, $name])
            ? $this->info->{$name}(...$args)
            : (isset($this->mime_instance) && is_callable([$this->mime_instance, $name])
                ? $this->mime_instance->{$name}(...$args)
                : throw new \Exception("Method {$name} not found"));
    }

    public function getOriginal(): static {
        return $this->original;
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
        $results = [];

        $config = [
            ...static::config('find'),
            ...$config
        ];

        if (isset($config['extension']) && !is_array($config['extension']))
            $config['extension'] = [$config['extension']];

        if (!is_array($input)) $input = [$input];

        array_walk($input, function ($i) use (&$results, $config) {
            
            $i = static::prepare_path($i);

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
                            if ($p != '' && str_starts_with($p, '.')) {
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
        $ttl = static::config('fetch.default_ttl');

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

    private function getHeaders(bool $force = false): array {
        if (!$this->isUri()) return [];

        $url = $this->getPathname();

        $cache_key = md5($url);
        $cache_directory = static::config('cache_dir').'/'.$cache_key.'/';

        if (!is_dir($cache_directory)) {
            mkdir($cache_directory, 0775, true);

            if (!is_dir($cache_directory))
                throw new \Exception("Cannot create cache directory");
        }

        $cache_meta_filename = $cache_directory.'metadata';

        $lock = fopen($cache_meta_filename.'.lock', 'c');
        if ($lock === false) throw new \Exception("Cannot open lock file {$cache_meta_filename}.lock");
        flock($lock, LOCK_EX);

        $meta = is_file($cache_meta_filename)
            ? (@json_decode(@file_get_contents($cache_meta_filename), true) ?: [])
            : [];

        if (
            ($meta['headers_cache_expires_in'] ?? 0) > time()
            && !$force
        ) {
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($cache_meta_filename.'.lock');

            return $meta;
        }

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

            $headers = [
                ...$headers,
                'http_code' => $info['http_code'] ?? null,
                'effective_url' => $info['url'] ?? $url,
                'content_length' => ($info['download_content_length'] ?? 0) > 0 
                    ? $info['download_content_length']
                    : $headers['content_length'] ?? 0
            ];

            $meta = [
                ...$meta,
                ...$headers,
                'headers_cache_expires_in' => time() + static::getTtl($headers)
            ];

            if (($meta['http_code'] ?? null) != 200 && !empty($meta['filename']) && is_file($cache_directory.$meta['filename']))
                $meta['file_cache_expires_in'] = time() + static::getTtl($meta);
            
            @file_put_contents($cache_meta_filename, json_encode($meta));
        }

        flock($lock, LOCK_UN);
        fclose($lock);
        @unlink($cache_meta_filename.'.lock');

        return $meta;
    }

    private function fetch(bool $force = false): static {
        if (!$this->isUri()) return $this;

        $meta = [];
        $url = $this->getPathname();

        $cache_key = md5($url);
        $cache_directory = static::config('cache_dir').'/'.$cache_key.'/';

        if (!is_dir($cache_directory)) {
            mkdir($cache_directory, 0775, true);

            if (!is_dir($cache_directory))
                throw new \Exception("Cannot create cache directory");
        }

        $cache_meta_filename = $cache_directory.'metadata';

        if (!$force) {
            if (is_file($cache_meta_filename))
                $meta = $this->getHeaders($force);
            
            if (
                !empty($meta['filename']) &&
                is_file($cache_directory.$meta['filename']) &&
                (
                    ($meta['file_cache_expires_in'] ?? 0) > time() ||
                    ($meta['http_code'] ?? null) != 200
                )
            ) return new static($cache_directory.$meta['filename'], $this);
        }

        $lock = fopen($cache_meta_filename.'.lock', 'c');
        if ($lock === false) throw new \Exception("Cannot open lock file {$cache_meta_filename}.lock");
        flock($lock, LOCK_EX);

        $attempt = 0;
        while ($attempt < static::config('fetch.max_attempts')) {
            try {
                $fopen = fopen($cache_meta_filename.'.temp', 'w');
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
                    @unlink($cache_meta_filename.'.temp');
                    throw new \RuntimeException ("cURL request failed for URL {$url} with error #{$error}: ".curl_strerror($error));
                }

                $cache_filename = '';
                if (isset($headers['content-disposition']) && preg_match('/filename="?([^"]+)"?/i', $headers['content-disposition'], $m))
                    $cache_filename = $m[1];
                
                if (!$cache_filename)
                    $cache_filename = basename(parse_url($info['url'] ?? $url, PHP_URL_PATH));

                if (!$cache_filename)
                    $cache_filename = 'file.bin';
                
                $filename = $cache_directory.$cache_filename;

                if ($cache_meta_filename == $filename)
                    $filename = $cache_directory.'_'.$cache_filename;

                rename($cache_meta_filename.'.temp', $filename);

                $ttl = static::getTtl($headers);
                $cache_expires_in = time() + $ttl;

                $meta = [
                    ...$headers,
                    'effective_url' => $headers['effective_url'] ?? $url,
                    'content_length' => $headers['content_length'] ?? filesize($filename),
                    'original_url' => $url,
                    'fetched_at' => time(),
                    'cache_ttl' => $ttl,
                    'file_cache_expires_in' => $cache_expires_in,
                    'headers_cache_expires_in' => $cache_expires_in,
                    'filename' => $cache_filename
                ];

                @file_put_contents($cache_meta_filename, json_encode($meta));

                flock($lock, LOCK_UN);
                fclose($lock);
                @unlink($cache_meta_filename.'.lock');

                return new static($filename, $this);
            } catch (\Throwable $th) {
                $attempt++;
            }
        }

        flock($lock, LOCK_UN);
        fclose($lock);
        @unlink($cache_meta_filename.'.lock');

        throw $th;
    }

    public function isUri(): bool {
        return $this->isUri;
    }

    public static function purgeAllCache(): void {
        $dir = static::config('cache_dir');

        if (!is_dir($dir)) return;

        $it = new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($files as $file)
            if ($file->isDir())
                rmdir($file->getPathname());
            else
                unlink($file->getPathname());
    }

    public function purgeCache(): static {
        if (!$this->isUri()) return $this;

        $dir = static::config('cache_dir').'/'.md5($this->getPathname());

        if (!is_dir($dir)) return $this;

        $it = new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);

        $lock_filename = $dir.'/'.'metadata.lock';
        $lock = fopen($lock_filename, 'c');

        if ($lock === false) throw new \RuntimeException("Cannot open lock file {$lock_filename}");
        flock($lock, LOCK_EX);

        foreach ($files as $file)
            if ($file->isDir())
                rmdir($file->getPathname());
            else
                unlink($file->getPathname());

        flock($lock, LOCK_UN);
        fclose($lock);
        @unlink($$lock_filename);

        rmdir($dir);

        return $this;
    }

    public function getMime(): string {
        $extension = $this->getExtension();
        $path = $this->getPathname();

        if (isset(static::config('mimes.extensions')[$extension]))
            return static::config('mimes.extensions')[$extension];

        if ($this->isUri())
            return $this->getHeaders()['content-type'] ?: '';

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

    public function getRelativePath(string $root = '~'): string {
        if ($this->isUri())
            return $this->getPathname();

        return str_replace(static::prepare_path($root), '/', $this->getPathname());
    }

    public function exists(): bool {
        return $this->isUri()
            ? false
            : file_exists($this->getPathname());
    }

    public function getDirectory(): string {
        return $this->isDir()
            ? $this->getPathname()
            : $this->getPath();
    }

    public function getRaw() {
        $instance = $this->isUri()
            ? $this->fetch()
            : $this;

        return file_get_contents($instance->getRealPath());
    }

    public function getContents() {
        $raw = $this->getRaw();

        return $this->mime_instance->get($raw);
    }

    public function putContents($raw, int $flags = 0) {
        $instance = $this->isUri()
            ? $this->fetch()
            : $this;

        $data = $this->mime_instance->set($raw, $flags);

        return file_put_contents($instance->getRealPath() ?: $instance->getPathname(), $data, $flags);
    }

}