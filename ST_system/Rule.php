<?php

namespace ST_system;

final class Rule {

    /** @var array<string, Rule> */
    private static $registry = [];

    /** @var object|null  Sentinel: поле не существует */
    private static $undefined = null;

    /** @var \Closure|null */
    private $callback;
    /** @var \Closure|null */
    private $before;
    /** @var \Closure|null */
    private $after;
    /** @var \Closure|null */
    private $handleError;
    /** @var int */
    private $order = 0;
    /** @var bool */
    private $skip = false;
    /** @var array */
    private $params = [];
    /** @var bool */
    private $frozen = false;

    private function __construct(?\Closure $callback = null) {
        self::init(false);
        $this->callback = $callback;
    }

    // ─── Геттеры ─────────────────────────────────────────────────────

    /** @param string $name callback|before|after|handleError|order|skip|params|frozen */
    public function __get(string $name) {
        if (in_array($name, ['callback', 'before', 'after', 'handleError', 'order', 'skip', 'params', 'frozen'])) {
            return $this->$name;
        }
        throw new \RuntimeException("Unknown property Rule::\${$name}");
    }

    // ─── Fluent-сеттеры ──────────────────────────────────────────────

    private function guardFrozen(): void {
        if ($this->frozen) {
            throw new \RuntimeException('Cannot modify a frozen Rule (aliased rules are immutable)');
        }
    }

    /** @param mixed $fn  Closure — устанавливает, иное — обнуляет */
    public function before($fn): self {
        $this->guardFrozen();
        $this->before = ($fn instanceof \Closure) ? $fn : null;
        return $this;
    }

    /** @param mixed $fn  Closure — устанавливает, иное — обнуляет */
    public function after($fn): self {
        $this->guardFrozen();
        $this->after = ($fn instanceof \Closure) ? $fn : null;
        return $this;
    }

    /** @param mixed $fn  Closure — устанавливает, иное — обнуляет */
    public function handleError($fn): self {
        $this->guardFrozen();
        $this->handleError = ($fn instanceof \Closure) ? $fn : null;
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

    // ─── copy (private) ──────────────────────────────────────────────

    private function copy(): self {
        $clone = clone $this;
        $clone->frozen = false;
        return $clone;
    }

    // ─── Реестр ──────────────────────────────────────────────────────

    public static function get(string $alias): ?Rule {
        self::init(false);
        return self::$registry[$alias] ?? null;
    }

    public function alias(string $name): self {
        if (isset(self::$registry[$name]) && self::$registry[$name] !== $this) {
            throw new \RuntimeException("Rule alias '{$name}' already registered");
        }
        self::$registry[$name] = $this;
        $this->frozen = true;
        return $this;
    }

    // ─── Ядро: execute / apply ───────────────────────────────────────

    /**
     * @param mixed $data
     * @return array{0: bool, 1: string[]}
     */
    private function execute(&$data): array {
        if ($this->before !== null) {
            ($this->before)($data);
        }

        $errors = [];
        $passed = true;

        if ($this->callback !== null) {
            try {
                $result = ($this->callback)($data, $this->params);
            } catch (\Throwable $th) {
                if ($this->after !== null) ($this->after)($data);
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
            ($this->after)($data);
        }

        return [$passed, $errors];
    }

    /**
     * Применить правило к значению. Мутирует $data, возвращает массив ошибок.
     *
     * @param mixed $data
     * @return string[]
     */
    public function apply(&$data): array {
        return $this->execute($data)[1];
    }

    /**
     * Проверить значение без мутации вызывающего кода. Принимает по значению.
     * Удобно для передачи литералов: Rule::create('required|int')->check('abc')
     *
     * @param mixed $data
     * @return string[]
     */
    public function check($data): array {
        return $this->execute($data)[1];
    }

    // ─── Парсинг строки ──────────────────────────────────────────────

    /**
     * Разбирает строку 'required|string|max:50' в массив Rule.
     * @return Rule[]
     */
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

            $tpl = self::$registry[$name] ?? null;
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

    /**
     * Компилирует спецификацию поля в отсортированный массив Rule.
     *
     * @param string|array|Rule $spec
     * @return Rule[]
     */
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

    // ─── Фабрика ─────────────────────────────────────────────────────

    /**
     * @param string|\Closure $spec
     * @return Rule
     */
    public static function create($spec): Rule {
        self::init(false);

        if ($spec instanceof \Closure) {
            return new self($spec);
        }

        if (is_string($spec)) {
            $subRules = self::parseString($spec);
            usort($subRules, fn(Rule $a, Rule $b) => $a->order <=> $b->order);

            if (count($subRules) === 0) {
                return new self(fn(&$v) => true);
            }
            if (count($subRules) === 1) {
                return $subRules[0];
            }

            return new self(function(&$data) use ($subRules): array {
                $errors = [];
                foreach ($subRules as $rule) {
                    [$passed, $ruleErrors] = $rule->execute($data);
                    if (!empty($ruleErrors)) {
                        array_push($errors, ...$ruleErrors);
                    }
                    if (!$passed && $rule->skip) break;
                }
                return $errors;
            });
        }

        if (is_array($spec)) {
            $subRules = self::compileFieldRules($spec);

            if (count($subRules) === 0) {
                return new self(fn(&$v) => true);
            }
            if (count($subRules) === 1) {
                return $subRules[0];
            }

            return new self(function(&$data) use ($subRules): array {
                $errors = [];
                foreach ($subRules as $rule) {
                    [$passed, $ruleErrors] = $rule->execute($data);
                    if (!empty($ruleErrors)) {
                        array_push($errors, ...$ruleErrors);
                    }
                    if (!$passed && $rule->skip) break;
                }
                return $errors;
            });
        }

        throw new \InvalidArgumentException('Rule::create() expects string, Closure, or array');
    }

    // ─── Хелперы ─────────────────────────────────────────────────────

    /**
     * Валидация ассоциативного массива / объекта по схеме полей.
     */
    public static function object(array $schema): Rule {
        self::init(false);

        // ── отделяем dot-notation ключи от обычных ──────────────────
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

        return new self(function(&$data) use ($compiled): array {
            if (is_object($data)) $data = (array)$data;
            if (!is_array($data)) return ['Expected array or object'];

            $errors = [];
            $result = [];
            $UNDEFINED = self::$undefined;

            foreach ($compiled as $key => $rules) {
                $temp = array_key_exists($key, $data) ? $data[$key] : $UNDEFINED;

                foreach ($rules as $rule) {
                    [$passed, $ruleErrors] = $rule->execute($temp);
                    foreach ($ruleErrors as $err) {
                        $errors[] = "{$key}.{$err}";
                    }
                    if (!$passed && $rule->skip) break;
                }

                if ($temp !== $UNDEFINED) {
                    $result[$key] = $temp;
                }
            }

            $data = $result;
            return $errors;
        });
    }

    /**
     * Валидация каждого элемента массива.
     *
     * @param string|Rule|\Closure $spec
     */
    public static function forEach($spec): Rule {
        self::init(false);

        if ($spec instanceof \Closure) {
            $innerRule = new self($spec);
            $rules = [$innerRule];
        } elseif ($spec instanceof self) {
            $rules = [$spec->copy()];
        } elseif (is_string($spec)) {
            $rules = self::parseString($spec);
            usort($rules, fn(Rule $a, Rule $b) => $a->order <=> $b->order);
        } else {
            throw new \InvalidArgumentException('Rule::forEach() expects string, Closure, or Rule');
        }

        return new self(function(&$data) use ($rules): array {
            if (!is_array($data) && !($data instanceof \Traversable)) {
                return ['Expected iterable'];
            }

            $errors = [];
            foreach ($data as $i => &$item) {
                foreach ($rules as $rule) {
                    [$passed, $ruleErrors] = $rule->execute($item);
                    foreach ($ruleErrors as $err) {
                        $errors[] = "{$i}.{$err}";
                    }
                    if (!$passed && $rule->skip) break;
                }
            }
            unset($item);
            return $errors;
        });
    }

    // ─── Плоская (dot-notation) схема ────────────────────────────────

    /**
     * Рекурсивно вставляет $spec в дерево по массиву частей пути.
     * Leaf-узел хранится как ['__spec__' => $spec].
     */
    private static function insertIntoTree(array &$node, array $parts, $spec): void {
        $key = array_shift($parts);

        if (empty($parts)) {
            // Leaf
            $node[$key] = ['__spec__' => $spec];
        } else {
            // Если на этом уровне уже лежит leaf — заменяем его на subtree
            if (!isset($node[$key]) || isset($node[$key]['__spec__'])) {
                $node[$key] = [];
            }
            self::insertIntoTree($node[$key], $parts, $spec);
        }
    }

    /**
     * Рекурсивно превращает узел дерева в Rule.
     *
     * @param array $node
     */
    private static function treeNodeToRule(array $node): Rule {
        // Leaf
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

        // Ordinary object
        $schema = [];
        foreach ($node as $childKey => $childNode) {
            $schema[$childKey] = self::treeNodeToRule($childNode);
        }
        return self::object($schema);
    }

    // ─── Условные хелперы ────────────────────────────────────────────

    /**
     * @param bool|\Closure $cond
     */
    public static function requiredIf($cond): Rule {
        self::init(false);
        $fn = ($cond instanceof \Closure) ? $cond : function() use ($cond) { return (bool)$cond; };
        $UNDEFINED = self::$undefined;

        $rule = new self(function(&$v) use ($fn, $UNDEFINED): bool {
            if (!$fn($v)) return true;
            return $v !== $UNDEFINED && $v !== null && $v !== '';
        });
        $rule->order = 100;
        $rule->skip  = true;
        $rule->handleError = fn($v) => 'This field is required';
        return $rule;
    }

    /**
     * @param bool|\Closure $cond
     */
    public static function prohibitedIf($cond): Rule {
        self::init(false);
        $fn = ($cond instanceof \Closure) ? $cond : function() use ($cond) { return (bool)$cond; };
        $UNDEFINED = self::$undefined;

        $rule = new self(function(&$v) use ($fn, $UNDEFINED): bool {
            if (!$fn($v)) return true;
            return $v === $UNDEFINED || $v === null || $v === '';
        });
        $rule->order = 100;
        $rule->skip  = true;
        $rule->handleError = fn($v) => 'This field is not allowed';
        return $rule;
    }

    /**
     * @param bool|\Closure $cond
     */
    public static function excludeIf($cond): Rule {
        self::init(false);
        $fn = ($cond instanceof \Closure) ? $cond : function() use ($cond) { return (bool)$cond; };
        $UNDEFINED = self::$undefined;

        $rule = new self(function(&$v) use ($fn, $UNDEFINED): bool {
            if (!$fn($v)) return true;
            $v = $UNDEFINED;
            return false;
        });
        $rule->order = 0;
        $rule->skip  = true;
        return $rule;
    }

    /**
     * @param bool|\Closure $cond
     * @param string|Rule $spec
     */
    public static function when($cond, $spec): Rule {
        self::init(false);
        $fn = ($cond instanceof \Closure) ? $cond : function() use ($cond) { return (bool)$cond; };
        $thenRule = is_string($spec) ? self::create($spec) : $spec;

        return new self(function(&$v) use ($fn, $thenRule) {
            if (!$fn()) return true;
            $errors = $thenRule->apply($v);
            return empty($errors) ? true : $errors;
        });
    }

    public static function in(array $values): Rule {
        self::init(false);
        $rule = new self(fn(&$v) => in_array($v, $values, false));
        $rule->handleError = fn($v) => 'Not a valid option';
        return $rule;
    }

    public static function notIn(array $values): Rule {
        self::init(false);
        $rule = new self(fn(&$v) => !in_array($v, $values, false));
        $rule->handleError = fn($v) => 'Value is not allowed';
        return $rule;
    }

    /**
     * Статический хелпер для regex-паттернов, содержащих |
     */
    public static function regex(string $pattern): Rule {
        self::init(false);
        $rule = new self(fn(&$v) => is_string($v) && @preg_match($pattern, $v) === 1);
        $rule->handleError = fn($v) => 'Invalid format';
        return $rule;
    }

    // ─── Инициализация стандартных правил ─────────────────────────────

    public static function init(bool $refresh = true): void {
        static $done = false;

        if ($refresh) {
            $done            = false;
            self::$registry  = [];
            self::$undefined = null;
        }
        if ($done) return;
        $done = true;

        self::$undefined = new \stdClass();
        $UNDEFINED = self::$undefined;

        // sometimes (order 0, skip=true, без handleError — поле пропускается если отсутствует)
        (static::create(function(&$v) use ($UNDEFINED): bool {
            return $v !== $UNDEFINED;
        }))->order(0)->skip(true)->alias('sometimes');

        // default (order 50 — подставляет значение если undefined/null/'')
        (static::create(function(&$v, array $p) use ($UNDEFINED): bool {
            if ($v === $UNDEFINED || $v === null || $v === '') {
                $v = $p[0] ?? null;
            }
            return true;
        }))->order(50)->alias('default');

        // required (order 100, skip=true) — алиас requiredIf(true)
        static::requiredIf(true)->alias('required');

        // nullable (order 100, skip=true — если null/'' => пропускаем остальные правила без ошибки)
        (static::create(function(&$v) use ($UNDEFINED): bool {
            return !($v === null || $v === '' || $v === $UNDEFINED);
        }))->order(100)->skip(true)->alias('nullable');

        // present (order 100, skip=true — поле должно существовать, пустое допустимо)
        (static::create(function(&$v) use ($UNDEFINED): bool {
            return $v !== $UNDEFINED;
        }))->order(100)->skip(true)->handleError(fn($v) => 'This field must be present')->alias('present');

        // string
        (static::create(function(&$v): bool {
            return is_string($v);
        }))->order(500)->handleError(fn($v) => 'Must be a string')->alias('string');

        // int / integer (проверка + каст)
        (static::create(function(&$v): bool {
            if (is_int($v)) return true;
            if (is_string($v) && ctype_digit(ltrim($v, '-'))) {
                $v = (int)$v;
                return true;
            }
            return false;
        }))->order(500)->handleError(fn($v) => 'Must be an integer')->alias('int')->alias('integer');

        // float (проверка + каст)
        (static::create(function(&$v): bool {
            if (is_float($v)) return true;
            if (is_numeric($v)) {
                $v = (float)$v;
                return true;
            }
            return false;
        }))->order(500)->handleError(fn($v) => 'Must be a number')->alias('float');

        // bool (проверка + каст)
        (static::create(function(&$v): bool {
            if (is_bool($v)) return true;
            if (in_array($v, ['0', '1', 0, 1, 'true', 'false', 'checked'], true)) {
                $v = is_string($v) ? filter_var($v, FILTER_VALIDATE_BOOLEAN) : (bool)$v;
                return true;
            }
            return false;
        }))->order(500)->handleError(fn($v) => 'Must be a boolean')->alias('bool');

        // email
        (static::create(function(&$v): bool {
            return is_string($v) && filter_var($v, FILTER_VALIDATE_EMAIL) !== false;
        }))->order(500)->handleError(fn($v) => 'Invalid email address')->alias('email');

        // url
        (static::create(function(&$v): bool {
            return is_string($v) && filter_var($v, FILTER_VALIDATE_URL) !== false;
        }))->order(500)->handleError(fn($v) => 'Invalid URL')->alias('url');

        // array
        (static::create(function(&$v): bool {
            return is_array($v);
        }))->order(500)->handleError(fn($v) => 'Must be an array')->alias('array');

        // max:n
        (static::create(function(&$v, array $p): bool {
            $l = $p[0] ?? null;
            if ($l === null) return true;
            $l = (float)$l;
            if (is_string($v)) return mb_strlen($v) <= $l;
            if (is_array($v))  return count($v) <= $l;
            return is_numeric($v) && (float)$v <= $l;
        }))->order(700)->handleError(fn($v) => 'Value is too large')->alias('max');

        // min:n
        (static::create(function(&$v, array $p): bool {
            $l = $p[0] ?? null;
            if ($l === null) return true;
            $l = (float)$l;
            if (is_string($v)) return mb_strlen($v) >= $l;
            if (is_array($v))  return count($v) >= $l;
            return is_numeric($v) && (float)$v >= $l;
        }))->order(700)->handleError(fn($v) => 'Value is too small')->alias('min');

        // in:a,b,c
        (static::create(function(&$v, array $p): bool {
            return in_array($v, $p, false);
        }))->order(700)->handleError(fn($v) => 'Not a valid option')->alias('in');

        // notIn / not_in
        (static::create(function(&$v, array $p): bool {
            return !in_array($v, $p, false);
        }))->order(700)->handleError(fn($v) => 'Value is not allowed')->alias('notIn')->alias('not_in');

        // regex:pattern
        (static::create(function(&$v, array $p): bool {
            $pattern = $p[0] ?? '';
            if (!is_string($v)) return false;
            return @preg_match($pattern, $v) === 1;
        }))->order(700)->handleError(fn($v) => 'Invalid format')->alias('regex');

        // digits:n
        (static::create(function(&$v, array $p): bool {
            $n = (int)($p[0] ?? 0);
            return is_string($v) && ctype_digit($v) && strlen($v) === $n;
        }))->order(700)->handleError(fn($v) => 'Must be digits only')->alias('digits');

        // between:min,max
        (static::create(function(&$v, array $p): bool {
            $min = (float)($p[0] ?? 0);
            $max = (float)($p[1] ?? 0);
            if (is_string($v)) { $s = mb_strlen($v); return $s >= $min && $s <= $max; }
            if (is_array($v))  { $c = count($v);     return $c >= $min && $c <= $max; }
            return is_numeric($v) && (float)$v >= $min && (float)$v <= $max;
        }))->order(700)->handleError(fn($v) => 'Value is out of range')->alias('between');

        // date
        (static::create(function(&$v): bool {
            return is_string($v) && strtotime($v) !== false;
        }))->order(500)->handleError(fn($v) => 'Invalid date')->alias('date');

        // date_format:format
        (static::create(function(&$v, array $p): bool {
            $fmt = $p[0] ?? '';
            if (!is_string($v)) return false;
            $d = \DateTime::createFromFormat($fmt, $v);
            return $d !== false && $d->format($fmt) === $v;
        }))->order(500)->handleError(fn($v) => 'Invalid date format')->alias('date_format');

        // json
        (static::create(function(&$v): bool {
            if (!is_string($v)) return false;
            @json_decode($v);
            return json_last_error() === JSON_ERROR_NONE;
        }))->order(500)->handleError(fn($v) => 'Invalid JSON')->alias('json');

        // uuid
        (static::create(function(&$v): bool {
            return is_string($v)
                && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v) === 1;
        }))->order(500)->handleError(fn($v) => 'Invalid UUID')->alias('uuid');

        // starts_with:prefix
        (static::create(function(&$v, array $p): bool {
            if (!is_string($v)) return false;
            foreach ($p as $prefix) {
                if (strncmp($v, $prefix, strlen($prefix)) === 0) return true;
            }
            return false;
        }))->order(700)->handleError(fn($v) => 'Invalid prefix')->alias('starts_with');

        // ends_with:suffix
        (static::create(function(&$v, array $p): bool {
            if (!is_string($v)) return false;
            foreach ($p as $suffix) {
                $len = strlen($suffix);
                if ($len === 0 || substr($v, -$len) === $suffix) return true;
            }
            return false;
        }))->order(700)->handleError(fn($v) => 'Invalid suffix')->alias('ends_with');

        // contains:substring
        (static::create(function(&$v, array $p): bool {
            if (!is_string($v)) return false;
            foreach ($p as $sub) {
                if (strpos($v, $sub) !== false) return true;
            }
            return false;
        }))->order(700)->handleError(fn($v) => 'Must contain')->alias('contains');

        // trim (order -1, трансформер)
        (static::create(function(&$v): bool {
            if (is_string($v)) $v = trim($v);
            return true;
        }))->order(-1)->alias('trim');

        // uppercase (order 1000, трансформер)
        (static::create(function(&$v): bool {
            if (is_string($v)) $v = mb_strtoupper($v);
            return true;
        }))->order(1000)->alias('uppercase');

        // lowercase (order 1000, трансформер)
        (static::create(function(&$v): bool {
            if (is_string($v)) $v = mb_strtolower($v);
            return true;
        }))->order(1000)->alias('lowercase');

        // accepted
        (static::create(function(&$v): bool {
            return in_array($v, [true, 1, '1', 'yes', 'on', 'true'], true);
        }))->order(500)->handleError(fn($v) => 'Must be accepted')->alias('accepted');

        // declined
        (static::create(function(&$v): bool {
            return in_array($v, [false, 0, '0', 'no', 'off', 'false'], true);
        }))->order(500)->handleError(fn($v) => 'Must be declined')->alias('declined');

        // file
        (static::create(function(&$v): bool {
            return is_array($v)
                && isset($v['tmp_name'], $v['error'], $v['size'], $v['name'])
                && $v['error'] === UPLOAD_ERR_OK
                && is_uploaded_file($v['tmp_name']);
        }))->order(500)->handleError(fn($v) => 'Invalid file upload')->alias('file');

        // mimes:image/jpeg,image/png
        (static::create(function(&$v, array $p): bool {
            if (!is_array($v) || !isset($v['tmp_name']) || $v['error'] !== UPLOAD_ERR_OK) return false;
            if (!is_uploaded_file($v['tmp_name'])) return false;
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($v['tmp_name']);
            return in_array($mime, $p, true);
        }))->order(600)->handleError(fn($v) => 'Invalid MIME type')->alias('mimes');

        // extension:jpg,png
        (static::create(function(&$v, array $p): bool {
            if (!is_array($v) || !isset($v['name'])) return false;
            $ext = strtolower(pathinfo($v['name'], PATHINFO_EXTENSION));
            return in_array($ext, array_map('strtolower', $p), true);
        }))->order(600)->handleError(fn($v) => 'Invalid file extension')->alias('extension');

        // filesize:2048 (килобайты)
        (static::create(function(&$v, array $p): bool {
            if (!is_array($v) || !isset($v['size'])) return false;
            $maxKb = (float)($p[0] ?? 0);
            return $v['size'] <= $maxKb * 1024;
        }))->order(600)->handleError(fn($v) => 'File exceeds size limit')->alias('filesize');
    }
}
