<?php

namespace ST_system\Validation;

final class Rule {

    /** @var array<string, Rule> */
    private static $registry = [];

    /** @var callable|null */
    private $callback;
    /** @var int */
    private $order;
    /** @var bool */
    private $isModifier;
    /** @var bool */
    private $bail;
    /** @var bool */
    private $stopOnPass;
    /** @var bool */
    private $skipField;
    /** @var array */
    private $params;
    /** @var Rule[] */
    private $chain;
    /** @var callable|null */
    private $onErrorCallback = null;
    /** @var callable|null */
    private $onSuccessCallback = null;
    /** @var bool */
    private $bailChain = false;

    private function __construct(array $opts = []) {
        $this->callback   = $opts['callback'] ?? null;
        $this->order      = $opts['order'] ?? 700;
        $this->isModifier = $opts['isModifier'] ?? false;
        $this->bail       = $opts['bail'] ?? false;
        $this->stopOnPass = $opts['stopOnPass'] ?? false;
        $this->skipField  = $opts['skipField'] ?? false;
        $this->params     = $opts['params'] ?? [];
        $this->bailChain  = $opts['bailChain'] ?? false;
        $chain            = $opts['chain'] ?? [];
        if (count($chain) > 1) {
            usort($chain, fn(Rule $a, Rule $b) => $a->order <=> $b->order);
        }
        $this->chain = $chain;
    }

    private function copy(): self { return clone $this; }

    // ─── Инициализация стандартных правил ─────────────────────────────

    public static function init(): void {
        static $done;
        if ($done) return;
        $done = true;

        (new self(['callback' => fn($v) => true, 'order' => 0, 'skipField' => true]))->alias('sometimes');

        (new self([
            'callback' => function(&$v, array $p): bool {
                if ($v === null || $v === '') $v = $p[0] ?? null;
                return true;
            },
            'order' => 50, 'isModifier' => true,
        ]))->alias('default');

        (new self(['callback' => fn($v) => $v !== null && $v !== '', 'order' => 100, 'bail' => true]))->alias('required');
        (new self(['callback' => fn($v) => $v === null || $v === '', 'order' => 100, 'stopOnPass' => true]))->alias('nullable');

        (new self(['callback' => fn($v) => is_string($v), 'order' => 500]))->alias('string');

        (new self(['chain' => [
            new self(['callback' => fn($v) => is_int($v) || (is_string($v) && ctype_digit(ltrim((string)$v, '-'))), 'order' => 500]),
            new self(['callback' => function(&$v): bool { $v = (int)$v; return true; }, 'order' => 1000, 'isModifier' => true]),
        ]]))->alias('int')->alias('integer');

        (new self(['chain' => [
            new self(['callback' => fn($v) => is_float($v) || is_numeric($v), 'order' => 500]),
            new self(['callback' => function(&$v): bool { $v = (float)$v; return true; }, 'order' => 1000, 'isModifier' => true]),
        ]]))->alias('float');

        (new self(['chain' => [
            new self(['callback' => fn($v) => is_bool($v) || in_array($v, ['0','1',0,1,'true','false'], true), 'order' => 500]),
            new self(['callback' => function(&$v): bool {
                $v = is_string($v) ? filter_var($v, FILTER_VALIDATE_BOOLEAN) : (bool)$v;
                return true;
            }, 'order' => 1000, 'isModifier' => true]),
        ]]))->alias('bool');

        (new self(['callback' => fn($v) => is_string($v) && filter_var($v, FILTER_VALIDATE_EMAIL) !== false, 'order' => 500]))->alias('email');
        (new self(['callback' => fn($v) => is_string($v) && filter_var($v, FILTER_VALIDATE_URL) !== false, 'order' => 500]))->alias('url');
        (new self(['callback' => fn($v) => is_array($v), 'order' => 500]))->alias('array');

        (new self(['callback' => function($v, array $p): bool {
            $l = $p[0] ?? null;
            if ($l === null) return true;
            $l = (float)$l;
            if (is_string($v)) return mb_strlen($v) <= $l;
            if (is_array($v))  return count($v) <= $l;
            return is_numeric($v) && (float)$v <= $l;
        }]))->alias('max');

        (new self(['callback' => function($v, array $p): bool {
            $l = $p[0] ?? null;
            if ($l === null) return true;
            $l = (float)$l;
            if (is_string($v)) return mb_strlen($v) >= $l;
            if (is_array($v))  return count($v) >= $l;
            return is_numeric($v) && (float)$v >= $l;
        }]))->alias('min');

        (new self(['callback' => fn($v, array $p) => in_array($v, $p, false)]))->alias('in');
        (new self(['callback' => fn($v, array $p) => !in_array($v, $p, false)]))->alias('notIn')->alias('not_in');

        // regex:pattern
        (new self(['callback' => function($v, array $p): bool {
            $pattern = $p[0] ?? '';
            if (!is_string($v)) return false;
            return @preg_match($pattern, $v) === 1;
        }]))->alias('regex');

        // digits:n
        (new self(['callback' => function($v, array $p): bool {
            $n = (int)($p[0] ?? 0);
            return is_string($v) && ctype_digit($v) && strlen($v) === $n;
        }]))->alias('digits');

        // between:min,max
        (new self(['callback' => function($v, array $p): bool {
            $min = (float)($p[0] ?? 0);
            $max = (float)($p[1] ?? 0);
            if (is_string($v)) { $s = mb_strlen($v); return $s >= $min && $s <= $max; }
            if (is_array($v))  { $c = count($v);     return $c >= $min && $c <= $max; }
            return is_numeric($v) && (float)$v >= $min && (float)$v <= $max;
        }]))->alias('between');

        // date
        (new self(['callback' => fn($v) => is_string($v) && strtotime($v) !== false, 'order' => 500]))->alias('date');

        // date_format:format
        (new self(['callback' => function($v, array $p): bool {
            $fmt = $p[0] ?? '';
            if (!is_string($v)) return false;
            $d = \DateTime::createFromFormat($fmt, $v);
            return $d !== false && $d->format($fmt) === $v;
        }, 'order' => 500]))->alias('date_format');

        // json
        (new self(['callback' => function($v): bool {
            if (!is_string($v)) return false;
            if (function_exists('json_validate')) return \json_validate($v);
            json_decode($v);
            return json_last_error() === JSON_ERROR_NONE;
        }, 'order' => 500]))->alias('json');

        // uuid
        (new self(['callback' => fn($v) => is_string($v)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v) === 1,
            'order' => 500,
        ]))->alias('uuid');

        // starts_with:prefix
        (new self(['callback' => function($v, array $p): bool {
            if (!is_string($v)) return false;
            foreach ($p as $prefix) { if (strncmp($v, $prefix, strlen($prefix)) === 0) return true; }
            return false;
        }]))->alias('starts_with');

        // ends_with:suffix
        (new self(['callback' => function($v, array $p): bool {
            if (!is_string($v)) return false;
            foreach ($p as $suffix) { $len = strlen($suffix); if ($len === 0 || substr($v, -$len) === $suffix) return true; }
            return false;
        }]))->alias('ends_with');

        // contains:substring
        (new self(['callback' => function($v, array $p): bool {
            if (!is_string($v)) return false;
            foreach ($p as $sub) { if (strpos($v, $sub) !== false) return true; }
            return false;
        }]))->alias('contains');

        // same:field — сравнение с другим полем
        (new self(['callback' => function($v, array $p, array $ctx): bool {
            $other = $p[0] ?? '';
            $data = $ctx['__data'] ?? [];
            return array_key_exists($other, $data) && $v === $data[$other];
        }]))->alias('same');

        // different:field
        (new self(['callback' => function($v, array $p, array $ctx): bool {
            $other = $p[0] ?? '';
            $data = $ctx['__data'] ?? [];
            return !array_key_exists($other, $data) || $v !== $data[$other];
        }]))->alias('different');

        // trim (modifier)
        (new self(['callback' => function(&$v): bool {
            if (is_string($v)) $v = trim($v);
            return true;
        }, 'order' => -1, 'isModifier' => true]))->alias('trim');

        // uppercase (modifier)
        (new self(['callback' => function(&$v): bool {
            if (is_string($v)) $v = mb_strtoupper($v);
            return true;
        }, 'order' => 1000, 'isModifier' => true]))->alias('uppercase');

        // lowercase (modifier)
        (new self(['callback' => function(&$v): bool {
            if (is_string($v)) $v = mb_strtolower($v);
            return true;
        }, 'order' => 1000, 'isModifier' => true]))->alias('lowercase');

        // accepted — yes, on, 1, "1", true, "true"
        (new self(['callback' => fn($v) => in_array($v, [true, 1, '1', 'yes', 'on', 'true'], true), 'order' => 500]))->alias('accepted');

        // declined — no, off, 0, "0", false, "false"
        (new self(['callback' => fn($v) => in_array($v, [false, 0, '0', 'no', 'off', 'false'], true), 'order' => 500]))->alias('declined');

        // present — поле обязано присутствовать в массиве (пустое значение допустимо)
        (new self(['callback' => fn($v, array $p, array $ctx) => !empty($ctx['__exists']), 'order' => 100, 'bail' => true]))->alias('present');

        // file — валидный загруженный файл ($_FILES-like array)
        (new self(['callback' => function($v): bool {
            return is_array($v)
                && isset($v['tmp_name'], $v['error'], $v['size'], $v['name'])
                && $v['error'] === UPLOAD_ERR_OK
                && is_uploaded_file($v['tmp_name']);
        }, 'order' => 500]))->alias('file');

        // mimes:image/jpeg,image/png — реальный MIME через finfo (не доверяем браузеру)
        (new self(['callback' => function($v, array $p): bool {
            if (!is_array($v) || !isset($v['tmp_name']) || $v['error'] !== UPLOAD_ERR_OK) return false;
            if (!is_uploaded_file($v['tmp_name'])) return false;
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($v['tmp_name']);
            return in_array($mime, $p, true);
        }, 'order' => 600]))->alias('mimes');

        // extension:jpg,png — по расширению имени файла
        (new self(['callback' => function($v, array $p): bool {
            if (!is_array($v) || !isset($v['name'])) return false;
            $ext = strtolower(pathinfo($v['name'], PATHINFO_EXTENSION));
            return in_array($ext, array_map('strtolower', $p), true);
        }, 'order' => 600]))->alias('extension');

        // filesize:2048 — максимальный размер в килобайтах
        (new self(['callback' => function($v, array $p): bool {
            if (!is_array($v) || !isset($v['size'])) return false;
            $maxKb = (float)($p[0] ?? 0);
            return $v['size'] <= $maxKb * 1024;
        }, 'order' => 600]))->alias('filesize');
    }

    // ─── Реестр ──────────────────────────────────────────────────────

    public static function get(string $alias): ?Rule {
        self::init();
        return self::$registry[$alias] ?? null;
    }

    public function alias(string $name): self {
        if (isset(self::$registry[$name]) && self::$registry[$name] !== $this) {
            throw new \RuntimeException("Rule alias '{$name}' already registered");
        }
        self::$registry[$name] = $this;
        return $this;
    }

    // ─── Фабрика ─────────────────────────────────────────────────────

    /**
     * @param string|callable|array $spec
     * @return Rule
     */
    public static function create($spec): Rule {
        self::init();

        if (is_string($spec)) return self::fromString($spec);

        if (is_callable($spec) && !is_array($spec)) {
            return new self(['callback' => $spec]);
        }

        if (is_array($spec)) return self::fromArray($spec);

        return new self();
    }

    private static function fromString(string $spec): Rule {
        $segments  = array_map('trim', explode('|', trim($spec)));
        $chain     = [];
        $bailChain = false;

        foreach ($segments as $seg) {
            if ($seg === '') continue;
            $params = [];
            $name = $seg;

            if (strpos($seg, ':') !== false) {
                [$name, $pStr] = explode(':', $seg, 2);
                $name = trim($name);
                $params = array_map('trim', explode(',', $pStr));
            }

            if ($name === 'bail') {
                $bailChain = true;
                continue;
            }

            $tpl = self::$registry[$name] ?? null;
            if ($tpl === null) continue;

            if (count($tpl->chain) > 0 && count($params) === 0) {
                array_push($chain, ...$tpl->chain);
            } elseif (count($tpl->chain) > 0) {
                foreach ($tpl->chain as $sub) {
                    $c = $sub->copy();
                    if (!$c->isModifier) $c->params = $params;
                    $chain[] = $c;
                }
            } elseif (count($params) > 0) {
                $c = $tpl->copy();
                $c->params = $params;
                $chain[] = $c;
            } else {
                $chain[] = $tpl;
            }
        }

        if (count($chain) === 0) return new self(['bailChain' => $bailChain]);
        if (count($chain) === 1 && !$bailChain) return $chain[0]->copy();

        return new self(['chain' => $chain, 'bailChain' => $bailChain]);
    }

    private static function fromArray(array $spec): Rule {
        $chain = [];

        // default → modifier в цепочку
        if (array_key_exists('default', $spec)) {
            $def = $spec['default'];
            $chain[] = new self([
                'callback' => function(&$v) use ($def): bool {
                    if ($v === null || $v === '') $v = $def;
                    return true;
                },
                'order' => 50, 'isModifier' => true,
            ]);
        }

        // before → modifier
        $before = $spec['before'] ?? null;
        if (is_callable($before)) {
            $chain[] = new self(['callback' => $before, 'order' => -1, 'isModifier' => true]);
        }

        // rule
        $rule = $spec['rule'] ?? null;
        if ($rule !== null) {
            if (is_string($rule)) {
                $parsed = self::fromString($rule);
                if (count($parsed->chain) > 0) array_push($chain, ...$parsed->chain);
                elseif ($parsed->callback !== null) $chain[] = $parsed;
            } elseif (is_array($rule)) {
                $joined = implode('|', array_filter($rule, 'is_string'));
                if ($joined !== '') {
                    $parsed = self::fromString($joined);
                    if (count($parsed->chain) > 0) array_push($chain, ...$parsed->chain);
                    elseif ($parsed->callback !== null) $chain[] = $parsed;
                }
            } elseif (is_callable($rule)) {
                $chain[] = new self(['callback' => $rule]);
            }
        }

        // after → modifier
        $after = $spec['after'] ?? null;
        if (is_callable($after)) {
            $chain[] = new self(['callback' => $after, 'order' => 1000, 'isModifier' => true]);
        }

        return new self(['chain' => $chain]);
    }

    // ─── Хелперы ─────────────────────────────────────────────────────

    /**
     * @param array|callable $spec
     * @return Rule
     */
    public static function object($spec): Rule {
        self::init();
        if (is_callable($spec) && !is_array($spec)) {
            return new self(['callback' => $spec]);
        }
        $rules = $spec;
        return new self([
            'callback' => function(&$value, array $params, array $context) use ($rules) {
                if (!is_array($value)) return false;
                $v = Validator::create($rules);
                if (!empty($context['__silent'])) $v->silent(); else $v->loud();
                if (!empty($context['__safe'])) $v->safe();
                $v->validate($value);
                return empty($v->errors) ? true : $v->errors;
            },
            'isModifier' => true,
        ]);
    }

    /**
     * @param string|callable $spec
     * @return Rule
     */
    public static function forEach($spec): Rule {
        self::init();
        if (is_string($spec)) {
            $inner = self::create($spec);
            return new self([
                'callback' => function(&$value, array $params, array $context) use ($inner) {
                    if (!is_array($value)) return false;
                    $isSafe = !empty($context['__safe']);
                    $errors = [];
                    foreach ($value as $i => &$item) {
                        if (!$inner->apply($item, $context)) {
                            $errors[$i] = $inner->getErrorMessage($item) ?: "Validation failed for element {$i}";
                        }
                    }
                    unset($item);
                    if ($isSafe) {
                        foreach (array_keys($errors) as $k) {
                            unset($value[$k]);
                        }
                    }
                    if (!empty($errors)) $errors['__partial'] = true;
                    return empty($errors) ? true : $errors;
                },
                'isModifier' => true,
            ]);
        }
        $fn = $spec;
        return new self([
            'callback' => function(&$value, array $params, array $context) use ($fn) {
                if (!is_array($value)) return false;
                $isSafe = !empty($context['__safe']);
                $errors = [];
                foreach ($value as $i => &$item) {
                    if (!$fn($item)) {
                        $errors[$i] = "Validation failed for element {$i}";
                    }
                }
                unset($item);
                if ($isSafe) {
                    foreach (array_keys($errors) as $k) {
                        unset($value[$k]);
                    }
                }
                if (!empty($errors)) $errors['__partial'] = true;
                return empty($errors) ? true : $errors;
            },
            'isModifier' => true,
        ]);
    }

    /**
     * @param bool|callable $cond
     * @return Rule
     */
    public static function requiredIf($cond): Rule {
        self::init();
        $fn = is_callable($cond) ? $cond : fn() => $cond;
        return new self(['callback' => fn($v) => $fn() ? ($v !== null && $v !== '') : true, 'order' => 100, 'bail' => true]);
    }

    /**
     * @param bool|callable $cond
     * @return Rule
     */
    public static function prohibitedIf($cond): Rule {
        self::init();
        $fn = is_callable($cond) ? $cond : fn() => $cond;
        return new self(['callback' => fn($v) => $fn() ? ($v === null || $v === '') : true, 'order' => 100, 'bail' => true]);
    }

    /**
     * @param bool|callable $cond
     * @return Rule
     */
    public static function excludeIf($cond): Rule {
        self::init();
        $fn = is_callable($cond) ? $cond : fn() => $cond;
        return new self(['callback' => fn($v) => !$fn(), 'order' => 0, 'skipField' => true]);
    }

    /**
     * @param bool|callable $cond
     * @param string|Rule $then
     * @return Rule
     */
    public static function when($cond, $then): Rule {
        self::init();
        $thenRule = is_string($then) ? self::create($then) : $then;
        $fn = is_callable($cond) ? $cond : fn() => $cond;
        return new self([
            'callback' => function(&$v, array $p, array &$ctx) use ($fn, $thenRule): bool {
                return $fn() ? $thenRule->apply($v, $ctx) : true;
            },
            'isModifier' => true, 'order' => $thenRule->order,
        ]);
    }

    public static function in(array $values): Rule {
        self::init();
        return new self(['callback' => fn($v) => in_array($v, $values, false)]);
    }

    public static function notIn(array $values): Rule {
        self::init();
        return new self(['callback' => fn($v) => !in_array($v, $values, false)]);
    }

    /**
     * Статический хелпер для regex-паттернов, содержащих |
     * (нельзя передать через строку 'regex:/a|b/' — | разделит на сегменты)
     */
    public static function regex(string $pattern): Rule {
        self::init();
        return new self(['callback' => fn($v) => is_string($v) && @preg_match($pattern, $v) === 1]);
    }

    // ─── Fluent ──────────────────────────────────────────────────────

    /** @return self */
    public function order(int $o): self   { $this->order = $o; return $this; }
    /** @return self */
    public function onError(callable $cb): self   { $this->onErrorCallback = $cb; return $this; }
    /** @return self */
    public function onSuccess(callable $cb): self { $this->onSuccessCallback = $cb; return $this; }

    /**
     * @param mixed $value
     * @return string|null
     */
    public function getErrorMessage($value, ?string $field = null): ?string {
        return $this->onErrorCallback ? ($this->onErrorCallback)($value, $field) : null;
    }

    // ─── apply ───────────────────────────────────────────────────────

    /**
     * @param mixed $value
     * @return bool
     */
    public function apply(&$value, array &$context = []): bool {
        if (count($this->chain) > 0) return $this->applyChain($value, $context);

        if ($this->callback === null) return true;

        $cb = $this->callback;
        if ($this->isModifier) return !is_array($cb($value, $this->params, $context));

        return (bool)$cb($value, $this->params, $context);
    }

    /** @param mixed $value */
    private function applyChain(&$value, array &$context): bool {
        $failed = false;

        foreach ($this->chain as $sub) {
            if ($sub->skipField && !($context['__exists'] ?? true)) {
                $context['__skip'] = true;
                return true;
            }
            if ($sub->skipField) continue;

            if (!$sub->apply($value, $context)) {
                $failed = true;
                if ($sub->bail || $this->bailChain) {
                    if ($this->onErrorCallback) ($this->onErrorCallback)($value);
                    return false;
                }
            } elseif ($sub->stopOnPass) {
                if ($this->onSuccessCallback) ($this->onSuccessCallback)($value);
                return true;
            }
        }

        if ($failed) {
            if ($this->onErrorCallback) ($this->onErrorCallback)($value);
            return false;
        }

        if ($this->onSuccessCallback) ($this->onSuccessCallback)($value);
        return true;
    }

    // ─── applyWithErrors (для Validator) ─────────────────────────────

    /**
     * @param mixed $value
     * @return array|null
     */
    public function applyWithErrors(&$value, array &$context): ?array {
        if (count($this->chain) > 0) return $this->applyChainWithErrors($value, $context);

        if ($this->callback === null) return null;

        $cb = $this->callback;
        if ($this->isModifier) {
            $result = $cb($value, $this->params, $context);
            return is_array($result) ? $result : null;
        }

        return (bool)$cb($value, $this->params, $context) ? null : ['__self' => true];
    }

    /** @param mixed $value */
    private function applyChainWithErrors(&$value, array &$context): ?array {
        $allErrors = null;

        foreach ($this->chain as $sub) {
            if ($sub->skipField && !($context['__exists'] ?? true)) {
                $context['__skip'] = true;
                return null;
            }
            if ($sub->skipField) continue;

            $subErrors = $sub->applyWithErrors($value, $context);

            if ($subErrors !== null) {
                $allErrors = $allErrors ?? [];
                $allErrors = array_merge($allErrors, $subErrors);
                if ($sub->bail || $this->bailChain) return $allErrors;
            }

            if ($subErrors === null && $sub->stopOnPass) return null;
        }

        return $allErrors;
    }
}