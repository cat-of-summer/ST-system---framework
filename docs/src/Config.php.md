# Config.php

## 1. Концепция

`Config` — **статический фасад управления конфигурацией** приложения. Инстанцирование не используется. Всё хранится в `static $cache`.

Ключевые идеи:

1. **Два типа хранилища.** `env`-параметры (переменные окружения) и `config`-параметры (файлы PHP/JSON). Хранятся в разных под-ключах `$cache`.

2. **Lazy-загрузка конфиг-файлов.** Файл читается только тогда, когда запрашивается ключ. Если `config_path` — директория, разные файлы загружаются лениво по первому сегменту ключа.

3. **Иммутабельный раздел.** Через `getImmutableConfig` / `setImmutableConfig` разные классы (есп. с трейтом `HasConfig`) хранят свою пер-класс конфигурацию.

4. **`.env` через `vlucas/phpdotenv`.** Если библиотека установлена и `.env` найден — загружается автоматически при `init()`.

**Порядок запуска:**
```php
// 1. Один раз в bootstrap.php:
Config::init([
    'config_path' => __DIR__ . '/config', // директория с .php / .json файлами
]);

// 2. Дальше в любом месте:
Config::env('APP_ENV');           // переменная окружения
Config::config('app.name');       // из config/app.php → ключ 'name'
Config::config('db.connections'); // из config/db.php → ключ 'connections'
```

---

## 2. Публичные методы

### `static init(array $params = []): void`

Инициализирует `Config`. Можно вызвать **только один раз**. Повторный вызов бросает `LogicException`.

| Ключ | Тип | Описание |
|-----|-----|----------|
| `config_path` | `string` | Путь к конфиг-директории или файлу. При отсутствии — `Config::config()` не читает файлы. |
| `dotenv_path` | `string\|null` | Директория с `.env`. Вычисляется автоматически если не указан. |

**Бросает:** `LogicException` при повторном вызове.

```php
Config::init(['config_path' => __DIR__ . '/config']);
```

---

### `static reload(): void`

Очищает внутренний кэш `env` и `config` без повторной инициализации. Используется в тестах или при перечитывании конфигурации на лету.

---

### `static env(string $name, mixed $default = ''): string`

Читает переменную окружения. Порядок поиска: `$_ENV` → `$_SERVER` → `getenv()` → внутренние дефолты → `$default`.

| Параметр | Тип | Описание |
|----------|-----|----------|
| `$name` | `string` | Имя переменной |
| `$default` | `mixed` | Значение по умолчанию |

**Возвращает:** `string`.

```php
Config::env('APP_ENV', 'production'); // 'development' (если задано)
Config::env('COMPOSER_ROOT');          // авто-детекция корня composer.json
```

---

### `static config(string $key = '', mixed $default = null): mixed`

Читает параметр конфигурации. Поддерживает dot-notation для навигации по вложенным значениям. При пустой строке возвращает весь кэш конфигурации.

Если `config_path` — директория: первый сегмент `$key` используется как имя файла (`app` → `config/app.php`).

```php
Config::config('app.name');          // 'Моё приложение'
Config::config('db.connections.0'); // первое соединение
Config::config('missing', 'test');  // 'test' (нет ключа)
Config::config();                   // весь кэш []
```

---

### `static setConfig(string $key, mixed $value): void`

Записывает значение в кэш конфигурации через dot-notation.

```php
Config::setConfig('app.debug', true);
Config::config('app.debug'); // true
```

---

### `static getImmutableConfig(string $key, string $subKey = ''): mixed`

Читает значение из пер-класс наместранства. Используется трейтом `HasConfig`.

| Параметр | Описание |
|----------|----------|
| `$key` | Полное имя класса (`SomeClass::class`) |
| `$subKey` | Dot-путь внутри пространства класса |

---

### `static setImmutableConfig(string $key, string $subKey, mixed $value): void`

Записывает значение в пер-класс наместранства.

---

### `static fillConfig(string $key, mixed $value): void`

Заполняет конфиг-кэш значениями-дефолтами: обновляет только те ключи, которые ещё не установлены. Если `$value` — массив, он разплющивается через `dotFlatten`.

---

### `static fillImmutableConfig(string $key, string $subKey, mixed $value): void`

Аналог `fillConfig` для пер-класс хранилища.
