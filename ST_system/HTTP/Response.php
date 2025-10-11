<?php

namespace ST_system\HTTP;

class Response {
    private $status = 200;
    private $headers = [];

    private $content = null;
    private $stream_callback = null;
    private $file_path = null;

    public function __construct() {}

    final public static function create(...$args): self {
        return new static(...$args);    
    }

    final public function header(string $key, string $value): self {
        $parts = preg_split('/[\\-_ ]+/', $key);
        $parts = array_map(function ($p) {
            return ucfirst(strtolower($p));
        }, $parts);

        $this->headers[implode('-', $parts)] = $value;
        return $this;
    }

    final public function headers(array $headers): self {
        array_walk($headers, fn($value, $key) => $this->header($key, $value));

        return $this;
    }

    final public function status(int $code): self {
        $this->status = $code;
        return $this;
    }

    final public function redirect(string $url, int $status = 302): self {
        $this->status($status);
        $this->header('Location', $url);

        return $this;
    }

    final public function html(string $html, int $status = 200): self {
        $this->status($status);
        $this->header('Content-Type', 'text/html; charset=UTF-8');

        $this->content = $html;

        return $this;
    }

    final public function json($data, int $status = 200, int $json_options = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES): self {
        $this->status($status);
        $this->header('Content-Type', 'application/json; charset=UTF-8');

        $encoded = @json_encode($data, $json_options);

        if ($encoded === false)
            throw new \RuntimeException('JSON encoding error: ' . json_last_error_msg());
        
        $this->content = $encoded;

        return $this;
    }

    final public function file(string $full_path, string $file_name = '', bool $download = false): self {
        if (!is_file($full_path) || !is_readable($full_path))
            throw new \InvalidArgumentException("File not found or not readable: {$full_path}");
        
        $this->file_path = $full_path;

        $file_name = str_replace('"', "'", $file_name != '' ? $file_name : basename($full_path));
        $mime_type = 'application/octet-stream';

        if (function_exists('finfo_open')) {
            $f = finfo_open(FILEINFO_MIME_TYPE);
            if ($f) {
                $m = finfo_file($f, $full_path);
                finfo_close($f);
                if ($m !== false)
                    $mime_type = $m;
                
            }
        } elseif (function_exists('mime_content_type')) {
            $m = mime_content_type($full_path);
            if ($m !== false)
                $mime_type = $m;
        }

        $this->header('Content-Type', $mime_type);

        if ($download)
            $this->header('Content-Disposition', 'attachment; filename="'.$file_name.'"');
        else
            $this->header('Content-Disposition', 'inline; filename="'.$file_name.'"');

        $this->header('Content-Length', (string)filesize($full_path));
        $this->header('ETag', '"'.md5($full_path.'|'.filemtime($full_path).'|'.filesize($full_path)).'"');
        $this->header('Last-Modified', (new \DateTime())->setTimestamp(filemtime($full_path))->format('D, d M Y H:i:s').' GMT');

        return $this;
    }

    final public function download(string $full_path, string $file_name = ''): self {
        return $this->file($full_path, $file_name, true);
    }

    final public function stream(callable $callback, int $status = 200): self {
        $this->stream_callback = $callback;
        $this->status($status);

        return $this;
    }

    final public function stream_download(callable $callback, string $file_name, int $status = 200): self {
        $this->header('Content-Type', 'application/octet-stream');
        $this->header('Content-Disposition', 'attachment; filename="'.str_replace('"', "'", $file_name).'"');

        return $this->stream($callback, $status);
    }

    final public function send(): void {
        if (headers_sent($file, $line))
            throw new \RuntimeException("Cannot send response, headers already sent in {$file}:{$line}");
        
        http_response_code($this->status);
        foreach ($this->headers as $k => $v)
            header($k.': '.$v, true);
        
        if (is_callable($this->stream_callback))
            call_user_func($this->stream_callback);
        elseif ($this->file_path != null) {
            $fp = fopen($this->file_path, 'rb');

            if ($fp === false)
                throw new \RuntimeException("Failed to open file: {$this->file_path}");
            
            @set_time_limit(0);

            while (!feof($fp)) {
                $buffer = fread($fp, 8192);

                if ($buffer === false) break;

                echo $buffer;

                if (function_exists('flush')) flush();
            }

            fclose($fp);
        } else
            echo $this->content;

        exit;
    }
}