<?php

namespace ST_system\Schemas;

use ST_system\Rule;
use ST_system\Main;

class DefaultSchema
{
    private const ROOT_NAMESPACE = __NAMESPACE__;
    private const M_ARRAY_OF     = 3;
    private const M_ONE_OF       = 4;
    private const REF_RE         = '/^[a-z][a-z0-9-]*$/';

    private static array $printContext = [];
    private static int   $printDepth   = 0;
    private static array $initialized  = [];
    private static array $scopeCache   = [];
    private static array $nameCache    = [];

    private array     $data        = [];
    private bool      $filled      = false;
    private ?string   $printCache  = null;
    private ?self     $parent      = null;
    private array     $fillParams  = [];
    private array     $rawData     = [];
    private array     $beforeHooks = [];
    private array     $afterHooks  = [];

    private ?array    $inlineFields  = null;
    private ?\Closure $inlinePrint   = null;
    private ?\Closure $inlineToArray = null;

    public static function create(...$args): static { return new static(...$args); }

    /**
     * Подкласс — обычный `new ChildClass()`.
     * Inline-схема — `new DefaultSchema(['fields' => [...], 'print' => fn($s)=>..., 'toArray' => fn($s)=>...])`.
     */
    final public function __construct(?array $inline = null)
    {
        if ($inline !== null) {
            if (isset($inline['fields']) && is_array($inline['fields'])) {
                $this->inlineFields = $inline['fields'];
            }
            if (isset($inline['print']) && $inline['print'] instanceof \Closure) {
                $this->inlinePrint = $inline['print'];
            }
            if (isset($inline['toArray']) && $inline['toArray'] instanceof \Closure) {
                $this->inlineToArray = $inline['toArray'];
            }
        }
        static::ensureInited();
    }

    // ─── Hooks for subclasses ────────────────────────────────────────────────

    protected static function getFields(): array
    {
        return [];
    }

    protected static function getPrint(): ?\Closure
    {
        return null;
    }

    protected static function getToArray(): ?\Closure
    {
        return null;
    }

    protected static function _init(): void {}

    // ─── Derived identity ────────────────────────────────────────────────────

    final public static function name(): string
    {
        $class = static::class;
        if (isset(self::$nameCache[$class])) {
            return self::$nameCache[$class];
        }
        $pos      = strrpos($class, '\\');
        $basename = $pos === false ? $class : substr($class, $pos + 1);
        return self::$nameCache[$class] = Main::kebabCase($basename);
    }

    final public static function scope(): string
    {
        $class = static::class;
        if (isset(self::$scopeCache[$class])) {
            return self::$scopeCache[$class];
        }
        $root = self::ROOT_NAMESPACE;
        if (strpos($class, $root . '\\') !== 0) {
            throw new \LogicException("Schema class {$class} must live under {$root}");
        }
        $relative = substr($class, strlen($root) + 1);
        $segments = explode('\\', $relative);
        array_pop($segments);
        $parts = array_map([Main::class, 'kebabCase'], $segments);
        return self::$scopeCache[$class] = implode('.', $parts);
    }

    final public static function path(): string
    {
        $scope = static::scope();
        return $scope === '' ? static::name() : $scope . '.' . static::name();
    }

    // ─── Lazy init walk-up ───────────────────────────────────────────────────

    private static function ensureInited(): void
    {
        $class = static::class;
        if (isset(self::$initialized[$class])) {
            return;
        }
        self::$initialized[$class] = true;

        $pos  = strrpos($class, '\\');
        $root = self::ROOT_NAMESPACE;
        if ($pos !== false) {
            $ns = substr($class, 0, $pos);
            if (
                $ns !== '' && $ns !== $root && strpos($ns, $root . '\\') === 0
                && class_exists($ns) && is_subclass_of($ns, self::class)
            ) {
                $ns::ensureInited();
            }
        }

        $path = $class::path();
        $init     = static fn() => $class::_init();

        if ($path === '') {
            $init();
        } else {
            self::withEntityScope($path, $init);
        }
    }

    // ─── arrayOf / oneOf markers ─────────────────────────────────────────────

    final public static function arrayOf(string $spec): object
    {
        return (object)['__m' => self::M_ARRAY_OF, 'spec' => $spec];
    }

    final public static function oneOf(array $specs): object
    {
        return (object)['__m' => self::M_ONE_OF, 'specs' => $specs];
    }

    // ─── Fill ────────────────────────────────────────────────────────────────

    final public function fill(array $data, array $fillParams = []): self
    {
        $ctx      = static::path();
        $ctxClass = static::class;

        $this->rawData = $data;

        foreach ($this->beforeHooks as $fn) {
            $fn($data);
        }

        $computed   = [];
        $ruleSchema = [];

        $fields = $this->inlineFields ?? static::getFields();

        self::withEntityScope($ctx, function () use ($fields, $ctxClass, &$ruleSchema, &$computed): void {
            foreach ($fields as $key => $spec) {
                if ($spec instanceof \Closure) {
                    $computed[$key] = $spec;
                    continue;
                }

                if ($spec instanceof Rule) {
                    $ruleSchema[$key] = $spec;
                    continue;
                }

                if (is_string($spec) && strpos($spec, '@') !== false) {
                    $parts   = explode('|', $spec);
                    $refPart = null;
                    $mods    = [];
                    foreach ($parts as $part) {
                        if ($refPart === null && strpos($part, '@') === 0) {
                            $refPart = $part;
                        } else {
                            $mods[] = $part;
                        }
                    }
                    if ($refPart !== null) {
                        $refRule = self::makeEntityRefRule(ltrim($refPart, '@'), $ctxClass);
                        if (empty($mods)) {
                            $ruleSchema[$key] = Rule::create([$refRule, 'required']);
                        } else {
                            array_unshift($mods, $refRule);
                            $ruleSchema[$key] = Rule::create($mods);
                        }
                        continue;
                    }
                }

                if (is_string($spec)) {
                    if (self::looksLikeClass($spec)) {
                        $ruleSchema[$key] = Rule::create([self::makeEntityRefRule($spec, $ctxClass), 'required']);
                    } else {
                        $ruleSchema[$key] = Rule::create($spec);
                    }
                    continue;
                }

                if (is_object($spec) && isset($spec->__m)) {
                    if ($spec->__m === self::M_ARRAY_OF) {
                        $ruleSchema[$key] = Rule::create([self::makeArrayOfRule($spec->spec, $ctxClass), 'required']);
                        continue;
                    }
                    if ($spec->__m === self::M_ONE_OF) {
                        $ruleSchema[$key] = Rule::create([self::makeOneOfRule($spec->specs, $ctxClass), 'required']);
                        continue;
                    }
                }

                if (is_array($spec)) {
                    $marker = null;
                    $mods   = [];
                    foreach ($spec as $item) {
                        if (is_object($item) && isset($item->__m)) {
                            if ($item->__m === self::M_ARRAY_OF) {
                                $marker = self::makeArrayOfRule($item->spec, $ctxClass);
                            } elseif ($item->__m === self::M_ONE_OF) {
                                $marker = self::makeOneOfRule($item->specs, $ctxClass);
                            }
                        } elseif ($marker === null && is_string($item) && strpos($item, '@') === 0) {
                            $marker = self::makeEntityRefRule(ltrim($item, '@'), $ctxClass);
                        } elseif ($marker === null && is_string($item) && self::looksLikeClass($item)) {
                            $marker = self::makeEntityRefRule($item, $ctxClass);
                        } else {
                            $mods[] = $item;
                        }
                    }
                    if ($marker !== null) {
                        if (empty($mods)) {
                            $ruleSchema[$key] = Rule::create([$marker, 'required']);
                        } else {
                            array_unshift($mods, $marker);
                            $ruleSchema[$key] = Rule::create($mods);
                        }
                        continue;
                    }
                    $ruleSchema[$key] = Rule::create($spec);
                    continue;
                }

                throw new \RuntimeException('Schema: unsupported field spec type');
            }
        });

        $result = $data;
        self::withEntityScope($ctx, function () use (&$result, $ruleSchema): void {
            Rule::object($ruleSchema)
                ->throwable()
                ->apply($result);
        });

        foreach ($computed as $key => $fn) {
            $result[$key] = $fn($result);
        }

        $this->data       = $result;
        $this->fillParams = $fillParams;
        $this->filled     = true;
        $this->printCache = null;

        foreach ($this->data as $value) {
            self::linkParent($value, $this);
        }

        foreach ($this->afterHooks as $fn) {
            $fn($this);
        }

        return $this;
    }

    final public function append(array $data): self
    {
        if (!$this->filled) {
            return $this->fill($data);
        }
        return $this->fill(array_merge($this->rawData, $data), $this->fillParams);
    }

    final public function before(callable $fn): self
    {
        $this->beforeHooks[] = $fn;
        return $this;
    }

    final public function after(callable $fn): self
    {
        $this->afterHooks[] = $fn;
        return $this;
    }

    final public function set(string $path, $value): self
    {
        $path = str_replace(['[', ']'], '', $path);

        if (strpos($path, '.') === false) {
            $this->data[$path] = $value;
            $this->printCache  = null;
            self::linkParent($value, $this);
            return $this;
        }

        [$head, $tail] = explode('.', $path, 2);

        if (($this->data[$head] ?? null) instanceof self) {
            $this->data[$head]->set($tail, $value);
        } elseif (is_array($this->data[$head] ?? null)) {
            [$idx, $rest] = strpos($tail, '.') !== false
                ? explode('.', $tail, 2)
                : [$tail, null];

            $idx = (int)$idx;

            if ($rest === null) {
                $this->data[$head][$idx] = $value;
                self::linkParent($value, $this);
            } elseif (($this->data[$head][$idx] ?? null) instanceof self) {
                $this->data[$head][$idx]->set($rest, $value);
            } else {
                $this->data[$head][$idx] = $value;
                self::linkParent($value, $this);
            }
        } else {
            $this->data[$head] = $value;
            self::linkParent($value, $this);
        }

        $this->printCache = null;
        return $this;
    }

    // ─── Output ──────────────────────────────────────────────────────────────

    final public function print(array $params = []): string
    {
        if (!$this->filled) {
            throw new \RuntimeException('Schema: cannot print unfilled instance');
        }

        $isTop = self::$printDepth === 0;
        if ($isTop) {
            self::$printContext = Main::merge($this->fillParams, $params);
        }
        self::$printDepth++;

        try {
            $useCache = $isTop && empty(self::$printContext);

            if ($useCache && $this->printCache !== null) {
                return $this->printCache;
            }

            $printer = $this->inlinePrint ?? static::getPrint();
            if ($printer !== null) {
                $out = (string)$printer($this, self::$printContext);
            } else {
                $out = json_encode(
                    $this->toArray(self::$printContext),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
            }

            if ($useCache) {
                $this->printCache = $out;
            }

            return $out;
        } finally {
            self::$printDepth--;
            if ($isTop) {
                self::$printContext = [];
            }
        }
    }

    final public function field(string $name = '')
    {
        if ($name === '') {
            return $this->data;
        }

        $name = str_replace(['[', ']'], '', $name);

        if (strpos($name, '.') === false) {
            return $this->data[$name] ?? null;
        }

        [$head, $tail] = explode('.', $name, 2);
        $value = $this->data[$head] ?? null;

        if ($value instanceof self) {
            return $value->field($tail);
        }

        if (is_array($value)) {
            [$idx, $rest] = strpos($tail, '.') !== false
                ? explode('.', $tail, 2)
                : [$tail, null];

            if (ctype_digit($idx)) {
                $item = $value[(int)$idx] ?? null;
                if ($rest === null) {
                    return $item;
                }
                if ($item instanceof self) {
                    return $item->field($rest);
                }
            }
        }

        return null;
    }

    final public function toArray(array $params = []): array
    {
        $custom = $this->inlineToArray ?? static::getToArray();
        if ($custom !== null) {
            return $custom($this, $params);
        }

        $out = [];
        foreach ($this->data as $key => $value) {
            $out[$key] = self::deepExport($value, $params);
        }
        return $out;
    }

    final public function parent(): ?self
    {
        return $this->parent;
    }

    // ─── Internal helpers ────────────────────────────────────────────────────

    private static function linkParent($value, self $parent): void
    {
        if ($value instanceof self) {
            $value->parent = $parent;
        } elseif (is_array($value)) {
            foreach ($value as $item) {
                if ($item instanceof self) {
                    $item->parent = $parent;
                }
            }
        }
    }

    private static function deepExport($value, array $params)
    {
        if ($value instanceof self) {
            return $value->toArray($params);
        }

        if (is_array($value)) {
            return array_map(static fn ($item) => self::deepExport($item, $params), $value);
        }

        return $value;
    }

    private static function withEntityScope(string $ctx, \Closure $fn): void
    {
        if ($ctx === '') {
            $fn();
            return;
        }

        $segments = explode('.', $ctx);
        $invoke   = static function () use ($ctx, $fn): void { Rule::scope($ctx, $fn); };

        for ($i = count($segments) - 1; $i >= 1; $i--) {
            $ancestor = implode('.', array_slice($segments, 0, $i));
            $inner    = $invoke;
            $invoke   = static function () use ($ancestor, $inner): void {
                Rule::scope($ancestor, $inner);
            };
        }

        $invoke();
    }

    // ─── Ref resolution ──────────────────────────────────────────────────────

    private static function resolveRef(string $ref, string $ctxClass): string
    {
        if (self::looksLikeClass($ref)) {
            if (!is_subclass_of($ref, self::class)) {
                throw new \RuntimeException("Schema: '{$ref}' is not a DefaultSchema subclass");
            }
            return $ref;
        }

        $ref      = ltrim($ref, '@');
        $basename = Main::studlyCase($ref);

        $candidate = $ctxClass . '\\' . $basename;
        if (class_exists($candidate) && is_subclass_of($candidate, self::class)) {
            return $candidate;
        }

        $root = self::ROOT_NAMESPACE;
        $ns   = $ctxClass;
        while (($pos = strrpos($ns, '\\')) !== false) {
            $ns = substr($ns, 0, $pos);
            if ($ns === '' || strpos($ns, $root) !== 0) {
                break;
            }
            $candidate = $ns . '\\' . $basename;
            if (class_exists($candidate) && is_subclass_of($candidate, self::class)) {
                return $candidate;
            }
            if ($ns === $root) {
                break;
            }
        }

        throw new \RuntimeException("Schema: cannot resolve '{$ref}' from '{$ctxClass}'");
    }

    private static function looksLikeClass(string $s): bool
    {
        return strpos($s, '\\') !== false && class_exists($s);
    }

    // ─── Ref-rule helpers ────────────────────────────────────────────────────

    private static function makeEntityRefRule(string $ref, string $ctxClass): Rule
    {
        $fqcn = self::resolveRef($ref, $ctxClass);

        return Rule::create(function (&$v) use ($fqcn): bool {
            $v = self::coerceToSchema($fqcn, $v);
            return true;
        })->order(600);
    }

    private static function makeArrayOfRule(string $spec, string $ctxClass): Rule
    {
        return Rule::create(function (&$v) use ($spec, $ctxClass): array {
            if (!is_array($v)) {
                return ['Expected array'];
            }
            try {
                $isRef = $spec !== '' && (
                    $spec[0] === '@'
                    || strpos($spec, '\\') !== false
                    || (bool)preg_match(self::REF_RE, $spec)
                );

                if ($isRef) {
                    $fqcn   = self::resolveRef($spec, $ctxClass);
                    $result = [];
                    foreach ($v as $i => $item) {
                        try {
                            $result[] = self::coerceToSchema($fqcn, $item);
                        } catch (\RuntimeException $e) {
                            throw new \RuntimeException("[{$i}].{$e->getMessage()}");
                        }
                    }
                    $v = $result;
                    return [];
                }

                $rule       = Rule::forEach($spec);
                $copy       = $v;
                $ruleErrors = $rule->apply($copy);

                if (!empty($ruleErrors)) {
                    return $ruleErrors;
                }

                $v = $copy;
                return [];
            } catch (\RuntimeException $e) {
                return [$e->getMessage()];
            }
        })->order(600);
    }

    private static function makeOneOfRule(array $specs, string $ctxClass): Rule
    {
        return Rule::create(function (&$v) use ($specs, $ctxClass): array {
            $lastErr = '';

            foreach ($specs as $spec) {
                if (!is_string($spec)) {
                    return ['oneOf: unsupported spec type ' . (is_object($spec) ? get_class($spec) : gettype($spec))];
                }

                try {
                    if ($spec[0] === '@') {
                        $v = self::coerceToSchema(self::resolveRef(ltrim($spec, '@'), $ctxClass), $v);
                        return [];
                    }

                    if (self::looksLikeClass($spec)) {
                        $v = self::coerceToSchema(self::resolveRef($spec, $ctxClass), $v);
                        return [];
                    }

                    $copy = $v;
                    $errs = Rule::create($spec)->apply($copy);
                    if (empty($errs)) {
                        $v = $copy;
                        return [];
                    }
                    $lastErr = implode('; ', $errs);
                } catch (\RuntimeException $e) {
                    $lastErr = $e->getMessage();
                }
            }

            return [
                'No match in [' . implode(', ', $specs) . ']'
                . ($lastErr !== '' ? ": {$lastErr}" : '')
            ];
        })->order(600);
    }

    private static function coerceToSchema(string $fqcn, $value): self
    {
        if ($value instanceof self) {
            if (!($value instanceof $fqcn)) {
                throw new \RuntimeException(
                    "Expected '{$fqcn}', got '" . get_class($value) . "'"
                );
            }
            if (!$value->filled) {
                throw new \RuntimeException(
                    "Schema instance for '{$fqcn}' is not filled"
                );
            }
            return $value;
        }

        if (is_array($value)) {
            $inst = new $fqcn();
            return $inst->fill($value);
        }

        throw new \RuntimeException("Expected array or Schema for '{$fqcn}'");
    }

}
