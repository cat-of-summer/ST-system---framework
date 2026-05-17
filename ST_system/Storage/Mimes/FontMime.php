<?php

namespace ST_system\Storage\Mimes;

use ST_system\Storage\Mimes\Mime;
use ST_system\Storage\File;
use ST_system\Traits\HasConfig;
use ST_system\Cache\Manager as Cache;

class FontMime extends Mime {

    use HasConfig;

    protected static function getDefaultConfig(): array {
        return [
            'weight' => [
                'thin' => 100,
                'extralight' => 200,
                'light' => 300,
                'regular' => 400,
                'medium' => 500,
                'semibold' => 600,
                'extrabold' => 800,
                'bold' => 700,
                'black' => 900,
            ],
            'style' => [
                'italic' => 'italic',
                'oblique' => 'oblique'
            ],
            'format' => [
                'woff2' => 'woff2',
                'woff'  => 'woff',
                'ttf'   => 'truetype',
                'otf'   => 'opentype',
                'eot'   => 'embedded-opentype'
            ],
            'cache_dir' => ''
        ];
    }

    private Cache $cache;
    private ?array $metadata = null;

    protected function __init(): void {
        $this->cache = Cache::make($this->file->getPathname(), [
            'driver' => 'filesystem',
            'dir' => static::config('cache_dir') ?: File::config('cache.dir'),
            'file' => $this->file->getFilename(),
            'ttl' => -1
        ]);
    }

    protected function parseFilename(): array {
        $file = $this->file->getBasename();
        $extension = $this->file->getExtension();

        $weight = static::config('weight.regular');
        foreach (static::config('weight') as $key => $w)
            if (stripos($file, $key) !== false) {
                $weight = $w;
                break;
            }

        $style = 'normal';
        foreach (static::config('style') as $key => $s)
            if (stripos($file, $key) !== false) {
                $style = $s;
                break;
            }

        return [
            'weight' => $weight,
            'style' => $style,
            'format' => static::config("format.{$extension}") ?: $extension,
            'family' => trim(preg_replace( '/(?<!^)(?=[A-Z])/', ' ', preg_match('/^[A-Za-z]+/', $file, $matches) ? $matches[0] : $file)),
            'display' => 'swap'
        ];
    }

    public function getMetadata(): array {
        if ($this->metadata !== null) 
            return $this->metadata;

        $meta = $this->parseFilename();

        foreach ($this->readBinaryMetadata() as $k => $v)
            if ($v !== null && $v !== '') $meta[$k] = $v;

        $this->metadata = $meta;
        
        return $this->metadata;
    }

    protected function readBinaryMetadata(): array {
        $ext = strtolower($this->file->getExtension());
        if (!in_array($ext, ['ttf', 'otf', 'woff'], true))
            return [];

        $cache = $this->cache->make($this->file->getPathname(), [
            'file' => $this->file->getBasename().'.fontmeta'
        ]);

        $src_mtime = $this->file->is_uri ? 0 : (int)@filemtime((string)$this->file->getRealPath());

        if ($cache->exists() && ($cache->getMeta()['src_mtime'] ?? -1) >= $src_mtime)
            return $cache->get() ?: [];

        $meta = [];
        try {
            $blob = $this->file->getRaw();
            $table = '';

            if ($ext === 'woff') {
                if (substr($blob, 0, 4) !== 'wOFF')
                    throw new \RuntimeException('Not a WOFF file');

                $numTables = unpack('n', substr($blob, 12, 2))[1];
                for ($i = 0; $i < $numTables; $i++) {
                    $entry = substr($blob, 44 + $i * 20, 20);
                    if (substr($entry, 0, 4) !== 'name') continue;

                    $offset     = unpack('N', substr($entry, 4, 4))[1];
                    $compLength = unpack('N', substr($entry, 8, 4))[1];
                    $origLength = unpack('N', substr($entry, 12, 4))[1];
                    $raw        = substr($blob, $offset, $compLength);
                    $table      = $compLength < $origLength ? (gzuncompress($raw) ?: $raw) : $raw;
                    break;
                }
            } else {
                $numTables = unpack('n', substr($blob, 4, 2))[1];
                for ($i = 0; $i < $numTables; $i++) {
                    $entry = substr($blob, 12 + $i * 16, 16);
                    if (substr($entry, 0, 4) !== 'name') continue;

                    $offset = unpack('N', substr($entry, 8, 4))[1];
                    $length = unpack('N', substr($entry, 12, 4))[1];
                    $table  = substr($blob, $offset, $length);
                    break;
                }
            }

            if (strlen($table) >= 6) {
                $count        = unpack('n', substr($table, 2, 2))[1];
                $stringOffset = unpack('n', substr($table, 4, 2))[1];

                $candidates = [];
                for ($i = 0; $i < $count; $i++) {
                    $rec = substr($table, 6 + $i * 12, 12);
                    if (strlen($rec) < 12) continue;

                    $platformID = unpack('n', substr($rec, 0, 2))[1];
                    $encodingID = unpack('n', substr($rec, 2, 2))[1];
                    $languageID = unpack('n', substr($rec, 4, 2))[1];
                    $nameID     = unpack('n', substr($rec, 6, 2))[1];
                    $length     = unpack('n', substr($rec, 8, 2))[1];
                    $offset     = unpack('n', substr($rec, 10, 2))[1];

                    if (!in_array($nameID, [1, 2, 16, 17], true)) continue;

                    $str = $this->decodeNameString(substr($table, $stringOffset + $offset, $length), $platformID, $encodingID);
                    if ($str === '') continue;

                    $score = ($platformID === 3 ? 10 : ($platformID === 0 ? 5 : ($platformID === 1 ? 1 : 0)))
                           + ($languageID === 0x0409 || $languageID === 0 ? 2 : 0);

                    if (!isset($candidates[$nameID]) || $candidates[$nameID]['score'] < $score)
                        $candidates[$nameID] = ['score' => $score, 'value' => $str];
                }

                $family    = $candidates[16]['value'] ?? $candidates[1]['value'] ?? '';
                $subfamily = $candidates[17]['value'] ?? $candidates[2]['value'] ?? '';

                if ($family !== '') $meta['family'] = $family;

                if ($subfamily !== '') {
                    $sub = strtolower($subfamily);
                    $weights = static::config('weight');
                    uksort($weights, fn($a, $b) => strlen($b) - strlen($a));
                    foreach ($weights as $key => $w)
                        if (strpos($sub, $key) !== false) { $meta['weight'] = $w; break; }

                    $meta['style'] = 'normal';
                    foreach (static::config('style') as $key => $s)
                        if (strpos($sub, $key) !== false) { $meta['style'] = $s; break; }
                }
            }
        } catch (\Throwable $e) {}

        $cache->set($meta);
        $cache->setMeta(['src_mtime' => $src_mtime]);
        return $meta;
    }

    private function decodeNameString(string $raw, int $platformID, int $encodingID): string {
        if ($platformID === 3 || $platformID === 0)
            return trim((string)@mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE'));

        if ($platformID === 1)
            return trim((string)@mb_convert_encoding($raw, 'UTF-8', 'Windows-1252'));

        return trim($raw);
    }

    public function toHTML(array $config = []): string {
        $config = array_merge(
            $this->getMetadata(),
            $config
        );

        return "
            <style>
                @font-face {
                    font-family: '{$config['family']}';
                    src: url('{$this->file->getRelativePath()}') format('{$config['format']}');
                    font-weight: {$config['weight']};
                    font-style: {$config['style']};
                    font-display: {$config['display']};
                }
            </style>
        ";
    }
}
