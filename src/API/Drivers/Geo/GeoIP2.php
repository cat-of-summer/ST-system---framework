<?php

namespace ST_system\API\Drivers\Geo;

final class GeoIP2 extends GeoDriver {

    private const METADATA_MARKER = "\xab\xcd\xefMaxMind.com";
    private const DATA_SEPARATOR  = 16;

    protected static function getDefaultConfig(): array {
        return array_merge(parent::getDefaultConfig(), [
            'endpoint' => 'https://geoip.maxmind.com/geoip/v2.1',
            'service'  => 'country',
            'edition'  => 'GeoLite2-Country',
        ]);
    }

    private string $account = '';
    private string $license = '';

    private ?string $loadedPath = null;
    private string $buf = '';
    private array $metadata = [];
    private int $nodeCount = 0;
    private int $recordSize = 0;
    private int $nodeByteSize = 0;
    private int $searchTreeSize = 0;
    private int $ipVersion = 0;
    private int $dataSectionStart = 0;
    private int $pointerBase = 0;
    private ?int $ipv4Start = null;

    protected function bootCredentials(string $credentials): void {
        if (strpos($credentials, ':') !== false)
            [$this->account, $this->license] = explode(':', $credentials, 2);
        else
            $this->license = $credentials;
    }

    protected function apiUrl(string $ip): string {
        return rtrim($this->getEndpoint(), '/') . '/' . static::config('service') . '/' . $ip;
    }

    protected function apiAuthHeader(): ?string {
        $auth = $this->account . ':' . $this->license;

        return $auth !== ':' ? 'Basic ' . base64_encode($auth) : null;
    }

    protected function normalizeApiResponse(array $resp): array {
        return $this->normalize($resp);
    }

    protected function dbFilename(): string {
        return static::config('edition') . '.mmdb';
    }

    protected function downloadUrl(): ?string {
        $url = (string)static::config('db_url');
        if ($url !== '') return $url;

        if ($this->license === '') return null;

        return 'https://download.maxmind.com/app/geoip_download?edition_id='
             . rawurlencode((string)static::config('edition'))
             . '&license_key=' . rawurlencode($this->license)
             . '&suffix=tar.gz';
    }

    protected function extract(string $archivePath, string $targetDir): ?string {
        $target = $targetDir . '/' . $this->dbFilename();

        $gz = $targetDir . '/' . static::config('edition') . '.tar.gz';
        if (!@copy($archivePath, $gz)) return null;

        $mmdb = null;
        try {
            foreach (new \RecursiveIteratorIterator(new \PharData($gz)) as $file) {
                if (substr(strtolower($file->getFilename()), -5) === '.mmdb') {
                    $mmdb = $file->getPathname();
                    break;
                }
            }
        } finally {
            @unlink($gz);
        }

        if ($mmdb === null) return null;

        @file_put_contents($target, file_get_contents($mmdb));

        return is_file($target) ? $target : null;
    }

    protected function dbVersion(string $path): ?string {
        if (!$this->open($path)) return null;

        $epoch = (int)($this->metadata['build_epoch'] ?? 0);

        return $epoch ? date('Y.m.d', $epoch) : null;
    }

    private function open(string $path): bool {
        if ($this->loadedPath === $path && $this->buf !== '') return true;

        $buf = @file_get_contents($path);
        if ($buf === false) return false;

        $pos = strrpos($buf, self::METADATA_MARKER);
        if ($pos === false) return false;

        $this->buf         = $buf;
        $this->ipv4Start   = null;
        $this->pointerBase = $pos + strlen(self::METADATA_MARKER);

        [$meta] = $this->mmdbDecode($this->pointerBase);
        if (!is_array($meta)) { $this->buf = ''; return false; }

        $this->metadata         = $meta;
        $this->nodeCount        = (int)$meta['node_count'];
        $this->recordSize       = (int)$meta['record_size'];
        $this->ipVersion        = (int)$meta['ip_version'];
        $this->nodeByteSize     = intdiv($this->recordSize, 4);
        $this->searchTreeSize   = $this->nodeCount * $this->nodeByteSize;
        $this->dataSectionStart = $this->searchTreeSize + self::DATA_SEPARATOR;
        $this->pointerBase      = $this->dataSectionStart;
        $this->loadedPath       = $path;

        return true;
    }

    protected function lookupLocal(string $ip, string $path): array {
        if (!$this->open($path)) return [];

        $raw = @inet_pton($ip);
        if ($raw === false) return [];

        $bits = strlen($raw) * 8;
        $node = ($bits === 32 && $this->ipVersion === 6) ? $this->mmdbIpv4StartNode() : 0;

        for ($i = 0; $i < $bits && $node < $this->nodeCount; $i++) {
            $bit  = (ord($raw[$i >> 3]) >> (7 - ($i % 8))) & 1;
            $node = $this->mmdbReadNode($node, $bit);
        }

        if ($node <= $this->nodeCount) return [];

        [$data] = $this->mmdbDecode($node - $this->nodeCount + $this->searchTreeSize);

        return $this->normalize(is_array($data) ? $data : []);
    }

    private function normalize(array $data): array {
        $iso = $data['country']['iso_code'] ?? ($data['registered_country']['iso_code'] ?? '');
        $out = [];

        if ($iso !== '') {
            $out['country']      = $iso;
            $out['country_code'] = $iso;
        }
        if (!empty($data['country']['names']['en'])) $out['country_name'] = $data['country']['names']['en'];
        if (!empty($data['city']['names']['en']))    $out['city']         = $data['city']['names']['en'];
        if (isset($data['location']['latitude']))    $out['lat']          = $data['location']['latitude'];
        if (isset($data['location']['longitude']))   $out['lon']          = $data['location']['longitude'];

        return $out;
    }

    private function mmdbIpv4StartNode(): int {
        if ($this->ipVersion !== 6) return 0;
        if ($this->ipv4Start !== null) return $this->ipv4Start;

        $node = 0;
        for ($i = 0; $i < 96 && $node < $this->nodeCount; $i++)
            $node = $this->mmdbReadNode($node, 0);

        return $this->ipv4Start = $node;
    }

    private function mmdbReadNode(int $node, int $index): int {
        $base = $node * $this->nodeByteSize;

        switch ($this->recordSize) {
            case 24:
                return unpack('N', "\x00" . substr($this->buf, $base + $index * 3, 3))[1];

            case 28:
                $middle = ord($this->buf[$base + 3]);
                if ($index === 0) {
                    $num   = ($middle & 0xF0) >> 4;
                    $bytes = chr($num) . substr($this->buf, $base, 3);
                } else {
                    $num   = $middle & 0x0F;
                    $bytes = chr($num) . substr($this->buf, $base + 4, 3);
                }
                return unpack('N', $bytes)[1];

            case 32:
                return unpack('N', substr($this->buf, $base + $index * 4, 4))[1];
        }

        throw new \RuntimeException("Unsupported mmdb record size: {$this->recordSize}");
    }

    private function mmdbDecode(int $offset): array {
        $ctrl = ord($this->buf[$offset]);
        $offset++;

        $type = $ctrl >> 5;
        if ($type === 0) {
            $type = ord($this->buf[$offset]) + 7;
            $offset++;
        }

        if ($type === 1)
            return $this->mmdbDecodePointer($ctrl, $offset);

        $size = $ctrl & 0x1f;
        if ($size >= 29) {
            $bytesToRead = $size - 28;
            $extra = $this->mmdbUint(substr($this->buf, $offset, $bytesToRead));
            $offset += $bytesToRead;
            if ($size === 29)      $size = 29 + $extra;
            elseif ($size === 30)  $size = 285 + $extra;
            else                   $size = 65821 + $extra;
        }

        switch ($type) {
            case 2:
                return [substr($this->buf, $offset, $size), $offset + $size];
            case 3:
                return [unpack('E', substr($this->buf, $offset, 8))[1], $offset + 8];
            case 4:
                return [substr($this->buf, $offset, $size), $offset + $size];
            case 5:
            case 6:
            case 9:
            case 10:
                return [$this->mmdbUint(substr($this->buf, $offset, $size)), $offset + $size];
            case 7:
                return $this->mmdbDecodeMap($size, $offset);
            case 8:
                return [$this->mmdbInt32(substr($this->buf, $offset, $size)), $offset + $size];
            case 11:
                return $this->mmdbDecodeArray($size, $offset);
            case 14:
                return [$size !== 0, $offset];
            case 15:
                return [unpack('G', substr($this->buf, $offset, 4))[1], $offset + 4];
            default:
                return [null, $offset + $size];
        }
    }

    private function mmdbDecodePointer(int $ctrl, int $offset): array {
        $pointerSize = (($ctrl >> 3) & 0x3) + 1;
        $bytes = substr($this->buf, $offset, $pointerSize);
        $offset += $pointerSize;

        switch ($pointerSize) {
            case 1:
                $pointer = (($ctrl & 0x7) << 8) | ord($bytes);
                break;
            case 2:
                $pointer = ((($ctrl & 0x7) << 16) | $this->mmdbUint($bytes)) + 2048;
                break;
            case 3:
                $pointer = ((($ctrl & 0x7) << 24) | $this->mmdbUint($bytes)) + 526336;
                break;
            default:
                $pointer = $this->mmdbUint($bytes);
        }

        [$value] = $this->mmdbDecode($this->pointerBase + $pointer);

        return [$value, $offset];
    }

    private function mmdbDecodeMap(int $size, int $offset): array {
        $map = [];
        for ($i = 0; $i < $size; $i++) {
            [$key, $offset]   = $this->mmdbDecode($offset);
            [$value, $offset] = $this->mmdbDecode($offset);
            $map[$key] = $value;
        }
        return [$map, $offset];
    }

    private function mmdbDecodeArray(int $size, int $offset): array {
        $arr = [];
        for ($i = 0; $i < $size; $i++) {
            [$value, $offset] = $this->mmdbDecode($offset);
            $arr[] = $value;
        }
        return [$arr, $offset];
    }

    private function mmdbUint(string $bytes): int {
        $value = 0;
        $len = strlen($bytes);
        for ($i = 0; $i < $len; $i++)
            $value = ($value << 8) | ord($bytes[$i]);
        return $value;
    }

    private function mmdbInt32(string $bytes): int {
        if ($bytes === '') return 0;
        $value = $this->mmdbUint($bytes);
        $bits = strlen($bytes) * 8;
        if ($value >= (1 << ($bits - 1)))
            $value -= (1 << $bits);
        return $value;
    }
}
