<?php

namespace ST_system\API\Drivers\Geo;

use ST_system\Storage\File;

final class StaticGeo extends GeoDriver {

    private static array $data = [];

    private string $path = '';

    protected function bootCredentials(string $credentials): void {
        $this->path = $credentials;
    }

    public function getDetails(string $ip): array {
        foreach ($this->data() as $mask => $details)
            if (self::ipMatches($ip, (string)$mask))
                return is_array($details) ? $details : [];

        return [];
    }

    private function data(): array {
        if (!array_key_exists($this->path, self::$data))
            self::$data[$this->path] = $this->load($this->path);

        return self::$data[$this->path];
    }

    private function load(string $path): array {
        if ($path === '') return [];

        $file = File::make($path);
        if (!$file->exists) return [];

        $data = $file->getContents();

        return is_array($data) ? $data : [];
    }

    private static function ipMatches(string $ip, string $pattern): bool {
        if ($pattern === '') return false;
        if (strpos($pattern, '/') !== false) return self::ipInCidr($ip, $pattern);
        if (substr($pattern, -1) === '.') return strncmp($ip, $pattern, strlen($pattern)) === 0;

        return self::normalizeIp($ip) === self::normalizeIp($pattern);
    }

    private static function ipInCidr(string $ip, string $cidr): bool {
        if (strpos($cidr, '/') === false) return false;

        [$subnet, $bits] = explode('/', $cidr, 2);
        $bits = (int)$bits;

        $ipBin     = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false) return false;
        if (strlen($ipBin) !== strlen($subnetBin)) return false;

        $maxBits = strlen($ipBin) * 8;
        if ($bits < 0 || $bits > $maxBits) return false;

        $bytes = intdiv($bits, 8);
        $rem   = $bits % 8;

        if ($bytes > 0 && strncmp($ipBin, $subnetBin, $bytes) !== 0) return false;
        if ($rem === 0) return true;

        $mask = chr((0xff << (8 - $rem)) & 0xff);

        return ($ipBin[$bytes] & $mask) === ($subnetBin[$bytes] & $mask);
    }

    private static function normalizeIp(string $ip): string {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = @inet_pton($ip);
            if ($packed !== false)
                return implode(':', str_split(bin2hex($packed), 4));
        }

        return $ip;
    }
}
