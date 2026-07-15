<!-- DOCGEN:START -->
# HasAttributes.php
<!-- DOCGEN:END -->

## Назначение

`HasAttributes` даёт классу Laravel-подобную систему "магических" атрибутов: свободное хранилище `$this->attributes`, доступ к которому идёт через `__get`/`__set`/`__isset`/`__unset`, плюс два способа переопределить, как именно вычисляется значение конкретного атрибута — явные accessor/mutator-методы (`getXAttribute()`/`setXAttribute()`) или декларативная карта `attributeMap()` с ленивым вычислением и опциональным кэшированием. Используется в `Cache\CacheDriver` (и во всех его драйверах-наследниках), а также в `Storage\Resource` и `Storage\File` — например именно отсюда берутся магические геттеры вида `$file->size`, `$file->mtime`, `$resource->extension`.

## Что добавляет подмешивающему классу

### Свойства

- `protected array $attributes = []` — "сырое" хранилище значений атрибутов (то, что не обслуживается ни `getXAttribute()`, ни `attributeMap()`, попадает и читается прямо отсюда).
- `protected array $attribute_cache = []` — кэш значений, вычисленных через `attributeMap()`, если для конкретного атрибута включено кэширование.
- `private ?array $attribute_map = null` — лениво заполняемый снимок `attributeMap()` (вычисляется один раз на экземпляр при первом обращении к `__get`/`__isset`).

### `protected function attributeMap(): array`

Точка расширения (по умолчанию `[]`). Возвращает ассоциативный массив `имя_атрибута => определение`, где определение — это:

- строка/`callable` — как "получить" значение;
- либо массив `[резолвер, флаг_кэширования]` — второй элемент (по умолчанию `false`) говорит, нужно ли класть результат в `attribute_cache`.

Резолвером может быть:
- имя метода того же объекта (строка) — тогда вызывается `$this->{$resolver}()` (либо, если метода нет, но есть `__call`, вызов всё равно пойдёт через магию `$this->{$resolver}()`, что покрывает случаи вроде `Resource`, где `__call` проксирует к mime-сервису или `\SplFileInfo`);
- произвольный `callable`, не являющийся строкой (замыкание, `[объект, метод]` и т.п.) — вызывается через `call_user_func($resolver)`;
- строка, которая не является ни методом объекта, ни допустимым именем при отсутствии `__call`, — трактуется как имя функции и вызывается напрямую (`$resolver()`).

Пример из `Storage\File.php` (дополняющий карту родителя `Resource`):

```php
protected function attributeMap(): array {
    return array_merge(parent::attributeMap(), [
        'real_path'   => ['getRealPath'],   // без кэша (по умолчанию false)
        'size'        => ['getSize'],
        'exists'      => ['exists'],
        'is_dir'      => ['isDir'],
    ]);
}
```

и из `Storage\Resource.php` — с явным включением кэша (второй элемент `true`):

```php
protected function attributeMap(): array {
    return [
        'relative_path' => ['getRelativePath', true],
        'pathname'      => ['getPathname', true],
        'extension'     => ['getExtension', true],
        'original'      => ['getOriginal', true],
    ];
}
```

Здесь `$resource->extension` при первом обращении вызовет `$this->getExtension()` и запомнит результат в `attribute_cache['extension']`; повторное чтение `$resource->extension` вернёт закэшированное значение без повторного вызова метода. `$file->size`, напротив, не кэшируется — каждое обращение к `$file->size` заново вызывает `$this->getSize()` (актуально, т.к. размер файла на диске может измениться между обращениями).

### `protected function purgeAttributes(): void`

Сбрасывает `attribute_cache` (весь целиком, `= []`). Не трогает `attributes` и `attribute_map`. Вызывается подмешивающими классами, когда нужно инвалидировать закэшированные вычисляемые значения — например `CacheDriver::purge()` и `Resource::purge()`/`File::purge()` вызывают `purgeAttributes()` при сбросе состояния (файл на диске мог измениться, кэш файла — тоже).

### `public function __get(string $name)`

Порядок разрешения при чтении `$obj->name`:

1. **Explicit accessor**: если существует метод `get{StudlyName}Attribute()` (имя атрибута переводится в StudlyCase через `Main::studlyCase()`), вызывается он и его результат возвращается сразу — это самый высокий приоритет, `attributeMap()` и `attributes[]` в этом случае не смотрятся вовсе. Пример (`Storage\Resource.php`):
   ```php
   final protected function getIsUriAttribute(): bool {
       return (bool)($this->attributes['is_uri'] ?? false);
   }
   ```
   т.е. `$resource->is_uri` всегда идёт через этот метод, а не напрямую в `attributes['is_uri']` (хотя внутри метод сам же читает `attributes`).
2. Если explicit-геттера нет, лениво инициализируется `$this->attribute_map` (вызовом `attributeMap()`, один раз за время жизни объекта).
3. Если имени нет в `attribute_map` — значение берётся напрямую из `$this->attributes[$name] ?? null` (обычное "сырое" хранилище, для атрибутов вроде `attributes['file']`, `attributes['ttl']`, `attributes['raw_key']`, которые просто пишутся напрямую в конструкторах и не нуждаются в вычислении).
4. Если имя есть в `attribute_map` и уже присутствует в `attribute_cache` (`array_key_exists`, то есть даже кэшированный `null` учитывается) — возвращается закэшированное значение без повторного вычисления.
5. Иначе определение из `attribute_map[$name]` нормализуется в `[$resolver, $cache]` (см. выше правила вызова резолвера), значение вычисляется, и если `$cache === true`, кладётся в `attribute_cache[$name]` на будущее.

### `public function __set(string $name, $value): void`

- Сначала безусловно инвалидирует `attribute_cache[$name]` (`unset`) — чтобы после записи не отдать устаревшее кэшированное значение.
- Если существует метод `set{StudlyName}Attribute($value)` — вызывается он (мутатор сам решает, что делать со значением; например `Resource::setIsUriAttribute()` объявлен пустым — намеренно игнорирует запись, т.е. `is_uri` доступен только на чтение, попытка `$resource->is_uri = true` молча ничего не сделает).
- Иначе значение просто кладётся в `$this->attributes[$name] = $value` — сырое хранилище.

Обратите внимание: `attributeMap()` в `__set` **не участвует вовсе** — карта атрибутов описывает только чтение (вычисляемые/производные геттеры); запись всегда идёт либо через явный `setXAttribute()`, либо напрямую в `attributes`.

### `public function __isset(string $name): bool`

`true`, если выполняется любое из:
- существует метод `get{StudlyName}Attribute()`;
- имя присутствует в (лениво инициализированной) `attribute_map`;
- имя присутствует (как ключ, независимо от значения — через `isset`, то есть `null`-значения в `attributes` дадут `false`) в `$this->attributes`.

### `public function __unset(string $name): void`

Удаляет одновременно `attributes[$name]` и `attribute_cache[$name]`. Не откатывает `attribute_map` и не вызывает никаких `unsetXAttribute()`-хуков (таких хуков трейт не предусматривает).

## Итоговая модель приоритетов

Для чтения `$obj->foo`: `getFooAttribute()` (метод) → `attributeMap()['foo']` (с кэшем, если включён) → `attributes['foo']` → `null`.
Для записи `$obj->foo = $v`: `setFooAttribute($v)` (метод, если есть) → иначе `attributes['foo'] = $v`; в обоих случаях предварительно сбрасывается кэш `attribute_cache['foo']`.

## Нюансы

- `attribute_map` кэшируется на уровне экземпляра (`??=`) — значит `attributeMap()` вызывается максимум один раз за время жизни объекта, даже если сам метод строит массив заново при каждом вызове (например через `array_merge(parent::attributeMap(), [...])`).
- Кэш в `attribute_cache` — только для атрибутов из `attributeMap()`, и только если явно указан `true` вторым элементом определения; атрибуты, вычисляемые через `getXAttribute()`-методы, кэшированием трейта не покрываются вовсе (кэшировать их или нет — должен решать сам метод, как это делает `Resource::mime()`/`$mime_data`, которые не являются частью `HasAttributes`, а собственным кэшем класса).
- `purgeAttributes()` сбрасывает только `attribute_cache`; чтобы очистить и `attributes`, класс должен обнулять их отдельно (или вызывать `__unset` по каждому ключу).
