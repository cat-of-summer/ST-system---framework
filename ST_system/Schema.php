<?php

namespace ST_system;

use ST_system\Rule;


final class Schema
{
    private const M_ARRAY_OF = 3;
    private const M_ONE_OF   = 4;

    private static array $registry     = [];
    private static array $nsStack      = [];
    private static array $printContext = [];
    private static int   $printDepth   = 0;

    private ?object $entityDef;
    private array   $data       = [];
    private bool    $filled     = false;
    private ?string $printCache = null;
    private ?self   $parent     = null;
    private array   $fillParams = [];
    private array   $rawData    = [];

    private function __construct(?object $entityDef = null)
    {
        $this->entityDef = $entityDef;
    }

    public static function entity(string $name, array $options = []): self
    {
        $fullPath = empty(self::$nsStack)
            ? $name
            : end(self::$nsStack) . '.' . $name;

        if (isset(self::$registry[$fullPath])) {
            throw new \RuntimeException("Schema: entity '{$fullPath}' already registered");
        }

        $def = (object)[
            'name'        => $name,
            'fullPath'    => $fullPath,
            'fields'      => $options['fields'] ?? [],
            'print'       => isset($options['print']) && is_callable($options['print']) ? $options['print'] : null,
            'toArray'     => isset($options['toArray']) && is_callable($options['toArray']) ? $options['toArray'] : null,
            'parentPath'  => empty(self::$nsStack) ? null : end(self::$nsStack),
            'beforeHooks' => [],
            'afterHooks'  => [],
        ];

        self::$registry[$fullPath] = $def;

        return new self($def);
    }

    private function scope(\Closure $fn): self
    {
        self::$nsStack[] = $this->entityDef->fullPath;
        try {
            Rule::scope($this->entityDef->fullPath, function() use ($fn) {
                $fn();
            });
        } finally {
            array_pop(self::$nsStack);
        }
        return $this;
    }

    public function __call(string $name, array $args)
    {
        if ($name === 'scope') {
            return $this->scope(...$args);
        }
        throw new \Error("Call to undefined method " . __CLASS__ . "::{$name}()");
    }

    public static function __callStatic(string $name, array $args)
    {
        if ($name === 'scope') {
            [$fullPath, $fn] = $args;

            if (isset(self::$registry[$fullPath])) {
                $instance = new self(self::$registry[$fullPath]);
            } else {
                $parts = explode('.', $fullPath);
                $def = (object)[
                    'name'        => end($parts),
                    'fullPath'    => $fullPath,
                    'fields'      => [],
                    'print'       => null,
                    'toArray'     => null,
                    'parentPath'  => count($parts) > 1 ? implode('.', array_slice($parts, 0, -1)) : null,
                    'beforeHooks' => [],
                    'afterHooks'  => [],
                ];
                self::$registry[$fullPath] = $def;
                $instance = new self($def);
            }

            return $instance->scope($fn);
        }

        throw new \Error("Call to undefined static method " . __CLASS__ . "::{$name}()");
    }

    public static function create(string $entityPath): self
    {
        if (!isset(self::$registry[$entityPath])) {
            throw new \RuntimeException("Schema: unknown entity '{$entityPath}'");
        }
        return new self(self::$registry[$entityPath]);
    }

    public static function arrayOf(string $spec): object
    {
        return (object)['__m' => self::M_ARRAY_OF, 'spec' => $spec];
    }

    public static function oneOf(array $specs): object
    {
        return (object)['__m' => self::M_ONE_OF, 'specs' => $specs];
    }

    public function fill(array $data, array $fillParams = []): self
    {
        $def = $this->entityDef;
        $ctx = $def->fullPath;

        $this->rawData = $data;

        foreach ($def->beforeHooks as $fn) {
            $fn($data);
        }

        $computed   = [];
        $ruleSchema = [];

        Rule::scope($ctx, function() use ($def, $ctx, &$ruleSchema, &$computed) {
            foreach ($def->fields as $key => $spec) {
                if ($spec instanceof \Closure) {
                    $computed[$key] = $spec;
                    continue;
                }
                $ruleSchema[$key] = self::compileFieldSpec($spec, $ctx);
            }
        });

        $result = $data;
        $errors = Rule::scope($ctx, fn() => Rule::object($ruleSchema)->apply($result));

        if (!empty($errors)) {
            throw new \RuntimeException(
                "Schema '{$ctx}' validation failed:\n" . implode("\n", $errors)
            );
        }

        foreach ($computed as $key => $fn) {
            $result[$key] = $fn($result);
        }

        $this->data       = $result;
        $this->fillParams = $fillParams;
        $this->filled     = true;
        $this->printCache = null;

        foreach ($this->data as $value) {
            if ($value instanceof self) {
                $value->parent = $this;
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof self) {
                        $item->parent = $this;
                    }
                }
            }
        }

        foreach ($def->afterHooks as $fn) {
            $fn($this);
        }

        return $this;
    }

    public function append(array $data): self
    {
        if (!$this->filled) {
            return $this->fill($data);
        }
        return $this->fill(array_merge($this->rawData, $data), $this->fillParams);
    }

    public function before(callable $fn): self
    {
        $this->entityDef->beforeHooks[] = $fn;
        return $this;
    }

    public function after(callable $fn): self
    {
        $this->entityDef->afterHooks[] = $fn;
        return $this;
    }

    public function set(string $path, $value): self
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

    public function print(array $params = []): string
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

            if ($this->entityDef->print !== null) {
                $out = (string)($this->entityDef->print)($this, self::$printContext);
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

    public function field(string $name = '')
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

    public function toArray(array $params = []): array
    {
        if ($this->entityDef->toArray !== null) {
            return ($this->entityDef->toArray)($this, $params);
        }

        $out = [];
        foreach ($this->data as $key => $value) {
            $out[$key] = self::deepExport($value, $params);
        }
        return $out;
    }

    public function parent(): ?self
    {
        return $this->parent;
    }

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
            $arr = $value->toArray($params);
            if ($value->entityDef->toArray === null) {
                $arr = array_merge(['@type' => $value->entityDef->name], $arr);
            }
            return $arr;
        }

        if (is_array($value)) {
            return array_map(static fn($item) => self::deepExport($item, $params), $value);
        }

        return $value;
    }

    private static function compileFieldSpec($spec, string $ctx): Rule
    {
        if ($spec instanceof Rule) {
            return $spec;
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
                $refRule = self::makeEntityRefRule($refPart, $ctx);
                if (empty($mods)) {
                    return Rule::create([$refRule, 'required']);
                }
                array_unshift($mods, $refRule);
                return Rule::create($mods);
            }
        }

        if (is_string($spec)) {
            return Rule::create($spec);
        }

        if (is_object($spec) && isset($spec->__m)) {
            if ($spec->__m === self::M_ARRAY_OF) {
                return Rule::create([self::makeArrayOfRule($spec->spec, $ctx), 'required']);
            }
            if ($spec->__m === self::M_ONE_OF) {
                return Rule::create([self::makeOneOfRule($spec->specs, $ctx), 'required']);
            }
        }

        if (is_array($spec)) {
            $marker = null;
            $mods   = [];
            foreach ($spec as $item) {
                if (is_object($item) && isset($item->__m)) {
                    if ($item->__m === self::M_ARRAY_OF) {
                        $marker = self::makeArrayOfRule($item->spec, $ctx);
                    } elseif ($item->__m === self::M_ONE_OF) {
                        $marker = self::makeOneOfRule($item->specs, $ctx);
                    }
                } elseif ($marker === null && is_string($item) && strpos($item, '@') === 0) {
                    $marker = self::makeEntityRefRule($item, $ctx);
                } else {
                    $mods[] = $item;
                }
            }
            if ($marker !== null) {
                if (empty($mods)) {
                    return Rule::create([$marker, 'required']);
                }
                array_unshift($mods, $marker);
                return Rule::create($mods);
            }
            return Rule::create($spec);
        }

        throw new \RuntimeException("Schema: unsupported field spec type");
    }

    private static function makeEntityRefRule(string $ref, string $ctx): Rule
    {
        $entityPath = self::resolveRef($ref, $ctx);

        return Rule::create(function(&$v) use ($entityPath): bool {
            $v = self::coerceToSchema($entityPath, $v);
            return true;
        })->order(600);
    }

    private static function makeArrayOfRule(string $spec, string $ctx): Rule
    {
        return Rule::create(function(&$v) use ($spec, $ctx): array {
            if (!is_array($v)) {
                return ['Expected array'];
            }
            try {
                $v = self::processArrayOf($spec, $v, $ctx);
                return [];
            } catch (\RuntimeException $e) {
                return [$e->getMessage()];
            }
        })->order(600);
    }

    private static function makeOneOfRule(array $specs, string $ctx): Rule
    {
        return Rule::create(function(&$v) use ($specs, $ctx): array {
            try {
                $v = self::processOneOf($specs, $v, $ctx);
                return [];
            } catch (\RuntimeException $e) {
                return [$e->getMessage()];
            }
        })->order(600);
    }

    private static function resolveRef(string $ref, string $contextPath): string
    {
        $name = ltrim($ref, '@');

        if (isset(self::$registry[$name])) {
            return $name;
        }

        $parts = explode('.', $contextPath);
        while (!empty($parts)) {
            $candidate = implode('.', $parts) . '.' . $name;
            if (isset(self::$registry[$candidate])) {
                return $candidate;
            }
            array_pop($parts);
        }

        throw new \RuntimeException("Cannot resolve '@{$name}' from '{$contextPath}'");
    }

    private static function coerceToSchema(string $entityPath, $value): self
    {
        if ($value instanceof self) {
            if ($value->entityDef->fullPath !== $entityPath) {
                throw new \RuntimeException(
                    "Expected '{$entityPath}', got '{$value->entityDef->fullPath}'"
                );
            }
            if (!$value->filled) {
                throw new \RuntimeException(
                    "Schema instance for '{$entityPath}' is not filled"
                );
            }
            return $value;
        }

        if (is_array($value)) {
            return self::create($entityPath)->fill($value);
        }

        throw new \RuntimeException("Expected array or Schema for '{$entityPath}'");
    }

    private static function processArrayOf(string $spec, array $items, string $ctx): array
    {
        $refName = (strpos($spec, '@') === 0) ? $spec : '@' . $spec;

        try {
            $entityPath = self::resolveRef($refName, $ctx);
        } catch (\RuntimeException $e) {
            $entityPath = null;
        }

        if ($entityPath !== null) {
            $result = [];
            foreach ($items as $i => $item) {
                try {
                    $result[] = self::coerceToSchema($entityPath, $item);
                } catch (\RuntimeException $e) {
                    throw new \RuntimeException("[{$i}].{$e->getMessage()}");
                }
            }
            return $result;
        }

        $rule = Rule::forEach($spec);
        $copy = $items;
        $ruleErrors = $rule->apply($copy);

        if (!empty($ruleErrors)) {
            throw new \RuntimeException(implode('; ', $ruleErrors));
        }

        return $copy;
    }

    private static function processOneOf(array $specs, $value, string $ctx)
    {
        $lastErr = '';

        foreach ($specs as $spec) {
            try {
                if (is_string($spec) && strpos($spec, '@') === 0) {
                    return self::coerceToSchema(
                        self::resolveRef($spec, $ctx),
                        $value
                    );
                }

                if (is_string($spec)) {
                    $copy = $value;
                    $errs = Rule::create($spec)->apply($copy);
                    if (empty($errs)) {
                        return $copy;
                    }
                    $lastErr = implode('; ', $errs);
                }
            } catch (\RuntimeException $e) {
                $lastErr = $e->getMessage();
            }
        }

        throw new \RuntimeException(
            'No match in [' . implode(', ', $specs) . ']'
            . ($lastErr !== '' ? ": {$lastErr}" : '')
        );
    }
}
