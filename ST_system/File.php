<?php

namespace ST_system;

class File {

    private const config = [
        'cache_dir' => '~/cache/',
        'connect_timeout' => 10,
        'timeout' => 300,
        'default_ttl' => 3600,
        'max_attempts' => 15
    ];

    private static $cache_dir = '';

    private $file = null;
    private $uri = null;

    private $isUri;

    private static function prepare_path(string $file_name): string {
        if (strpos($file_name, '~') === 0)
            $file_name = $_SERVER['DOCUMENT_ROOT'].DIRECTORY_SEPARATOR.trim($file_name, DIRECTORY_SEPARATOR.'~');
        elseif (strpos($file_name, DIRECTORY_SEPARATOR) !== 0)
            $file_name = __DIR__.DIRECTORY_SEPARATOR.trim($file_name, DIRECTORY_SEPARATOR);

        return $file_name;
    }

    private static function get_ttl(array $headers = []) {
        $ttl = self::config['default_ttl'];

        if (!empty($headers['headers']['cache-control'])) {
            if (preg_match('/\bmax-age\s*=\s*(\d+)/i', $headers['headers']['cache-control'], $m))
                $ttl = (int)$m[1];
            elseif (preg_match('/\bs-maxage\s*=\s*(\d+)/i', $headers['headers']['cache-control'], $m))
                $ttl = (int)$m[1];
            elseif (preg_match('/\b(no-cache|no-store)\b/i', $headers['headers']['cache-control']))
                $ttl = 0;
        } elseif (!empty($headers['headers']['expires'])) {
            $expires = strtotime($headers['headers']['expires']);

            if ($expires !== false)
                $ttl = max($expires - time(), 0);
        }

        return (int)$ttl;
    }

    public static function getCacheDirectory() {
        if (self::$cache_dir == '') {
            self::$cache_dir = self::prepare_path(self::config['cache_dir']);

            if (!is_dir(self::$cache_dir)) {
                mkdir(self::$cache_dir, 0775, true);

                if (!is_dir(self::$cache_dir))
                    throw new \RuntimeException("Cannot create cache directory");
            }
        }

        return self::$cache_dir;
    }
    
    public static function purgeAllCache() {
        $dir = self::getCacheDirectory();

        if (!is_dir($dir)) return;

        $it = new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($files as $file)
            if ($file->isDir())
                rmdir($file->getPathname());
            else
                unlink($file->getPathname());
    }

    public static function find(mixed $input, array $params = []): array {


        return [];
    }

    public function __construct(string $file_name) {
        $this->isUri = (bool)filter_var($file_name, FILTER_VALIDATE_URL);

        if ($this->isUri)
            $this->uri = new \SplFileInfo($file_name);
        else
            $this->file = new \SplFileInfo(self::prepare_path($file_name));
    }

    public function __call(string $name, array $args) { return ($this->file ?? $this->uri)->{$name}(...$args); }

    public function __get($name) {
        switch ($name) {
            case 'file':
                return $this->file;
            case 'uri':
                return $this->uri;
            default:
                return null;
        }
    }

    public function isUri(): bool { return $this->isUri; }

    public function fetch(bool $force = false): self {
        if (!$this->isUri()) return $this;

        $meta = [];
        $url = $this->uri->getPathname();

        $cache_key = md5($url);
        $cache_directory = self::getCacheDirectory().DIRECTORY_SEPARATOR.$cache_key.DIRECTORY_SEPARATOR;

        if (!is_dir($cache_directory)) {
            mkdir($cache_directory, 0775, true);

            if (!is_dir($cache_directory))
                throw new \RuntimeException("Cannot create cache directory");
        }

        $cache_meta_filename = $cache_directory.'metadata';

        $lock = fopen($cache_meta_filename.'.lock', 'c');
        if ($lock === false) throw new \RuntimeException("Cannot open lock file {$cache_meta_filename}.lock");
        flock($lock, LOCK_EX);

        if (!$force) {
            if (is_file($cache_meta_filename))
                $meta = @json_decode(@file_get_contents($cache_meta_filename), true) ?: [];
            
            if (!empty($meta['filename']) && is_file($cache_directory.$meta['filename']) && $meta['cache_expires_in'] ?? 0 > time()) {
                flock($lock, LOCK_UN);
                fclose($lock);
                @unlink($cache_meta_filename.'.lock');

                $this->file = new \SplFileInfo($cache_directory.$meta['filename']);
                
                return $this;
            }

            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL => $url,
                CURLOPT_NOBODY => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_CONNECTTIMEOUT => self::config['connect_timeout'],
                CURLOPT_TIMEOUT => min(30, self::config['timeout']),
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => array_filter([
                    !empty($meta['etag']) ? 'If-None-Match: '.$meta['etag'] : null,
                    !empty($meta['last_modified']) ? 'If-Modified-Since: '.$meta['last_modified'] : null
                ]),
            ]);

            $response = curl_exec($curl);
            $error = curl_errno($curl);
            $info = curl_getinfo($curl);

            curl_close($curl);

            if ($error)
                throw new \RuntimeException("cURL request failed for URL {$url} with error #{$error}: ".curl_strerror($error));

            $headers = [];
            foreach (preg_split('#\r\n#', trim(explode("\r\n\r\n", ($response ?? '')."\r\n\r\n", 2)[0])) as $line) {
                if (strpos($line, ':') !== false) {
                    [$k, $v] = explode(':', $line, 2);
                    $headers[strtolower(trim($k))] = trim($v);
                } else
                    $headers['status-line'] = $line;
            }

            $headers = [
                'http_code' => $info['http_code'] ?? null,
                'effective_url' => $info['url'] ?? $url,
                'content_length' => $info['download_content_length'] ?? null,
                'headers' => $headers
            ];

            if (($headers['http_code'] ?? null) == 304 && !empty($meta['filename']) && is_file($cache_directory.$meta['filename'])) {
                $meta['cache_expires_in'] = time() + self::get_ttl($headers);

                @file_put_contents($cache_meta_filename, json_encode($meta));

                flock($lock, LOCK_UN);
                fclose($lock);
                @unlink($cache_meta_filename.'.lock');

                $this->file = new \SplFileInfo($cache_directory.$meta['filename']);

                return $this;
            }

        }

        $attempt = 0;
        while ($attempt < self::config['max_attempts']) {
            try {
                $fopen = fopen($cache_meta_filename.'.temp', 'w');
                if (!$fopen) throw new \Exception();

                $headers = [];
                $curl = curl_init();
                curl_setopt_array($curl, [
                    CURLOPT_URL => $url,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_RETURNTRANSFER => false,
                    CURLOPT_HEADER => false,
                    CURLOPT_CONNECTTIMEOUT => self::config['connect_timeout'],
                    CURLOPT_TIMEOUT => self::config['timeout'],
                    CURLOPT_FILE => $fopen,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                ]);

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
                    throw new \Exception();
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

                $ttl = self::get_ttl($headers);

                $meta = [
                    'original_url' => $url,
                    'effective_url' => $headers['effective_url'] ?? $url,
                    'http_code' => $headers['http_code'] ?? null,
                    'content_length' => $headers['content_length'] ?? filesize($filename),
                    'content_type' => $headers['content-type'] ?? null,
                    'etag' => $headers['etag'] ?? $meta['etag'] ?? null,
                    'last_modified' => $headers['last-modified'] ?? $meta['last_modified'] ?? null,
                    'fetched_at' => time(),
                    'cache_ttl' => $ttl,
                    'cached_until' => time() + $ttl,
                    'headers' => $headers,
                    'filename' => $cache_filename
                ];

                @file_put_contents($cache_meta_filename, json_encode($meta));

                flock($lock, LOCK_UN);
                fclose($lock);
                @unlink($cache_meta_filename.'.lock');

                $this->file = new \SplFileInfo($filename);

                return $this;

            } catch (\Throwable $th) {
                $attempt++;
            }
        }

        return $this;
    }

    public function purgeCache(): self {
        if (!$this->isUri()) return $this;

        $dir = self::getCacheDirectory().DIRECTORY_SEPARATOR.md5($this->uri->getPathname());

        if (!is_dir($dir)) return $this;

        $it = new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);

        $lock_filename = $dir.DIRECTORY_SEPARATOR.'metadata.lock';
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
}