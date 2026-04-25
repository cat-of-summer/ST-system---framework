<?php

namespace ST_system;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  Schema — типизированный реестр сущностей (entities) с валидацией и рендерингом
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Класс предоставляет декларативный способ описывать структуры данных (entity),
 * автоматически их валидировать, а затем рендерить в строку (print) или
 * экспортировать в массив (toArray).
 *
 * ───────────────────────────────────────────────────────────────────────────────
 *  ОСНОВНЫЕ КОНЦЕПЦИИ
 * ───────────────────────────────────────────────────────────────────────────────
 *
 * Entity   — именованный тип данных. Регистрируется один раз через Schema::entity().
 * Instance — заполненный экземпляр entity, создаётся через Schema::create()->fill().
 * Namespace — иерархический префикс для полного имени entity (через точку).
 *
 * ───────────────────────────────────────────────────────────────────────────────
 *  РЕГИСТРАЦИЯ ENTITY
 * ───────────────────────────────────────────────────────────────────────────────
 *
 *   Schema::entity(string $name, array $options = []): self
 *
 *   $options:
 *     'fields'  => array         — схема полей: key => spec. По умолчанию [].
 *     'print'   => callable|null — fn(Schema $s, array $params): string
 *                                  Если не задана, print() вызовет
 *                                  json_encode($s->toArray($params), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).
 *     'toArray' => callable|null — fn(Schema $s, array $params): array
 *                                  Если не задана, toArray() рекурсивно итерирует
 *                                  $this->data; вложенные Schema-инстансы получают
 *                                  '@type' => entityDef->name.
 *
 *   Пример — entity с полями и XML-рендером:
 *
 *     Schema::entity('doctor', [
 *         'fields' => [
 *             'id'   => 'required|string',
 *             'name' => 'required|string',
 *         ],
 *         'print' => function (Schema $s, array $params): string {
 *             return '<doctor id="' . $s->field('id') . '">'
 *                  . '<name>' . $s->field('name') . '</name>'
 *                  . '</doctor>';
 *         },
 *     ]);
 *
 *   Пример — sub-entity с кастомным toArray (для JSON-LD):
 *
 *     Schema::entity('postal-address', [
 *         'fields' => [
 *             'locality' => 'required|string',
 *             'country'  => 'required|string',
 *         ],
 *         'toArray' => function (Schema $s, array $params): array {
 *             return [
 *                 '@type'           => 'PostalAddress',
 *                 'addressLocality' => $s->field('locality'),
 *                 'addressCountry'  => $s->field('country'),
 *             ];
 *         },
 *     ]);
 *
 *     $addr = Schema::create('postal-address')->fill(['locality' => 'Москва', 'country' => 'RU']);
 *     $arr = $addr->toArray();  // ['@type' => 'PostalAddress', 'addressLocality' => 'Москва', ...]
 *     $str = $addr->print();    // '{"@type":"PostalAddress","addressLocality":"Москва",...}'
 *
 *   Пример — entity только с полями (оба метода — дефолтные):
 *
 *     Schema::entity('User', [
 *         'fields' => [
 *             'name'  => 'required|string',
 *             'email' => 'required|email',
 *             'age'   => 'sometimes|int',
 *         ],
 *     ]);
 *
 * ───────────────────────────────────────────────────────────────────────────────
 *  NAMESPACE
 * ───────────────────────────────────────────────────────────────────────────────
 *
 * Namespace — иерархический префикс через точку.
 * Все entity, зарегистрированные внутри Closure, получат этот префикс.
 *
 *   Schema::namespace('schema', function (): void {
 *       Schema::entity('service', [...]);   // fullPath = 'schema.service'
 *       Schema::entity('offer',   [...]);   // fullPath = 'schema.offer'
 *   });
 *
 * Entity может открывать собственный namespace через ->namespace():
 *
 *   Schema::entity('service', [...])->namespace(function (): void {
 *       Schema::entity('offer', [...]);     // fullPath = 'service.offer'
 *   });
 *
 * Schema::namespace() идемпотентен: открывает существующий namespace или создаёт
 * пустышку. Schema::entity() бросает исключение при повторной регистрации.
 *
 * ───────────────────────────────────────────────────────────────────────────────
 *  СПЕЦИФИКАЦИИ ПОЛЕЙ (field specs)
 * ───────────────────────────────────────────────────────────────────────────────
 *
 * Каждое поле в 'fields' задаётся спецификацией одного из следующих типов:
 *
 *   Строка без '@':
 *     'required|string'          — Rule-pipe (стандартные правила валидации)
 *     'sometimes|int'            — необязательное числовое поле
 *
 *   Строка с '@':
 *     '@Doctor'                  — обязательная ссылка на entity 'Doctor'
 *     'sometimes|@Doctor'        — необязательная ссылка на entity
 *     '@Doctor|sometimes'        — то же самое (порядок не важен)
 *
 *   Rule-объект:
 *     Rule::create('required|string')  — явный Rule-объект передаётся as-is
 *
 *   Маркер arrayOf:
 *     Schema::arrayOf('@Doctor')              — обязательный массив entity 'Doctor'
 *     Schema::arrayOf('string')               — обязательный массив строк (Rule)
 *     [Schema::arrayOf('@Doctor'), 'sometimes'] — необязательный массив
 *
 *   Маркер oneOf:
 *     Schema::oneOf(['@Person', '@Organization'])  — один из нескольких вариантов
 *
 *   Closure (computed field):
 *     'full_name' => function (array $data): string {
 *         return $data['first_name'] . ' ' . $data['last_name'];
 *     }
 *     Computed-поля вычисляются ПОСЛЕ валидации и получают обработанные данные.
 *
 *   Массив с маркером:
 *     [Schema::arrayOf('@Doctor'), 'sometimes']   — маркер + Rule-модификаторы
 *     ['@Doctor', 'sometimes']                    — ref + модификаторы
 *
 * ───────────────────────────────────────────────────────────────────────────────
 *  ФАБРИКА И ЗАПОЛНЕНИЕ
 * ───────────────────────────────────────────────────────────────────────────────
 *
 *   $instance = Schema::create('Doctor')->fill([
 *       'id'   => '42',
 *       'name' => 'Иванов Иван',
 *   ]);
 *
 *   fill() возвращает заполненный экземпляр или бросает RuntimeException с ошибками.
 *   Повторный вызов fill() полностью перезаписывает данные.
 *
 *   append() мерджит новые данные поверх rawData последнего fill():
 *     $instance->append(['age' => 30]);  // fill(array_merge(rawData, ['age' => 30]))
 *
 *   fillParams — произвольный массив контекста, передаётся в print() и toArray():
 *     $instance = Schema::create('Card')->fill($data, ['lang' => 'ru']);
 *
 * ───────────────────────────────────────────────────────────────────────────────
 *  ХУКИ before / after
 * ───────────────────────────────────────────────────────────────────────────────
 *
 *   before(callable $fn): self
 *     Вызывается ДО валидации, получает массив $data по ссылке.
 *     Допустимо unset()-ить поля для фильтрации.
 *     Регистрируется на entity-определении — действует для всех экземпляров.
 *
 *   after(callable $fn): self
 *     Вызывается ПОСЛЕ fill(). Получает заполненный Schema-экземпляр.
 *     Для изменения данных используйте $s->set().
 *     Регистрируется на entity-определении — действует для всех экземпляров.
 *
 * ───────────────────────────────────────────────────────────────────────────────
 *  set() — прямая запись поля с dot-notation
 * ───────────────────────────────────────────────────────────────────────────────
 *
 *   set(string $path, mixed $value): self
 *     Напрямую задаёт значение поля (без валидации и хуков).
 *     Поддерживает dot-notation. Индексы массива — оба стиля (N и [N]):
 *       $s->set('name', 'Иван')
 *       $s->set('doctor.name', 'Иван')
 *       $s->set('items.0.name', 'Первый')
 *       $s->set('items.[0].name', 'Первый')   // эквивалентно
 *     Инвалидирует printCache на каждом уровне навигации.
 *
 * ───────────────────────────────────────────────────────────────────────────────
 *  ВЫВОД: print() и toArray()
 * ───────────────────────────────────────────────────────────────────────────────
 *
 *   print(array $params = []): string
 *     Возвращает строковое представление экземпляра.
 *     Если задана 'print'-функция entity — вызывает её с ($this, $params).
 *     Иначе: json_encode($this->toArray($params), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).
 *     $params = merge(fillParams, $params) — объединяется один раз на верхнем уровне.
 *     Результат кэшируется при пустом $params и пустом fillParams.
 *     Кэш инвалидируется при fill(), append(), set().
 *
 *   toArray(array $params = []): array
 *     Возвращает массив данных экземпляра.
 *     Если задана 'toArray'-функция entity — вызывает её с ($this, $params).
 *     Иначе: рекурсивно итерирует $this->data:
 *       - Schema-инстанс (без кастомного toArray) → array_merge(['@type' => name], $child->toArray($params))
 *       - Schema-инстанс (с кастомным toArray) → $child->toArray($params)
 *       - массив → рекурсия через deepExport
 *       - скаляр → as-is
 *     $params пробрасывается во все вложенные toArray()-вызовы.
 *
 * ───────────────────────────────────────────────────────────────────────────────
 *  ПОЛУЧЕНИЕ ПОЛЕЙ: field()
 * ───────────────────────────────────────────────────────────────────────────────
 *
 *   field(string $name = ''): mixed
 *     Поддерживает dot-notation. Индексы массива — оба стиля (N и [N]):
 *       $s->field()                → весь $this->data
 *       $s->field('name')          → $data['name']
 *       $s->field('doctor.name')   → $data['doctor']->field('name')
 *       $s->field('items.0')       → $data['items'][0]
 *       $s->field('items.[0]')     → то же самое
 *       $s->field('items.0.name')  → $data['items'][0]->field('name')
 *
 * ───────────────────────────────────────────────────────────────────────────────
 *  PARENT
 * ───────────────────────────────────────────────────────────────────────────────
 *
 *   parent(): ?Schema
 *     Возвращает родительский Schema-экземпляр, если данный создан как дочерний
 *     (entity-ref, arrayOf, oneOf). Для корневых экземпляров — null.
 *
 * ───────────────────────────────────────────────────────────────────────────────
 *  ПОЛНЫЙ ПРИМЕР
 * ───────────────────────────────────────────────────────────────────────────────
 *
 *   // Регистрация
 *   Schema::entity('Address', [
 *       'fields' => [
 *           'city'   => 'required|string',
 *           'street' => 'required|string',
 *       ],
 *       'toArray' => function (Schema $s, array $p): array {
 *           return ['@type' => 'PostalAddress', 'city' => $s->field('city')];
 *       },
 *   ]);
 *
 *   Schema::entity('Doctor', [
 *       'fields' => [
 *           'name'    => 'required|string',
 *           'address' => '@Address',
 *           'tags'    => [Schema::arrayOf('string'), 'sometimes'],
 *       ],
 *       'print' => function (Schema $s, array $p): string {
 *           return '<doctor><name>' . $s->field('name') . '</name></doctor>';
 *       },
 *   ]);
 *
 *   // Использование
 *   $doctor = Schema::create('Doctor')->fill([
 *       'name'    => 'Иванов Иван',
 *       'address' => ['city' => 'Москва', 'street' => 'Ленина, 1'],
 *       'tags'    => ['терапевт', 'педиатр'],
 *   ]);
 *
 *   echo $doctor->print();    // <doctor><name>Иванов Иван</name></doctor>
 *   $arr = $doctor->toArray();
 *   // [
 *   //   'name'    => 'Иванов Иван',
 *   //   'address' => ['@type' => 'PostalAddress', 'city' => 'Москва'],
 *   //   'tags'    => ['терапевт', 'педиатр'],
 *   // ]
 *
 *   $doctor->set('address.city', 'Санкт-Петербург');
 *   $doctor->set('tags.0', 'хирург');
 *   $doctor->set('tags.[1]', 'онколог');   // эквивалентно tags.1
 */
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

    private function namespace(\Closure $fn): self
    {
        self::$nsStack[] = $this->entityDef->fullPath;
        try {
            $fn();
        } finally {
            array_pop(self::$nsStack);
        }
        return $this;
    }

    public function __call(string $name, array $args)
    {
        if ($name === 'namespace') {
            return $this->namespace(...$args);
        }
        throw new \Error("Call to undefined method " . __CLASS__ . "::{$name}()");
    }

    public static function __callStatic(string $name, array $args)
    {
        if ($name === 'namespace') {
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

            return $instance->namespace($fn);
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

        foreach ($def->fields as $key => $spec) {
            if ($spec instanceof \Closure) {
                $computed[$key] = $spec;
                continue;
            }
            $ruleSchema[$key] = self::compileFieldSpec($spec, $ctx);
        }

        $result = $data;
        $errors = Rule::object($ruleSchema)->apply($result);

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
