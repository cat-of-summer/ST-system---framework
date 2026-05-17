<?php

namespace ST_system;

final class Rule {

    private static $registry = [];
    private static array $prefixStack = [];

    private \Closure $callback;
    private ?\Closure $before = null;
    private ?\Closure $after = null;
    private ?\Closure $handleError = null;
    private int $order = 600;
    private bool $skip = false;
    private array $params = [];
    private bool $frozen = false;
    private bool $seesSentinel = false;

    private function __construct(\Closure $callback) {
        self::init();
        $this->callback = $callback;
    }

    public function __get(string $name) {
        if (in_array($name, ['callback', 'before', 'after', 'handleError', 'order', 'skip', 'params', 'frozen'])) {
            return $this->$name;
        }
        throw new \RuntimeException("Unknown property Rule::\${$name}");
    }

    private function guardFrozen(): void {
        if ($this->frozen) {
            throw new \RuntimeException('Cannot modify a frozen Rule (aliased rules are immutable)');
        }
    }
    
    public function before(\Closure $fn): self {
        $this->guardFrozen();
        $this->before = $fn;
        return $this;
    }
    
    public function after(\Closure $fn): self {
        $this->guardFrozen();
        $this->after = $fn;
        return $this;
    }

    public function handleError(\Closure $fn): self {
        $this->guardFrozen();
        $this->handleError = $fn;
        return $this;
    }

    public function throwable(): self {
        $this->handleError(fn($v, $errors) => throw new \Exception(implode(PHP_EOL, $errors)));
        return $this;
    }

    public function order(int $o): self {
        $this->guardFrozen();
        $this->order = $o;
        return $this;
    }

    public function skip(bool $s = true): self {
        $this->guardFrozen();
        $this->skip = $s;
        return $this;
    }

    public function seesSentinel(bool $s = true): self {
        $this->guardFrozen();
        $this->seesSentinel = $s;
        return $this;
    }

    private function copy(): self {
        $clone = clone $this;
        $clone->frozen = false;
        return $clone;
    }
    
    public static function scope(string $prefix, \Closure $fn) {
        self::init();
        self::$prefixStack[] = $prefix;
        try {
            return $fn();
        } finally {
            array_pop(self::$prefixStack);
        }
    }

    public static function currentPrefix(): ?string {
        $n = count(self::$prefixStack);
        return $n > 0 ? self::$prefixStack[$n - 1] : null;
    }

    private static function resolveAlias(string $name): ?Rule {
        if (strpos($name, '\\') !== false) {
            $name = ltrim($name, '\\');
            return self::$registry[$name] ?? null;
        }
        for ($i = count(self::$prefixStack) - 1; $i >= 0; $i--) {
            $key = self::$prefixStack[$i] . '\\' . $name;
            if (isset(self::$registry[$key])) return self::$registry[$key];
        }
        return self::$registry[$name] ?? null;
    }
    
    public static function get(string $alias): ?Rule {
        self::init();
        return self::resolveAlias($alias);
    }

    public function alias(string $name, $skip = 0): self {
        if (strpos($name, '\\') !== false) {
            $name = ltrim($name, '\\');
        } elseif ($prefix = self::currentPrefix()) {
            $name = $prefix . '\\' . $name;
        }

        if (isset(self::$registry[$name]) && self::$registry[$name] !== $this) {
            if (!$skip)
                throw new \RuntimeException("Rule alias '{$name}' already registered");

            if ($skip === 1)
                return $this;
        }
        self::$registry[$name] = $this;
        $this->frozen = true;
        return $this;
    }
    
    private function execute(&$data): array {
        if (!$this->seesSentinel && self::isSentinel($data)) {
            $masked = null;
            $result = $this->executeRaw($masked);
            if ($masked !== null) $data = $masked;
            return $result;
        }
        return $this->executeRaw($data);
    }

    private function executeRaw(&$data): array {
        if ($this->before !== null) {
            ($this->before)($data, $this->params);
        }

        $errors = [];
        $passed = true;

        if ($this->callback !== null) {
            try {
                $result = ($this->callback)($data, $this->params);
            } catch (\Throwable $th) {
                if ($this->after !== null) ($this->after)($data, $this->params);
                return [false, [$th->getMessage()]];
            }

            if (is_array($result)) {
                $errors = $result;
                $passed = empty($result);
                if (!$passed && $this->handleError !== null) {
                    $msg = ($this->handleError)($data, $errors);
                    $errors = is_string($msg) ? [$msg] : [];
                }
            } elseif ($result === false || $result === 0) {
                $passed = false;
                if ($this->handleError !== null) {
                    $msg = ($this->handleError)($data, $errors);
                    if (is_string($msg)) {
                        $errors[] = $msg;
                    }
                }
            }
        }

        if ($this->after !== null) {
            ($this->after)($data, $this->params);
        }

        return [$passed, $errors];
    }

    public function apply(&$data): array {
        return $this->execute($data)[1];
    }

    public function check($data): array {
        return $this->execute($data)[1];
    }

    private static function parseString(string $spec): array {
        $segments = array_map('trim', explode('|', trim($spec)));
        $rules    = [];

        foreach ($segments as $seg) {
            if ($seg === '') continue;

            $params = [];
            $name   = $seg;

            if (strpos($seg, ':') !== false) {
                [$name, $pStr] = explode(':', $seg, 2);
                $name   = trim($name);
                $params = array_map('trim', explode(',', $pStr));
            }

            $tpl = self::resolveAlias($name);
            if ($tpl === null) {
                throw new \RuntimeException("Unknown rule: '{$name}'");
            }

            $clone = $tpl->copy();
            if (!empty($params)) {
                $clone->params = $params;
            }
            $rules[] = $clone;
        }

        return $rules;
    }

    private static function compileFieldRules($spec): array {
        $rules = [];

        if ($spec instanceof self) {
            $rules = [$spec->copy()];
        } elseif (is_string($spec)) {
            $rules = self::parseString($spec);
        } elseif (is_array($spec)) {
            foreach ($spec as $item) {
                if (is_string($item)) {
                    array_push($rules, ...self::parseString($item));
                } elseif ($item instanceof self) {
                    $rules[] = $item->copy();
                } else {
                    throw new \RuntimeException('Schema value must be a string, Rule, or array of strings/Rules');
                }
            }
        } else {
            throw new \RuntimeException('Schema value must be a string, Rule, or array of strings/Rules');
        }

        usort($rules, fn(Rule $a, Rule $b) => $a->order <=> $b->order);
        return $rules;
    }

    public static function create($spec): Rule {
        self::init();

        if ($spec instanceof \Closure) {
            return new self($spec);
        }

        if (is_string($spec)) {
            $subRules = self::parseString($spec);
            usort($subRules, fn(Rule $a, Rule $b) => $a->order <=> $b->order);

            if (count($subRules) === 0) {
                return new self(fn() => true);
            }
            if (count($subRules) === 1) {
                return $subRules[0];
            }

            return self::create(function(&$data) use ($subRules): array {
                $errors = [];
                foreach ($subRules as $rule) {
                    [$passed, $ruleErrors] = $rule->execute($data);
                    if (!empty($ruleErrors)) {
                        array_push($errors, ...$ruleErrors);
                    }
                    if (!$passed && $rule->skip) break;
                }
                return $errors;
            })->seesSentinel();
        }

        if (is_array($spec)) {
            $subRules = self::compileFieldRules($spec);

            if (count($subRules) === 0) {
                return new self(fn(&$v) => true);
            }
            if (count($subRules) === 1) {
                return $subRules[0];
            }

            return self::create(function(&$data, array $params = []) use ($subRules): array {
                $errors = [];
                foreach ($subRules as $rule) {
                    if (!empty($params) && empty($rule->params)) {
                        $rule = $rule->copy();
                        $rule->params = $params;
                    }
                    [$passed, $ruleErrors] = $rule->execute($data);
                    if (!empty($ruleErrors)) {
                        array_push($errors, ...$ruleErrors);
                    }
                    if (!$passed && $rule->skip) break;
                }
                return $errors;
            })->seesSentinel();
        }

        throw new \InvalidArgumentException('Rule::create() expects string, Closure, or array');
    }

    public static function object(array $schema): Rule {
        self::init();

        
        $regular  = [];
        $flatSpec = [];

        foreach ($schema as $key => $spec) {
            if (strpos((string)$key, '.') !== false) {
                $flatSpec[(string)$key] = $spec;
            } else {
                $regular[$key] = $spec;
            }
        }

        if (!empty($flatSpec)) {
            $tree = [];
            foreach ($flatSpec as $path => $spec)
                self::insertIntoTree($tree, explode('.', (string)$path), $spec);
        
            foreach ($tree as $topKey => $node) {
                if (array_key_exists($topKey, $regular)) {
                    throw new \RuntimeException(
                        "Rule::object(): key '{$topKey}' is defined both as a regular key and via dot-notation"
                    );
                }
                $regular[$topKey] = self::treeNodeToRule($node);
            }
        }

        $compiled = [];
        foreach ($regular as $key => $spec) {
            $compiled[$key] = self::compileFieldRules($spec);
        }

        return self::create(function(&$data) use ($compiled): array {
            if (is_object($data)) $data = (array)$data;
            if (!is_array($data)) return ['Expected array or object'];

            $errors = [];
            $result = [];

            foreach ($compiled as $key => $rules) {
                $temp = array_key_exists($key, $data) ? $data[$key] : self::sentinel();

                foreach ($rules as $rule) {
                    [$passed, $ruleErrors] = $rule->execute($temp);
                    foreach ($ruleErrors as $err) {
                        $errors[] = "{$key}.{$err}";
                    }
                    if (!$passed && $rule->skip) break;
                }

                if (!self::isSentinel($temp)) {
                    $result[$key] = $temp;
                }
            }

            $data = $result;
            return $errors;
        })->seesSentinel();
    }

    public static function forEach($spec): Rule {
        self::init();

        if ($spec instanceof \Closure) {
            $innerRule = self::create($spec);
            $rules = [$innerRule];
        } elseif ($spec instanceof self) {
            $rules = [$spec->copy()];
        } elseif (is_string($spec)) {
            $rules = self::parseString($spec);
            usort($rules, fn(Rule $a, Rule $b) => $a->order <=> $b->order);
        } elseif (is_array($spec)) {
            $rules = self::compileFieldRules($spec);
        } else {
            throw new \InvalidArgumentException('Rule::forEach() expects string, Closure, array or Rule');
        }

        return self::create(function(&$data) use ($rules): array {
            if (!is_array($data) && !($data instanceof \Traversable)) {
                return ['Expected iterable'];
            }

            $errors = [];
            $toRemove = [];

            foreach ($data as $i => &$item) {
                $elementFailed = false;
                foreach ($rules as $rule) {
                    [$passed, $ruleErrors] = $rule->execute($item);
                    foreach ($ruleErrors as $err) {
                        $errors[] = "{$i}.{$err}";
                        $elementFailed = true;
                    }
                    if (!$passed && $rule->skip) break;
                }
                if ($elementFailed) $toRemove[] = $i;
            }
            unset($item);

            foreach ($toRemove as $i) unset($data[$i]);
            
            return $errors;
        })
        ->order(500)
        ->seesSentinel();
    }

    private static function insertIntoTree(array &$node, array $parts, $spec): void {
        $key = array_shift($parts);

        if (empty($parts)) {
            
            $node[$key] = ['__spec__' => $spec];
        } else {
            
            if (!isset($node[$key]) || isset($node[$key]['__spec__'])) {
                $node[$key] = [];
            }
            self::insertIntoTree($node[$key], $parts, $spec);
        }
    }

    private static function treeNodeToRule(array $node): Rule {
        
        if (array_key_exists('__spec__', $node)) {
            $spec = $node['__spec__'];
            if ($spec instanceof self) {
                return $spec->copy();
            }
            return self::create((string)$spec);
        }

        $hasWildcard  = array_key_exists('*', $node);
        $hasNamed     = count(array_diff_key($node, ['*' => true])) > 0;

        if ($hasWildcard && $hasNamed) {
            throw new \RuntimeException(
                "Rule::flat(): cannot mix wildcard '*' with named keys at the same level"
            );
        }

        if ($hasWildcard) {
            if (array_key_exists('__spec__', $node['*'])) {
                $spec = $node['*']['__spec__'];
                if ($spec instanceof self) {
                    return self::forEach($spec);
                }
                return self::forEach((string)$spec);
            }

            $innerSchema = [];
            foreach ($node['*'] as $childKey => $childNode) {
                $innerSchema[$childKey] = self::treeNodeToRule($childNode);
            }
            return self::forEach(self::object($innerSchema));
        }

        
        $schema = [];
        foreach ($node as $childKey => $childNode) {
            $schema[$childKey] = self::treeNodeToRule($childNode);
        }
        return self::object($schema);
    }

    public static function requiredIf($cond): Rule {
        self::init();
        $fn = ($cond instanceof \Closure) ? $cond : function() use ($cond) { return (bool)$cond; };

        return self::create(function(&$v) use ($fn): bool {
            if (!$fn($v)) return true;
            return !self::isSentinel($v) && $v !== null && $v !== '';
        })
        ->order(100)
        ->skip()
        ->seesSentinel()
        ->handleError(fn($v) => 'This field is required');
    }

    public static function prohibitedIf($cond): Rule {
        self::init();
        $fn = ($cond instanceof \Closure) ? $cond : function() use ($cond) { return (bool)$cond; };

        return self::create(function(&$v) use ($fn): bool {
            if (!$fn($v)) return true;
            return self::isSentinel($v) || $v === null || $v === '';
        })
        ->order(100)
        ->skip()
        ->seesSentinel()
        ->handleError(fn($v) => 'This field is not allowed');
    }

    public static function excludeIf($cond): Rule {
        self::init();
        $fn = ($cond instanceof \Closure) ? $cond : function() use ($cond) { return (bool)$cond; };

        return self::create(function(&$v) use ($fn): bool {
            if (!$fn($v)) return true;
            $v = self::sentinel();
            return false;
        })
        ->order(0)
        ->skip()
        ->seesSentinel();
    }

    public static function when($cond, $spec): Rule {
        self::init();
        $fn = ($cond instanceof \Closure) ? $cond : function() use ($cond) { return (bool)$cond; };
        $thenRule = is_string($spec) ? self::create($spec) : $spec;

        return self::create(function(&$v) use ($fn, $thenRule) {
            if (!$fn()) return true;
            $errors = $thenRule->apply($v);
            return empty($errors) ? true : $errors;
        })->order(-2);
    }

    public static function in(array $values): Rule {
        self::init();

        return self::create(fn(&$v) => in_array($v, $values, false))
        ->order(700)
        ->handleError(fn($v) => 'Not a valid option');
    }

    public static function notIn(array $values): Rule {
        self::init();

        return self::create(fn(&$v) => !in_array($v, $values, false))
        ->order(700)
        ->handleError(fn($v) => 'Value is not allowed');
    }

    public static function anyOf(...$specs): Rule {
        self::init();

        return self::create(function(&$v) use ($specs): bool {
            foreach ($specs as $spec) {
                $copy = $v;
                $rule = ($spec instanceof self) ? $spec : self::create($spec);
                if (empty($rule->apply($copy))) {
                    $v = $copy;
                    return true;
                }
            }
            return false;
        })
        ->order(500)
        ->handleError(fn($v) => 'Value does not match any allowed type');
    }

    public static function default($value, bool $valid = false): Rule {
        self::init();

        return self::create(function(&$v) use ($value, $valid): bool {
            $substituted = self::isSentinel($v) || $v === null || $v === '';
            if ($substituted) $v = $value;
            return !($substituted || ($valid && $v == $value));
        })->order(-3)->seesSentinel()->skip();
    }

    public static function regex(string $pattern): Rule {
        self::init();

        return self::create([
            'string',
            self::create(fn(&$v) => @preg_match($pattern, $v) === 1),
        ])
        ->order(700)
        ->seesSentinel()
        ->handleError(fn($v) => 'Invalid format');
    }

    public static function isSentinel($value): bool {
        return $value === self::sentinel();
    }

    private static function sentinel(): object {
        static $sentinel = null;

        if ($sentinel === null) $sentinel = new \stdClass();

        return $sentinel;
    }

    private static function init(): void {
        static $done = false;

        if ($done) return;
        $done = true;

        
        (self::create(function(&$v): bool {
            return !self::isSentinel($v);
        }))
        ->order(0)
        ->skip()
        ->seesSentinel()
        ->alias('sometimes');

        
        (self::create(function(&$v, array $p): bool {
            $substituted = self::isSentinel($v) || $v === null || $v === '';
            if ($substituted) $v = $p[0] ?? null;
            $valid = isset($p[1]) && $p[1] === 'true';
            return !($substituted || ($valid && $v == ($p[0] ?? null)));
        }))
        ->order(-3)
        ->seesSentinel()
        ->skip()
        ->alias('default');

        
        self::requiredIf(true)->alias('required');

        
        (self::create(function(&$v): bool {
            return !($v === null || $v === '' || self::isSentinel($v));
        }))
        ->order(100)
        ->skip()
        ->seesSentinel()
        ->alias('nullable');

        
        (self::create(function(&$v): bool {
            return !self::isSentinel($v);
        }))
        ->order(100)
        ->skip()
        ->seesSentinel()
        ->handleError(fn($v) => 'This field must be present')
        ->alias('present');


        (self::create(function(&$v): bool {
            if (is_string($v)) return true;
            if ($v === null || self::isSentinel($v)) { $v = ''; return true; }
            return false;
        }))
        ->order(500)
        ->seesSentinel()
        ->handleError(fn($v) => 'Must be a string')
        ->alias('string');


        (self::create(function(&$v): bool {
            if (is_int($v)) return true;
            if ($v === null || self::isSentinel($v)) { $v = 0; return true; }
            if (is_string($v) && is_numeric($v)) { $v = (int)$v; return true; }
            if (is_float($v) && is_finite($v)) { $v = (int)$v; return true; }
            if (is_bool($v)) { $v = (int)$v; return true; }
            return false;
        }))
        ->order(500)
        ->seesSentinel()
        ->handleError(fn($v) => 'Must be an integer')
        ->alias('int')
        ->alias('integer');


        (self::create(function(&$v): bool {
            if (is_float($v)) return true;
            if ($v === null || self::isSentinel($v)) { $v = 0.0; return true; }
            if (is_int($v) || is_bool($v)) { $v = (float)$v; return true; }
            if (is_numeric($v)) { $v = (float)$v; return true; }
            return false;
        }))
        ->order(500)
        ->seesSentinel()
        ->handleError(fn($v) => 'Must be a number')
        ->alias('float');

        
        (self::create(function(&$v): bool {
            if (is_bool($v)) return true;
            if ($v === null || self::isSentinel($v)) { $v = false; return true; }
            if (in_array($v, ['0', '1', 0, 1, 'true', 'false', 'checked', 'on', 'off'], true)) {
                $v = is_string($v) ? filter_var($v, FILTER_VALIDATE_BOOLEAN) : (bool)$v;
                return true;
            }
            return false;
        }))
        ->order(500)
        ->seesSentinel()
        ->handleError(fn($v) => 'Must be a boolean')
        ->alias('bool');


        (self::create(function(&$v): bool {
            return is_callable($v);
        }))
        ->order(500)
        ->handleError(fn($v) => 'Must be callable')
        ->alias('callable');


        (self::create(function(&$v): bool {
            return $v instanceof \Closure;
        }))
        ->order(500)
        ->handleError(fn($v) => 'Must be closure')
        ->alias('closure');


        self::create([
            'string',
            self::create(fn(&$v) => filter_var($v, FILTER_VALIDATE_EMAIL) !== false)
                ->handleError(fn($v) => 'Invalid email address'),
        ])
        ->order(500)
        ->seesSentinel()
        ->alias('email');


        self::create([
            'string',
            self::create(fn(&$v) => filter_var($v, FILTER_VALIDATE_URL) !== false)
                ->handleError(fn($v) => 'Invalid URL'),
        ])
        ->order(500)
        ->seesSentinel()
        ->alias('url');


        (self::create(function(&$v): bool {
            if (is_array($v)) return true;
            if ($v === null || self::isSentinel($v)) { $v = []; return true; }
            return false;
        }))
        ->order(500)
        ->seesSentinel()
        ->handleError(fn($v) => 'Must be an array')
        ->alias('array');

        
        (self::create(function(&$v, array $p): array {
            if (is_array($v) && !empty($v))
                return self::forEach($p)->apply($v);
            
            return [];
        }))
        ->order(500)
        ->alias('foreach');


        (self::create(function(&$v, array $p): bool {
            foreach ($p as $spec) {
                $copy = $v;
                if (empty(self::create($spec)->apply($copy))) {
                    $v = $copy;
                    return true;
                }
            }
            return false;
        }))
        ->order(500)
        ->handleError(fn($v) => 'Value does not match any allowed type')
        ->alias('or')
        ->alias('anyOf')
        ->alias('any_of');


        (self::create(function(&$v, array $p): bool {
            $l = $p[0] ?? null;
            if ($l === null) return true;
            $l = (float)$l;
            if (is_string($v)) return mb_strlen($v) <= $l;
            if (is_array($v))  return count($v) <= $l;
            return is_numeric($v) && (float)$v <= $l;
        }))
        ->order(700)
        ->handleError(fn($v) => 'Value is too large')
        ->alias('max');

        
        (self::create(function(&$v, array $p): bool {
            $l = $p[0] ?? null;
            if ($l === null) return true;
            $l = (float)$l;
            if (is_string($v)) return mb_strlen($v) >= $l;
            if (is_array($v))  return count($v) >= $l;
            return is_numeric($v) && (float)$v >= $l;
        }))
        ->order(700)
        ->handleError(fn($v) => 'Value is too small')
        ->alias('min');


        self::create(['array', 'min', 'max'])
            ->order(700)
            ->seesSentinel()
            ->alias('count');


        (self::create(function(&$v, array $p): bool {
            return in_array($v, $p, false);
        }))
        ->order(700)
        ->handleError(fn($v) => 'Not a valid option')
        ->alias('in');

        
        (self::create(function(&$v, array $p): bool {
            return !in_array($v, $p, false);
        }))
        ->order(700)
        ->handleError(fn($v) => 'Value is not allowed')
        ->alias('notIn')
        ->alias('not_in');


        self::create([
            'string',
            self::create(fn(&$v, array $p) => @preg_match($p[0] ?? '', $v) === 1)
                ->handleError(fn($v) => 'Invalid format'),
        ])
        ->order(700)
        ->seesSentinel()
        ->alias('regex');


        self::create([
            'string',
            self::create(fn(&$v, array $p) => ctype_digit($v) && strlen($v) === (int)($p[0] ?? 0))
                ->handleError(fn($v) => 'Must be digits only'),
        ])
        ->order(700)
        ->seesSentinel()
        ->alias('digits');


        self::create([
            'string',
            self::create(fn(&$v) => preg_match('/^#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $v) === 1)
                ->handleError(fn($v) => 'Invalid hex color'),
        ])
        ->order(700)
        ->seesSentinel()
        ->alias('hex_color')
        ->alias('hexColor');

        
        (self::create(function(&$v, array $p): bool {
            $min = (float)($p[0] ?? 0);
            $max = (float)($p[1] ?? 0);
            if (is_string($v)) { $s = mb_strlen($v); return $s >= $min && $s <= $max; }
            if (is_array($v))  { $c = count($v);     return $c >= $min && $c <= $max; }
            return is_numeric($v) && (float)$v >= $min && (float)$v <= $max;
        }))
        ->order(700)
        ->handleError(fn($v) => 'Value is out of range')
        ->alias('between');


        self::create([
            'string',
            self::create(fn(&$v) => strtotime($v) !== false)
                ->handleError(fn($v) => 'Invalid date'),
        ])
        ->order(500)
        ->seesSentinel()
        ->alias('date');


        self::create([
            'string',
            self::create(function(&$v, array $p) {
                $fmt = $p[0] ?? '';
                $d = \DateTime::createFromFormat($fmt, $v);
                return $d !== false && $d->format($fmt) === $v;
            })->handleError(fn($v) => 'Invalid date format'),
        ])
        ->order(500)
        ->seesSentinel()
        ->alias('date_format');


        self::create([
            'string',
            self::create(function(&$v) {
                @json_decode($v);
                return json_last_error() === JSON_ERROR_NONE;
            })->handleError(fn($v) => 'Invalid JSON'),
        ])
        ->order(500)
        ->seesSentinel()
        ->alias('json');


        self::create([
            'string',
            self::create(function(&$v, array $p) {
                foreach ($p as $prefix) {
                    if (strncmp($v, $prefix, strlen($prefix)) === 0) return true;
                }
                return false;
            })->handleError(fn($v) => 'Invalid prefix'),
        ])
        ->order(700)
        ->seesSentinel()
        ->alias('starts_with');


        self::create([
            'string',
            self::create(function(&$v, array $p) {
                foreach ($p as $suffix) {
                    $len = strlen($suffix);
                    if ($len === 0 || substr($v, -$len) === $suffix) return true;
                }
                return false;
            })->handleError(fn($v) => 'Invalid suffix'),
        ])
        ->order(700)
        ->seesSentinel()
        ->alias('ends_with');


        (self::create(function(&$v, array $p): bool {
            if (is_string($v)) {
                foreach ($p as $sub) {
                    if ($sub !== '' && strpos($v, $sub) !== false) return true;
                }
                return false;
            }
            if (is_array($v)) {
                foreach ($p as $sub) {
                    if (in_array($sub, $v, false)) return true;
                }
                return false;
            }
            return false;
        }))
        ->order(700)
        ->handleError(fn($v) => 'Must contain')
        ->alias('contains');

        
        (self::create(function(&$v, array $p = []): bool {
            $chars = !empty($p) ? implode('', $p) : null;
            $fn = fn($x) => is_string($x) ? ($chars !== null ? trim($x, $chars) : trim($x)) : $x;

            if (is_string($v))
                $v = $fn($v);
            elseif (is_array($v))
                $v = array_filter(array_map($fn, $v), fn($x) => $x !== '');
            return true;
        }))
        ->order(-2)
        ->alias('trim');

        (self::create(function(&$v, array $p = []): bool {
            if (is_string($v))
                $v = !empty($p) ? ltrim($v, implode('', $p)) : ltrim($v);
            return true;
        }))
        ->order(-2)
        ->alias('ltrim');


        (self::create(function(&$v, array $p = []): bool {
            if (is_string($v))
                $v = !empty($p) ? rtrim($v, implode('', $p)) : rtrim($v);
            return true;
        }))
        ->order(-2)
        ->alias('rtrim');


        (self::create(function(&$v): bool {
            if (is_string($v))
                $v = htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            return true;
        }))
        ->order(-2)
        ->alias('escape_html');


        self::create([
            'string',
            self::create(function(&$v) { $v = mb_strtoupper($v); return true; }),
        ])
        ->order(-2)
        ->seesSentinel()
        ->alias('uppercase');


        self::create([
            'string',
            self::create(function(&$v) { $v = mb_strtolower($v); return true; }),
        ])
        ->order(-2)
        ->seesSentinel()
        ->alias('lowercase');

        
        (self::create(function(&$v): bool {
            return in_array($v, [true, 1, '1', 'yes', 'on', 'true'], true);
        }))
        ->order(500)
        ->handleError(fn($v) => 'Must be accepted')
        ->alias('accepted');

        
        (self::create(function(&$v): bool {
            return in_array($v, [false, 0, '0', 'no', 'off', 'false'], true);
        }))
        ->order(500)
        ->handleError(fn($v) => 'Must be declined')
        ->alias('declined');

        
        (self::create(function(&$v): bool {
            return is_array($v)
                && isset($v['tmp_name'], $v['error'], $v['size'], $v['name'])
                && $v['error'] === UPLOAD_ERR_OK
                && is_uploaded_file($v['tmp_name']);
        }))
        ->order(500)
        ->handleError(fn($v) => 'Invalid file upload')
        ->alias('file');

        
        (self::create(function(&$v, array $p): bool {
            if (!is_array($v) || !isset($v['tmp_name']) || $v['error'] !== UPLOAD_ERR_OK) return false;
            if (!is_uploaded_file($v['tmp_name'])) return false;
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($v['tmp_name']);
            return in_array($mime, $p, true);
        }))
        ->order(600)
        ->handleError(fn($v) => 'Invalid MIME type')
        ->alias('mimes');

        
        (self::create(function(&$v, array $p): bool {
            if (!is_array($v) || !isset($v['name'])) return false;
            $ext = strtolower(pathinfo($v['name'], PATHINFO_EXTENSION));
            return in_array($ext, array_map('strtolower', $p), true);
        }))
        ->order(600)
        ->handleError(fn($v) => 'Invalid file extension')
        ->alias('extension');

        
        (self::create(function(&$v, array $p): bool {
            if (!is_array($v) || !isset($v['size'])) return false;
            $maxKb = (float)($p[0] ?? 0);
            return $v['size'] <= $maxKb * 1024;
        }))
        ->order(600)
        ->handleError(fn($v) => 'File exceeds size limit')
        ->alias('filesize');
    }
}
