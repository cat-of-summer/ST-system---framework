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

    public static function pluralForm($n, $forms): string {
        $n = (int)$n;
        return $forms[($n % 10 == 1 && $n % 100 != 11) ? 0 : (($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 10 || $n % 100 >= 20)) ? 1 : 2)];
    }

    private static function splitWords(string $name): array {
        return preg_split('/[\s\-_]+|(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', $name) ?: [];
    }

    public static function studlyCase(string $name): string {
        static $cache = [];
        return $cache[$name] ??= implode('', array_map(
            fn($w) => ucfirst(strtolower($w)),
            static::splitWords($name)
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

    public static function serialize($value): string {
        $visited = [];
        $refs    = ['next' => 0, 'map' => []];
        return static::_serialize($value, $visited, $refs);
    }

    private static function _serialize($value, array &$visited, array &$refs): string {
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
                    $parts = array_map(function($v) use (&$visited, &$refs) { return static::_serialize($v, $visited, $refs); }, $value);
                    return 'l:['.implode(',', $parts).']';
                } else {
                    return 'a:{'.implode(',', array_map(function($k) use (&$visited, &$refs, $value) { return (is_int($k) ? 'ki:'.$k : 'ks:'.strlen($k).':'.$k).'='.static::_serialize($value[$k], $visited, $refs); }, array_keys($value))).'}';
                }
            break;
            case $value instanceof \Closure:
                return 'c';
            break;
            case is_object($value):
                $id = spl_object_hash($value);

                if (isset($visited[$id]))
                    return 'r:'.$refs['map'][$id];

                $refs['map'][$id] = $refs['next']++;
                $visited[$id]     = true;

                return 'o:'.get_class($value).':'.static::_serialize($value instanceof \JsonSerializable
                        ? $value->jsonSerialize()
                        : (array)$value,
                    $visited,
                    $refs
                );
            break;
            default:
                throw new \RuntimeException('Unsupported type in serialize');
        }
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

    public static function deserialize(string $s) {
        $pos   = 0;
        $refs  = [];
        $value = static::_deserialize($s, $pos, $refs);

        if ($pos !== strlen($s))
            throw new \RuntimeException('Trailing data in deserialize at offset '.$pos);

        return $value;
    }

    private static function _deserialize(string $s, int &$pos, array &$refs) {
        if (!isset($s[$pos]))
            throw new \RuntimeException('Unexpected end of input at '.$pos);

        $tag = $s[$pos];

        switch ($tag) {
            case 'n':
                $pos++;
                return null;

            case 'c':
                $pos++;
                return fn() => null;

            case 'b':
                $pos += 2;
                $v = $s[$pos] === '1';
                $pos++;
                return $v;

            case 'i': {
                $pos += 2;
                $end = self::_scanScalarEnd($s, $pos);
                $v   = (int)substr($s, $pos, $end - $pos);
                $pos = $end;
                return $v;
            }

            case 'd': {
                $pos += 2;
                $end = self::_scanScalarEnd($s, $pos);
                $raw = substr($s, $pos, $end - $pos);
                $pos = $end;
                if ($raw === 'INF')  return INF;
                if ($raw === '-INF') return -INF;
                if ($raw === 'NAN')  return NAN;
                return (float)$raw;
            }

            case 's': {
                $pos  += 2;
                $colon = strpos($s, ':', $pos);
                if ($colon === false) throw new \RuntimeException('Malformed string at '.$pos);
                $len   = (int)substr($s, $pos, $colon - $pos);
                $pos   = $colon + 1;
                $v     = substr($s, $pos, $len);
                $pos  += $len;
                return $v;
            }

            case 'l': {
                $pos += 3;
                $result = [];
                if (($s[$pos] ?? '') !== ']') {
                    while (true) {
                        $result[] = static::_deserialize($s, $pos, $refs);
                        if (($s[$pos] ?? '') === ',') { $pos++; continue; }
                        break;
                    }
                }
                $pos++;
                return $result;
            }

            case 'a': {
                $pos += 3;
                $result = [];
                if (($s[$pos] ?? '') !== '}') {
                    while (true) {
                        if ($s[$pos] === 'k' && ($s[$pos + 1] ?? '') === 'i') {
                            $pos += 3;
                            $end  = self::_scanKeyEnd($s, $pos);
                            $key  = (int)substr($s, $pos, $end - $pos);
                            $pos  = $end;
                        } else {
                            $pos += 3;
                            $colon = strpos($s, ':', $pos);
                            if ($colon === false) throw new \RuntimeException('Malformed key at '.$pos);
                            $klen  = (int)substr($s, $pos, $colon - $pos);
                            $pos   = $colon + 1;
                            $key   = substr($s, $pos, $klen);
                            $pos  += $klen;
                        }
                        $pos++;
                        $result[$key] = static::_deserialize($s, $pos, $refs);
                        if (($s[$pos] ?? '') === ',') { $pos++; continue; }
                        break;
                    }
                }
                $pos++;
                return $result;
            }

            case 'o': {
                $pos  += 2;
                $colon = strpos($s, ':', $pos);
                if ($colon === false) throw new \RuntimeException('Malformed object header at '.$pos);
                $class = substr($s, $pos, $colon - $pos);
                $pos   = $colon + 1;

                $missing = !class_exists($class);
                if ($missing) {
                    $obj = new \stdClass();
                } else {
                    try {
                        $obj = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
                    } catch (\Throwable $e) {
                        $obj     = new \stdClass();
                        $missing = true;
                    }
                }

                $refs[] = $obj;

                $data = static::_deserialize($s, $pos, $refs);
                if (!is_array($data))
                    throw new \RuntimeException('Object body must be array for class '.$class);

                self::_populateObject($obj, $class, $missing, $data);
                return $obj;
            }

            case 'r': {
                $pos += 2;
                $end  = self::_scanScalarEnd($s, $pos);
                $idx  = (int)substr($s, $pos, $end - $pos);
                $pos  = $end;
                if (!array_key_exists($idx, $refs))
                    throw new \RuntimeException('Unknown ref '.$idx);
                return $refs[$idx];
            }

            default:
                throw new \RuntimeException('Unknown tag "'.$tag.'" at '.$pos);
        }
    }

    private static function _scanScalarEnd(string $s, int $pos): int {
        $len = strlen($s);
        while ($pos < $len) {
            $c = $s[$pos];
            if ($c === ',' || $c === ']' || $c === '}' || $c === '=') break;
            $pos++;
        }
        return $pos;
    }

    private static function _scanKeyEnd(string $s, int $pos): int {
        $len = strlen($s);
        while ($pos < $len && $s[$pos] !== '=') $pos++;
        return $pos;
    }

    private static function _populateObject($obj, string $class, bool $classMissing, array $data): void {
        foreach ($data as $key => $value) {
            if ($classMissing || !is_string($key) || $key === '' || $key[0] !== "\0") {
                $obj->{$key} = $value;
                continue;
            }

            $parts    = explode("\0", $key);
            $propName = $parts[2] ?? '';
            if ($propName === '') { $obj->{$key} = $value; continue; }

            $owner = ($parts[1] === '*') ? $class : $parts[1];

            try {
                $rp = new \ReflectionProperty($owner, $propName);
                $rp->setAccessible(true);
                $rp->setValue($obj, $value);
            } catch (\Throwable $e) {
                $obj->{$propName} = $value;
            }
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
            // Recurse only into non-empty associative arrays; treat list arrays as
            // atomic leaf values so an override list replaces the default wholesale
            // instead of being merged element-by-element.
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

    public static function preparePath(string $path, $base = 0): string {
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
                array_pop($stack);
                continue;
            }

            $stack[] = $segment;
        }

        return '/'.implode('/', $stack);
    }
}
