<?php

namespace ST_system\API\Drivers\Geo;

final class SxGeo extends GeoDriver {

    private const ID2ISO = [
        '', 'AP', 'EU', 'AD', 'AE', 'AF', 'AG', 'AI', 'AL', 'AM', 'CW', 'AO', 'AQ', 'AR', 'AS', 'AT', 'AU',
        'AW', 'AZ', 'BA', 'BB', 'BD', 'BE', 'BF', 'BG', 'BH', 'BI', 'BJ', 'BM', 'BN', 'BO', 'BR', 'BS',
        'BT', 'BV', 'BW', 'BY', 'BZ', 'CA', 'CC', 'CD', 'CF', 'CG', 'CH', 'CI', 'CK', 'CL', 'CM', 'CN',
        'CO', 'CR', 'CU', 'CV', 'CX', 'CY', 'CZ', 'DE', 'DJ', 'DK', 'DM', 'DO', 'DZ', 'EC', 'EE', 'EG',
        'EH', 'ER', 'ES', 'ET', 'FI', 'FJ', 'FK', 'FM', 'FO', 'FR', 'SX', 'GA', 'GB', 'GD', 'GE', 'GF',
        'GH', 'GI', 'GL', 'GM', 'GN', 'GP', 'GQ', 'GR', 'GS', 'GT', 'GU', 'GW', 'GY', 'HK', 'HM', 'HN',
        'HR', 'HT', 'HU', 'ID', 'IE', 'IL', 'IN', 'IO', 'IQ', 'IR', 'IS', 'IT', 'JM', 'JO', 'JP', 'KE',
        'KG', 'KH', 'KI', 'KM', 'KN', 'KP', 'KR', 'KW', 'KY', 'KZ', 'LA', 'LB', 'LC', 'LI', 'LK', 'LR',
        'LS', 'LT', 'LU', 'LV', 'LY', 'MA', 'MC', 'MD', 'MG', 'MH', 'MK', 'ML', 'MM', 'MN', 'MO', 'MP',
        'MQ', 'MR', 'MS', 'MT', 'MU', 'MV', 'MW', 'MX', 'MY', 'MZ', 'NA', 'NC', 'NE', 'NF', 'NG', 'NI',
        'NL', 'NO', 'NP', 'NR', 'NU', 'NZ', 'OM', 'PA', 'PE', 'PF', 'PG', 'PH', 'PK', 'PL', 'PM', 'PN',
        'PR', 'PS', 'PT', 'PW', 'PY', 'QA', 'RE', 'RO', 'RU', 'RW', 'SA', 'SB', 'SC', 'SD', 'SE', 'SG',
        'SH', 'SI', 'SJ', 'SK', 'SL', 'SM', 'SN', 'SO', 'SR', 'ST', 'SV', 'SY', 'SZ', 'TC', 'TD', 'TF',
        'TG', 'TH', 'TJ', 'TK', 'TM', 'TN', 'TO', 'TL', 'TR', 'TT', 'TV', 'TW', 'TZ', 'UA', 'UG', 'UM',
        'US', 'UY', 'UZ', 'VA', 'VC', 'VE', 'VG', 'VI', 'VN', 'VU', 'WF', 'WS', 'YE', 'YT', 'RS', 'ZA',
        'ZM', 'ME', 'ZW', 'A1', 'XK', 'O1', 'AX', 'GG', 'IM', 'JE', 'BL', 'MF', 'BQ', 'SS'
    ];

    protected static function getDefaultConfig(): array {
        return array_merge(parent::getDefaultConfig(), [
            'endpoint' => 'https://api.sypexgeo.net',
            'db_url'   => 'https://sypexgeo.net/files/SxGeoCountry.zip',
        ]);
    }

    private string $key = '';

    private ?string $loadedPath = null;
    private $fh;
    private array $info = [];
    private int $range = 0;
    private int $b_idx_len = 0;
    private int $m_idx_len = 0;
    private int $db_items = 0;
    private int $id_len = 0;
    private int $block_len = 0;
    private int $max_region = 0;
    private int $max_city = 0;
    private int $max_country = 0;
    private int $country_size = 0;
    private int $db_begin = 0;
    private string $b_idx_str = '';
    private string $m_idx_str = '';
    private string $db = '';
    private string $regions_db = '';
    private string $cities_db = '';
    private $pack = '';

    protected function bootCredentials(string $credentials): void {
        $this->key = $credentials;
    }

    protected function apiUrl(string $ip): string {
        return rtrim($this->getEndpoint(), '/') . ($this->key !== '' ? '/'.$this->key : '') . '/json/' . $ip;
    }

    protected function normalizeApiResponse(array $resp): array {
        $iso = $resp['country']['iso'] ?? '';
        $out = [];

        if ($iso !== '') {
            $out['country']      = $iso;
            $out['country_code'] = $iso;
        }
        if (!empty($resp['country']['name_en'])) $out['country_name'] = $resp['country']['name_en'];
        if (!empty($resp['city']['name_en']))    $out['city']         = $resp['city']['name_en'];
        if (isset($resp['city']['lat']))         $out['lat']          = $resp['city']['lat'];
        if (isset($resp['city']['lon']))         $out['lon']          = $resp['city']['lon'];

        return $out;
    }

    protected function dbFilename(): string {
        return 'SxGeo.dat';
    }

    protected function extract(string $archivePath, string $targetDir): ?string {
        $target = $targetDir . '/SxGeo.dat';

        $zip = new \ZipArchive();
        if ($zip->open($archivePath) !== true) return null;

        $entry = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (substr(strtolower((string)$name), -4) === '.dat') { $entry = $name; break; }
        }
        if ($entry === null) { $zip->close(); return null; }

        $stream = $zip->getStream($entry);
        if (!$stream) { $zip->close(); return null; }

        $out = @fopen($target, 'wb');
        if (!$out) { fclose($stream); $zip->close(); return null; }
        stream_copy_to_stream($stream, $out);
        fclose($stream);
        fclose($out);
        $zip->close();

        return is_file($target) ? $target : null;
    }

    protected function lookupLocal(string $ip, string $path): array {
        if (!$this->open($path)) return [];

        $num = $this->datGetNum($ip);
        if ($num === false || $num === 0) return [];

        if ($this->max_city) {
            $parsed = $this->datParseCity($num);
            $iso = $parsed['country']['iso'] ?? '';
            $out = $iso !== '' ? ['country' => $iso, 'country_code' => $iso] : [];

            if (!empty($parsed['city']['name_en'])) $out['city'] = $parsed['city']['name_en'];
            if (isset($parsed['city']['lat']))      $out['lat']  = $parsed['city']['lat'];
            if (isset($parsed['city']['lon']))      $out['lon']  = $parsed['city']['lon'];

            return $out;
        }

        $iso = self::ID2ISO[$num] ?? '';

        return $iso !== '' ? ['country' => $iso, 'country_code' => $iso] : [];
    }

    protected function dbVersion(string $path): ?string {
        return $this->open($path) ? date('Y.m.d', $this->info['time']) : null;
    }

    private function open(string $path): bool {
        if ($this->loadedPath === $path && is_resource($this->fh)) return true;

        if (is_resource($this->fh)) @fclose($this->fh);
        $this->fh = null;
        $this->loadedPath = null;

        $fh = @fopen($path, 'rb');
        if (!$fh) return false;

        $header = fread($fh, 40);
        if (substr($header, 0, 3) != 'SxG') { fclose($fh); return false; }

        $info = unpack('Cver/Ntime/Ctype/Ccharset/Cb_idx_len/nm_idx_len/nrange/Ndb_items/Cid_len/nmax_region/nmax_city/Nregion_size/Ncity_size/nmax_country/Ncountry_size/npack_size', substr($header, 3));
        if ($info['b_idx_len'] * $info['m_idx_len'] * $info['range'] * $info['db_items'] * $info['time'] * $info['id_len'] == 0) {
            fclose($fh);
            return false;
        }

        $this->range        = $info['range'];
        $this->b_idx_len    = $info['b_idx_len'];
        $this->m_idx_len    = $info['m_idx_len'];
        $this->db_items     = $info['db_items'];
        $this->id_len       = $info['id_len'];
        $this->block_len    = 3 + $this->id_len;
        $this->max_region   = $info['max_region'];
        $this->max_city     = $info['max_city'];
        $this->max_country  = $info['max_country'];
        $this->country_size = $info['country_size'];
        $this->pack         = $info['pack_size'] ? explode("\0", fread($fh, $info['pack_size'])) : '';
        $this->b_idx_str    = fread($fh, $info['b_idx_len'] * 4);
        $this->m_idx_str    = fread($fh, $info['m_idx_len'] * 4);
        $this->db_begin     = ftell($fh);
        $this->db           = fread($fh, $this->db_items * $this->block_len);
        $this->regions_db   = $info['region_size'] > 0 ? fread($fh, $info['region_size']) : '';
        $this->cities_db    = $info['city_size'] > 0 ? fread($fh, $info['city_size']) : '';

        $info['regions_begin'] = $this->db_begin + $this->db_items * $this->block_len;
        $info['cities_begin']  = $info['regions_begin'] + $info['region_size'];

        $this->info       = $info;
        $this->fh         = $fh;
        $this->loadedPath = $path;

        return true;
    }

    private function datSearchIdx($ipn, $min, $max) {
        while ($max - $min > 8) {
            $offset = ($min + $max) >> 1;
            if ($ipn > substr($this->m_idx_str, $offset * 4, 4)) $min = $offset;
            else $max = $offset;
        }
        while ($ipn > substr($this->m_idx_str, $min * 4, 4) && $min++ < $max) {}

        return $min;
    }

    private function datSearchDb($str, $ipn, $min, $max) {
        if ($max - $min > 1) {
            $ipn = substr($ipn, 1);
            while ($max - $min > 8) {
                $offset = ($min + $max) >> 1;
                if ($ipn > substr($str, $offset * $this->block_len, 3)) $min = $offset;
                else $max = $offset;
            }
            while ($ipn >= substr($str, $min * $this->block_len, 3) && ++$min < $max) {}
        } else {
            $min++;
        }

        return hexdec(bin2hex(substr($str, $min * $this->block_len - $this->id_len, $this->id_len)));
    }

    private function datGetNum($ip) {
        $ip1n = (int)$ip;
        if ($ip1n == 0 || $ip1n == 10 || $ip1n == 127 || $ip1n >= $this->b_idx_len || false === ($ipn = ip2long($ip)))
            return false;

        $ipn = pack('N', $ipn);
        $blocks = unpack("Nmin/Nmax", substr($this->b_idx_str, ($ip1n - 1) * 4, 8));

        if ($blocks['max'] - $blocks['min'] > $this->range) {
            $part = $this->datSearchIdx($ipn, floor($blocks['min'] / $this->range), floor($blocks['max'] / $this->range) - 1);
            $min = $part > 0 ? $part * $this->range : 0;
            $max = $part > $this->m_idx_len ? $this->db_items : ($part + 1) * $this->range;
            if ($min < $blocks['min']) $min = $blocks['min'];
            if ($max > $blocks['max']) $max = $blocks['max'];
        } else {
            $min = $blocks['min'];
            $max = $blocks['max'];
        }

        return $this->datSearchDb($this->db, $ipn, $min, $max);
    }

    private function datReadData($seek, $max, $type) {
        $raw = '';
        if ($seek && $max)
            $raw = substr($type == 1 ? $this->regions_db : $this->cities_db, $seek, $max);

        return $this->datUnpack($this->pack[$type], $raw);
    }

    private function datParseCity($seek, $full = false) {
        if (!$this->pack) return false;

        $only_country = false;
        if ($seek < $this->country_size) {
            $country = $this->datReadData($seek, $this->max_country, 0);
            $city = $this->datUnpack($this->pack[2]);
            $city['lat'] = $country['lat'];
            $city['lon'] = $country['lon'];
            $only_country = true;
        } else {
            $city = $this->datReadData($seek, $this->max_city, 2);
            $country = ['id' => $city['country_id'], 'iso' => self::ID2ISO[$city['country_id']] ?? ''];
            unset($city['country_id']);
        }

        if ($full) {
            $region = $this->datReadData($city['region_seek'], $this->max_region, 1);
            if (!$only_country) $country = $this->datReadData($region['country_seek'], $this->max_country, 0);
            unset($city['region_seek'], $region['country_seek']);
            return ['city' => $city, 'region' => $region, 'country' => $country];
        }

        unset($city['region_seek']);
        return ['city' => $city, 'country' => ['id' => $country['id'], 'iso' => $country['iso']]];
    }

    private function datUnpack($pack, $item = '') {
        $unpacked = [];
        $empty = empty($item);
        $pack = explode('/', $pack);
        $pos = 0;

        foreach ($pack as $p) {
            list($type, $name) = explode(':', $p);
            $type0 = $type[0];

            if ($empty) {
                $unpacked[$name] = $type0 == 'b' || $type0 == 'c' ? '' : 0;
                continue;
            }

            switch ($type0) {
                case 't':
                case 'T': $l = 1; break;
                case 's':
                case 'n':
                case 'S': $l = 2; break;
                case 'm':
                case 'M': $l = 3; break;
                case 'd': $l = 8; break;
                case 'c': $l = (int)substr($type, 1); break;
                case 'b': $l = strpos($item, "\0", $pos) - $pos; break;
                default: $l = 4;
            }

            $val = substr($item, $pos, $l);
            $v = null;
            switch ($type0) {
                case 't': $v = unpack('c', $val); break;
                case 'T': $v = unpack('C', $val); break;
                case 's': $v = unpack('s', $val); break;
                case 'S': $v = unpack('S', $val); break;
                case 'm': $v = unpack('l', $val . (ord($val[2]) >> 7 ? "\xff" : "\0")); break;
                case 'M': $v = unpack('L', $val . "\0"); break;
                case 'i': $v = unpack('l', $val); break;
                case 'I': $v = unpack('L', $val); break;
                case 'f': $v = unpack('f', $val); break;
                case 'd': $v = unpack('d', $val); break;
                case 'n': $v = current(unpack('s', $val)) / pow(10, $type[1]); break;
                case 'N': $v = current(unpack('l', $val)) / pow(10, $type[1]); break;
                case 'c': $v = rtrim($val, ' '); break;
                case 'b': $v = $val; $l++; break;
            }

            $pos += $l;
            $unpacked[$name] = is_array($v) ? current($v) : $v;
        }

        return $unpacked;
    }
}
