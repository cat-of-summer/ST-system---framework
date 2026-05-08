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

### `static hasConfigInit(): void`

Регистрирует специальное правило `\defaultConfig` в реестре `Rule` (один раз, идемпотентно). После вызова этого метода правило можно применять в строковых спецификациях:

```php
// В схеме объекта:
$schema = [
    'timeout' => 'int|\\defaultConfig:MyService,timeout',
    'retry'   => '\\defaultConfig:MyService,retry',
];

Rule::object($schema)->apply($data);
// Если поле отсутствует — подставится значение из MyService::config('timeout')
```

Обычно вызывается в `boot()` или при инициализации приложения.

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
