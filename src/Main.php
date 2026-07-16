<?php

namespace ST_system;

use ST_system\Config;

final class Main {

    public static function timestamp(string $format = ''): string {
        $now = time();
        $ts  = function_exists('hrtime') ? hrtime(true) / 1e9 : microtime(true);

        if ($format)
            return (new \DateTime())->setTimestamp($now)->format($format);
        
        return (string)$ts;
    }

    public static function pluralIndex(int $n, string $locale = 'ru'): int {
        $n = abs($n);

        switch ($locale) {
            case 'ru':
            case 'uk':
            case 'be':
                return ($n % 10 === 1 && $n % 100 !== 11) ? 0 : (($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 10 || $n % 100 >= 20)) ? 1 : 2);
            default:
                return $n === 1 ? 0 : 1;
        }
    }

    public static function pluralForm($n, $forms, string $locale = 'ru'): string {
        return $forms[self::pluralIndex((int)$n, $locale)];
    }

    private static function splitWords(string $name): array {
        return preg_split('/[\s\-_]+|(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', $name) ?: [];
    }

    public static function basename(string $class): string {
        return substr(strrchr('\\'.str_replace('/', '\\', $class), '\\'), 1);
    }

    public static function glue(array $parts, string $separator): string {
        $parts = array_filter(array_map(fn($p) => trim($p, $separator), $parts), fn($p) => $p !== '');
        return implode($separator, $parts);
    }

    public static function studlyCase(string $name): string {
        static $cache = [];
        return $cache[$name] ??= implode('', array_map(
            fn($w) => ucfirst(strtolower($w)),
            static::splitWords($name)
        ));
    }

    public static function readable(string $name): string {
        static $cache = [];
        
        $words = static::splitWords($name);

        return $cache[$name] ??= implode(' ', array_map(
            static fn($word, $index) => $index === 0
                ? ucfirst(strtolower($word))
                : strtolower($word),
            $words,
            array_keys($words)
        ));
    }

    public static function camelCase(string $name): string {
        static $cache = [];
        return $cache[$name] ??= lcfirst(static::studlyCase($name));
    }

    public static function snakeCase(string $name): string {
        static $cache = [];
        return $cache[$name] ??= strtolower(implode('_', static::splitWords($name)));
    }

    public static function kebabCase(string $name): string {
        static $cache = [];
        return $cache[$name] ??= strtolower(implode('-', static::splitWords($name)));
    }
    
    public static function merge(...$arrays) {
        $result = [];
        foreach ($arrays as $array)
            $result = self::_merge($result, $array);
    
        return $result;
    }
    
    private static function _merge($array1, $array2) {

        if (is_callable($array1) && is_callable($array2))
            return fn() => self::_merge($array1(), $array2());

        foreach ($array2 as $key => $value) {
            if (
                array_key_exists($key, $array1) &&
                is_array($array1[$key]) &&
                is_array($value)
            )
                $array1[$key] = self::_merge($array1[$key], $value);
            elseif (
                array_key_exists($key, $array1) &&
                is_callable($array1[$key]) &&
                is_callable($value)
            ) {
                $array1[$key] = fn(...$ARGS) => self::_merge($array1[$key](...$ARGS), $value(...$ARGS));
            }
            else
                $array1[$key] = $value;
        }
    
        return $array1;
    }

    public static function hash($value): string {
        $visited = [];
        $refs    = ['next' => 0, 'map' => []];
        return md5(static::_hash($value, $visited, $refs));
    }

    private static function _hash($value, array &$visited, array &$refs): string {
        switch (true) {
            case is_string($value):
                return 's:'.strlen($value).':'.$value;
            break;
            case is_int($value):
                return 'i:'.(string)$value;
            break;
            case is_float($value):
                return 'd:'.sprintf('%.14G', $value);
            break;
            case is_bool($value):
                return 'b:'.($value ? '1' : '0');
            break;
            case $value === null:
                return 'n';
            break;
            case is_array($value):
                $is_list = true; $i = 0;
                foreach ($value as $key => $_)
                    if ($key !== $i++) {
                        $is_list = false;
                        break;
                    }

                if ($is_list) {
                    $parts = array_map(function($v) use (&$visited, &$refs) { return static::_hash($v, $visited, $refs); }, $value);
                    sort($parts, SORT_STRING);
                    return 'l:['.implode(',', $parts).']';
                } else {
                    $keys = array_keys($value);
                    usort($keys, function($a, $b) {
                        $ka = is_int($a) ? 'i:' . $a : 's:' . $a;
                        $kb = is_int($b) ? 'i:' . $b : 's:' . $b;
                        if ($ka === $kb) return 0;
                        return ($ka < $kb) ? -1 : 1;
                    });
                    return 'a:{'.implode(',', array_map(function($k) use (&$visited, &$refs, $value) { return (is_int($k) ? 'ki:'.$k : 'ks:'.strlen($k).':'.$k).'='.static::_hash($value[$k], $visited, $refs); }, $keys)).'}';
                }
            break;
            case $value instanceof \Closure:
                $rf   = new \ReflectionFunction($value);
                $file = $rf->getFileName() ?: '';
                $vars = $rf->getStaticVariables();
                return 'c:'.strlen($file).':'.$file.':'.$rf->getStartLine().':'.$rf->getEndLine().':'.static::_hash($vars, $visited, $refs);
            break;
            case is_object($value):
                $id = spl_object_hash($value);

                if (isset($visited[$id]))
                    return 'r:'.$refs['map'][$id];

                $refs['map'][$id] = $refs['next']++;
                $visited[$id]     = true;

                return 'o:'.get_class($value).':'.static::_hash($value instanceof \JsonSerializable
                        ? $value->jsonSerialize()
                        : (array)$value,
                    $visited,
                    $refs
                );
            break;
            default:
                throw new \RuntimeException('Unsupported type in hash');
        }
    }

    public static function uuid(int $version = 7): string {
        switch ($version) {
            case 4:
                $data = random_bytes(16);
                $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
                break;

            default: 
                $ms = (int)(microtime(true) * 1000);
                $data = substr(pack('J', $ms), 0, 6) . random_bytes(10);
                $data[6] = chr((ord($data[6]) & 0x0f) | 0x70);
                break;
        }

        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public static function dotGet(array $data, string $path, $default = null) {
        foreach (explode('.', $path) as $segment) {
            if (!is_array($data)) return $default;
            if (array_key_exists($segment, $data)) { $data = $data[$segment]; continue; }
            if (ctype_digit($segment) && array_key_exists((int)$segment, $data)) { $data = $data[(int)$segment]; continue; }
            return $default;
        }
        return $data;
    }

    public static function dotSet(array &$data, string $path, $value): void {
        $segments = explode('.', $path);
        $current = &$data;
        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $current[$segment] = $value;
            } else {
                if (!isset($current[$segment]) || !is_array($current[$segment]))
                    $current[$segment] = [];
                $current = &$current[$segment];
            }
        }
    }

    public static function dotFlatten(array $data, string $prefix = ''): array {
        $result = [];
        foreach ($data as $k => $v) {
            $path = $prefix !== '' ? $prefix . '.' . $k : (string)$k;
            if (is_array($v) && !empty($v) && !self::arrayIsList($v)) {
                foreach (self::dotFlatten($v, $path) as $fk => $fv)
                    $result[$fk] = $fv;
            } else {
                $result[$path] = $v;
            }
        }
        return $result;
    }

    public static function arrayIsList(array $arr): bool {
        $i = 0;
        foreach ($arr as $k => $_) { if ($k !== $i++) return false; }
        return true;
    }

    public const BYTE_UNITS = [
        'pb' => 1125899906842624,
        'tb' => 1099511627776,
        'gb' => 1073741824,
        'mb' => 1048576,
        'kb' => 1024,
        'b'  => 1,
    ];


    public static function formatBytes($bytes, string $format = '', int $precision = 2) {
        $bytes = (float)$bytes;

        if ($format === '') {
            foreach (static::BYTE_UNITS as $unit => $factor)
                if ($bytes >= $factor || $factor === 1)
                    return round($bytes / $factor, $precision).' '.strtoupper($unit);
        }

        $parts   = [];
        $units   = [];
        $literal = '';
        $length  = strlen($format);

        for ($i = 0; $i < $length;) {
            if ($format[$i] === '\\' && $i + 1 < $length) {
                $literal .= $format[$i + 1];
                $i += 2;
                continue;
            }

            $unit = null;
            foreach ([3, 2, 1] as $size) {
                if ($i + $size > $length) continue;
                $candidate = strtolower(substr($format, $i, $size));
                if ($size === 3) $candidate = str_replace('ib', 'b', $candidate);
                if (isset(static::BYTE_UNITS[$candidate])) {
                    $unit = [$candidate, $size];
                    break;
                }
            }

            if ($unit === null) {
                $literal .= $format[$i++];
                continue;
            }

            if ($literal !== '') {
                $parts[] = $literal;
                $literal = '';
            }

            $parts[] = [$unit[0], substr($format, $i, $unit[1])];
            $units[] = $unit[0];
            $i += $unit[1];
        }

        if ($literal !== '') $parts[] = $literal;

        if (!$units) return (int)$bytes;

        if (count($units) === 1 && count($parts) === 1) {
            $factor = static::BYTE_UNITS[$units[0]];
            return $factor === 1 ? (int)$bytes : $bytes / $factor;
        }

        $out = '';
        foreach ($parts as $part) {
            if (is_string($part)) {
                $out .= $part;
                continue;
            }

            $factor = static::BYTE_UNITS[$part[0]];
            $value  = (int)floor($bytes / $factor);
            $bytes -= $value * $factor;
            $out   .= $value.' '.$part[1];
        }

        return $out;
    }

    public static function preparePath(string $path, $base = 0, bool $strict = false): string {
        if (strpos($path, '~') === 0) {
            $path = (Config::env('DOCUMENT_ROOT') ?: Config::env('COMPOSER_ROOT')).'/'.trim($path, '/~');
        } elseif (strpos($path, '/') !== 0) {
            if (is_int($base))
                $base = dirname(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)[$base]['file']);
            $path = rtrim((string)$base, '/').'/'.trim($path, '/');
        }

        $path = rtrim($path, '/');

        $stack = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') continue;

            if ($segment === '..') {
                if ($strict)
                    throw new \InvalidArgumentException("Main::preparePath(): illegal '..' segment in strict path '{$path}'");
                array_pop($stack);
                continue;
            }

            $stack[] = $segment;
        }

        return '/'.implode('/', $stack);
    }
}
