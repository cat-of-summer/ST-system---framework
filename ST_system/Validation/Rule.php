<?php

namespace ST_system\Validation;

final class Rule {

    /** @var array<string, Rule> */
    private static $registry = [];

    /** @var \Closure|null */
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
    /** @var \Closure|null */
    private $onErrorCallback = null;
    /** @var \Closure|null */
    private $onSuccessCallback = null;

    private function __construct(array $opts = []) {
        $this->callback   = $opts['callback'] ?? null;
        $this->order      = $opts['order'] ?? 700;
        $this->isModifier = $opts['isModifier'] ?? false;
        $this->bail       = $opts['bail'] ?? false;
        $this->stopOnPass = $opts['stopOnPass'] ?? false;
        $this->skipField  = $opts['skipField'] ?? false;
        $this->params     = $opts['params'] ?? [];
        $this->chain      = $opts['chain'] ?? [];
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
            return new self(['callback' => \Closure::fromCallable($spec)]);
        }

        if (is_array($spec)) return self::fromArray($spec);

        return new self();
    }

    private static function fromString(string $spec): Rule {
        $segments = array_map('trim', explode('|', trim($spec)));
        $chain = [];

        foreach ($segments as $seg) {
            if ($seg === '') continue;
            $params = [];
            $name = $seg;

            if (strpos($seg, ':') !== false) {
                [$name, $pStr] = explode(':', $seg, 2);
                $name = trim($name);
                $params = array_map('trim', explode(',', $pStr));
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

        if (count($chain) === 0) return new self();
        if (count($chain) === 1) return $chain[0]->copy();

        return new self(['chain' => $chain]);
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
            $chain[] = new self(['callback' => \Closure::fromCallable($before), 'order' => -1, 'isModifier' => true]);
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
                $chain[] = new self(['callback' => \Closure::fromCallable($rule)]);
            }
        }

        // after → modifier
        $after = $spec['after'] ?? null;
        if (is_callable($after)) {
            $chain[] = new self(['callback' => \Closure::fromCallable($after), 'order' => 1000, 'isModifier' => true]);
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
            return new self(['callback' => \Closure::fromCallable($spec)]);
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
                        $c = $inner->copy();
                        if (!$c->apply($item, $context)) {
                            $errors[$i] = $c->getErrorMessage($item) ?: "Validation failed for element {$i}";
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
        $fn = \Closure::fromCallable($spec);
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
        $fn = is_callable($cond) ? \Closure::fromCallable($cond) : fn() => $cond;
        return new self(['callback' => fn($v) => $fn() ? ($v !== null && $v !== '') : true, 'order' => 100, 'bail' => true]);
    }

    /**
     * @param bool|callable $cond
     * @return Rule
     */
    public static function prohibitedIf($cond): Rule {
        self::init();
        $fn = is_callable($cond) ? \Closure::fromCallable($cond) : fn() => $cond;
        return new self(['callback' => fn($v) => $fn() ? ($v === null || $v === '') : true, 'order' => 100, 'bail' => true]);
    }

    /**
     * @param bool|callable $cond
     * @return Rule
     */
    public static function excludeIf($cond): Rule {
        self::init();
        $fn = is_callable($cond) ? \Closure::fromCallable($cond) : fn() => $cond;
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
        $fn = is_callable($cond) ? \Closure::fromCallable($cond) : fn() => $cond;
        return new self([
            'callback' => function(&$v, array $p, array $ctx) use ($fn, $thenRule): bool {
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

    // ─── Fluent ──────────────────────────────────────────────────────

    /** @return self */
    public function order(int $o): self   { $this->order = $o; return $this; }
    /** @return self */
    public function onError(callable $cb): self   { $this->onErrorCallback = \Closure::fromCallable($cb); return $this; }
    /** @return self */
    public function onSuccess(callable $cb): self { $this->onSuccessCallback = \Closure::fromCallable($cb); return $this; }

    /**
     * @param mixed $value
     * @return string|null
     */
    public function getErrorMessage($value): ?string {
        return $this->onErrorCallback ? ($this->onErrorCallback)($value) : null;
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
        $sorted = $this->chain;
        usort($sorted, fn(Rule $a, Rule $b) => $a->order <=> $b->order);
        $failed = false;

        foreach ($sorted as $sub) {
            if ($sub->skipField && !($context['__exists'] ?? true)) {
                $context['__skip'] = true;
                return true;
            }
            if ($sub->skipField) continue;

            if (!$sub->apply($value, $context)) {
                $failed = true;
                if ($sub->bail) {
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
        $sorted = $this->chain;
        usort($sorted, fn(Rule $a, Rule $b) => $a->order <=> $b->order);
        $allErrors = null;

        foreach ($sorted as $sub) {
            if ($sub->skipField && !($context['__exists'] ?? true)) {
                $context['__skip'] = true;
                return null;
            }
            if ($sub->skipField) continue;

            $subErrors = $sub->applyWithErrors($value, $context);

            if ($subErrors !== null) {
                $allErrors = $allErrors ?? [];
                $allErrors = array_merge($allErrors, $subErrors);
                if ($sub->bail) return $allErrors;
            }

            if ($subErrors === null && $sub->stopOnPass) return null;
        }

        return $allErrors;
    }
}