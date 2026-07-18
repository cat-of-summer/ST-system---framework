<?php

namespace ST_system\HTTP;

use ST_system\Storage\Resource;
use ST_system\Storage\File;
use ST_system\Storage\Traits\HasMime;
use ST_system\Main;
use ST_system\Rule;

final class UploadedFile extends Resource implements \ArrayAccess {

    use HasMime;

    private string $tmpPath;
    private string $clientType;
    private int    $fileError;
    private int    $fileSize;

    protected function __construct(array $entry) {
        $this->tmpPath    = (string)($entry['tmp_name'] ?? '');
        $this->clientType = (string)($entry['type'] ?? '');
        $this->fileError  = (int)($entry['error'] ?? UPLOAD_ERR_OK);
        $this->fileSize   = (int)($entry['size'] ?? 0);

        parent::__construct([
            'name' => (string)($entry['name'] ?? ''),
            'id'   => $this->tmpPath,
        ]);
    }

    public static function fetch(?string $field = null): array {
        static $data = null;

        if ($data === null) {
            $data = [];

            foreach ($_FILES as $name => $file) {
                $data[$name] = [];

                foreach (is_array($file['name']) ? $file['name'] : [$file['name']] as $i => $filename) {
                    $error = is_array($file['error']) ? $file['error'][$i] : $file['error'];

                    if ($error === UPLOAD_ERR_NO_FILE)
                        continue;

                    $data[$name][] = static::make([
                        'name'     => $filename,
                        'tmp_name' => is_array($file['tmp_name']) ? $file['tmp_name'][$i] : $file['tmp_name'],
                        'type'     => is_array($file['type']) ? $file['type'][$i] : $file['type'],
                        'size'     => is_array($file['size']) ? $file['size'][$i] : $file['size'],
                        'error'    => $error,
                    ]);
                }
            }
        }

        return $field === null ? $data : ($data[$field] ?? []);
    }

    public function isValid(): bool {
        return !$this->hasError()
            && $this->tmpPath !== ''
            && is_uploaded_file($this->tmpPath);
    }

    public function hasError(): int { return $this->fileError; }

    public function getPath(): string { return $this->tmpPath; }

    public function getSize(string $unit = 'b') {
        return Main::formatBytes($this->fileSize, $unit);
    }

    public function getClientType(): string {
        return $this->clientType;
    }

    public function getMime(): string {
        $mime = parent::getMime();
        if ($mime !== '') return $mime;

        return $this->detectMime($this->tmpPath);
    }

    public function getRaw(bool $force = false) {
        if ($this->tmpPath === '' || !is_file($this->tmpPath))
            throw new \LogicException('Uploaded file has no readable temp content');

        return file_get_contents($this->tmpPath);
    }

    public static function filter($value, callable $keep): array {
        $single = $value instanceof self;
        $list   = $single ? [$value] : (is_array($value) ? $value : []);

        $kept = [];
        foreach ($list as $item)
            if ($item instanceof self && $keep($item))
                $kept[] = $item;

        return [$kept, $single];
    }

    public static function registerRules(): void {

        Rule::filtered(fn($v, array $p) => static::filter($v, fn(self $f) => $f->isValid()))
        ->order(500)
        ->handleError(fn($v) => 'The file failed to upload or is invalid')
        ->alias('files');

        Rule::filtered(fn($v, array $p) => static::filter($v, function(self $f) use ($p): bool {
            if (!$f->isValid()) return false;

            $mime = $f->getMime();

            foreach ($p as $pattern) {
                if (substr($pattern, -2) === '/*') {
                    if (strpos($mime, substr($pattern, 0, -1)) === 0) return true;
                } elseif ($mime === $pattern) {
                    return true;
                }
            }

            return false;
        }))
        ->order(600)
        ->handleError(fn($v) => 'The file MIME type is not allowed')
        ->alias('mime')
        ->alias('mimes');

        Rule::filtered(fn($v, array $p) => static::filter($v, fn(self $f) =>
            $f->isValid() && in_array(strtolower($f->getExtension()), array_map('strtolower', $p), true)
        ))
        ->order(600)
        ->handleError(fn($v) => 'The file extension is not allowed')
        ->alias('extension');

        Rule::filtered(fn($v, array $p) => static::filter($v, fn(self $f) =>
            $f->isValid() && $f->getSize('b') <= (float)($p[0] ?? 0) * 1024
        ))
        ->order(600)
        ->handleError(fn($v) => 'The file exceeds the allowed size')
        ->alias('filesize');
    }

    protected function attributeMap(): array {
        return array_merge(parent::attributeMap(), [
            'mime'  => ['getMime'],
            'size'  => ['getSize'],
            'error' => ['hasError'],
            'path'  => ['getPath'],
        ]);
    }

    public function save(string $destination): File {
        if (!$this->isValid())
            throw new \RuntimeException("Cannot save an invalid uploaded file (error {$this->fileError})");

        $target = File::make($destination);
        $path   = $target->pathname;
        $dir    = dirname($path);

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir))
            throw new \RuntimeException("Cannot create directory {$dir}");

        if (!@move_uploaded_file($this->tmpPath, $path))
            throw new \RuntimeException("Failed to move uploaded file to {$path}");

        $this->tmpPath = $path;

        return $target;
    }

    public function toArray(): array {
        return [
            'name'      => $this->getFilename(),
            'tmp_name'  => $this->tmpPath,
            'type'      => $this->clientType,
            'size'      => $this->fileSize,
            'error'     => $this->fileError,
            'extension' => strtolower($this->getExtension()),
        ];
    }

    public function offsetExists($offset): bool {
        return array_key_exists($offset, $this->toArray());
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($offset) {
        return $this->toArray()[$offset] ?? null;
    }

    public function offsetSet($offset, $value): void {
        throw new \LogicException('UploadedFile is read-only');
    }

    public function offsetUnset($offset): void {
        throw new \LogicException('UploadedFile is read-only');
    }
}
