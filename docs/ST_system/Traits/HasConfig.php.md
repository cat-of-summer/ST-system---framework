# HasConfig.php

> Для AI-агентов: этот документ описывает трейт `HasConfig`.  
> Трейт живёт в пространстве имён `ST_system\Traits`, файл `Traits/HasConfig.php`.

---

## 1. Концепция

`HasConfig` — **трейт управления конфигурацией класса** поверх глобального `Config`-хранилища.

Ключевые идеи:

1. **Класс-пространство имён в Config.** Каждый класс, использующий트ейт, получает собственное «пространство» в иммутабельном разделе `Config`. Ключом является `static::class` (полное имя класса).

2. **Дефолтная конфигурация.** Класс переопределяет `getDefaultConfig(): array` — метод, который возвращает значения по умолчанию. Они применяются один раз при первом вызове `config()`.

3. **Методы `setConfig` / `config`.** `setConfig()` позволяет переопределить конкретные ключи перед инициализацией (до первого использования). `config()` возвращает значение по ключу (или весь массив если ключ не указан).

4. **`defaultConfig` Rule.** Метод `hasConfigInit()` регистрирует специальное правило валидации `\defaultConfig` в реестре `Rule`, которое позволяет использовать дефолтную конфигурацию в схемах:
   ```php
   Rule::create('required|\defaultConfig:ClassName,key')
   ```

**Когда использовать:**
- В классах-сервисах с настраиваемым поведением (`Debug`, `Access`, `IntegrationDriver` и т.д.).
- Когда нужно, чтобы класс имел дефолтные параметры, но позволял их переопределять однократно при инициализации приложения.

```php
class MyService {
    use \ST_system\Traits\HasConfig;

    protected static function getDefaultConfig(): array {
        return [
            'timeout' => 30,
            'retry'   => 3,
        ];
    }
}

// Переопределение до первого использования:
MyService::setConfig(['timeout' => 60]);

// Чтение значений:
MyService::config('timeout'); // 60
MyService::config('retry');   // 3 (из defaults)
MyService::config();          // ['timeout' => 60, 'retry' => 3]
```

---

## 2. Публичные методы

### `static setConfig(array $config = []): void`

Записывает переданные ключи в иммутабельное хранилище конфигурации класса через `Config::setImmutableConfig`.

> **Внимание:** Если `config()` уже был вызван (инициализация прошла), повторный вызов `setConfig()` **перезапишет** значения, но новые значения не будут считаться «дефолтными». Рекомендуется вызывать `setConfig()` один раз при старте приложения, до обращения к классу.

| Параметр | Тип | Описание |
|----------|-----|----------|
| `$config` | `array` | Ассоциативный массив `['key' => value]` |

```php
MyService::setConfig([
    'timeout' => 60,
    'retry'   => 5,
]);
```

---

### `final static config(string $key = ''): mixed`

Возвращает значение конфигурации по ключу, или весь массив конфигурации.

При первом вызове инициализирует дефолтные значения из `getDefaultConfig()` через `Config::fillImmutableConfig`. Последующие вызовы используют кэш.

| Параметр | Тип | Описание |
|----------|-----|----------|
| `$key` | `string` | Dot-notation путь к значению. При пустой строке возвращается весь массив. |

**Возвращает:** значение (`mixed`) или весь массив конфигурации класса.

```php
MyService::config('timeout');         // 60
MyService::config('nested.key');      // dot-notation поддерживается
MyService::config();                  // ['timeout' => 60, 'retry' => 5]
```

---

### `static applyConfig(array &$config, array $schema = []): void`

Применяет конфигурацию к массиву `$config`. Работает в двух режимах в зависимости от наличия `$schema`.

Внутри регистрирует правило `\defaultConfig` в глобальном реестре `Rule` (один раз, идемпотентно) и выполняет всё в контексте `Rule::scope(static::class, ...)`.

---

**Режим без схемы** (`applyConfig($config)`):

Заполняет все top-level ключи `$config` из `static::config()`: если ключ отсутствует, равен `null` или `''` — подставляется значение из `getDefaultConfig()`.

```php
class MyDriver {
    use \ST_system\Traits\HasConfig;

    protected static function getDefaultConfig(): array {
        return ['timeout' => 30, 'retries' => 3];
    }

    public function __construct(array $config = []) {
        static::applyConfig($config);
        // $config теперь содержит дефолтные значения для отсутствующих ключей
        $this->timeout = $config['timeout'];
    }
}
```

---

**Режим со схемой** (`applyConfig($config, $schema)`):

Выполняет `Rule::object($schema)->throwable()->apply($config)` — валидирует и трансформирует `$config` по переданной схеме, отбрасывая ключи вне схемы. При ошибке — бросает исключение.

В схеме поддерживается **`@path`-нотация**: символ `@` в строковых спецификациях заменяется на `defaultConfig:` перед передачей в `Rule::object`. Это позволяет ссылаться на значения из `getDefaultConfig()` прямо в pipe-строках:

| Запись в схеме | Что получает Rule |
|---|---|
| `'@credentials.name'` | `'defaultConfig:credentials.name'` |
| `'nullable\|string\|@accessMethod'` | `'nullable\|string\|defaultConfig:accessMethod'` |
| `['array\|@CORS.methods', Rule::forEach(...)]` | `['array\|defaultConfig:CORS.methods', Rule::forEach(...)]` |

Rule-объекты в схеме проходят без изменений. Нотацию `@path` можно смешивать с любыми другими правилами в pipe-строке.

```php
class MyService {
    use \ST_system\Traits\HasConfig;

    protected static function getDefaultConfig(): array {
        return [
            'credentials' => ['name' => 'pass', 'value' => date('dm')],
            'method'      => 'GET',
        ];
    }

    public static function handle(array $config = []) {
        static::applyConfig($config, [
            'name'   => 'string|html_decode|@credentials.name',
            'value'  => 'string|html_decode|@credentials.value',
            'method' => 'nullable|string|@method',
            'onFail' => ['callable', Rule::default(fn() => throw new \Exception())],
        ]);
        // $config содержит только ключи из схемы, заполненные дефолтами где нужно
    }
}
```

---

## 3. Защищённые методы (для переопределения)

### `protected static getDefaultConfig(): array`

Определяет значения по умолчанию. Переопределяется в каждом конкретном классе.

```php
protected static function getDefaultConfig(): array {
    return [
        'key1' => 'value1',
        'key2' => 42,
    ];
}
```
