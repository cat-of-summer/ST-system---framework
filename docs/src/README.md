<!-- DOCGEN:START -->
# src

## Папки

- [API](API/)
- [Cache](Cache/)
- [CensorText](CensorText/)
- [Console](Console/)
- [HTTP](HTTP/)
- [Schemas](Schemas/)
- [Storage](Storage/)
- [Traits](Traits/)

## Файлы

- [Access.php](Access.php.md)
- [Assets.php](Assets.php.md)
- [Config.php](Config.php.md)
- [Debug.php](Debug.php.md)
- [Lang.php](Lang.php.md)
- [Loader.php](Loader.php.md)
- [Main.php](Main.php.md)
- [Menu.php](Menu.php.md)
- [Rule.php](Rule.php.md)
- [View.php](View.php.md)

<!-- DOCGEN:END -->

## Обзор фреймворка

`ST_system` — PHP-фреймворк общего назначения (пространство имён `ST_system`, сообщения об ошибках — на русском языке). Файлы в корне `src/` — это базовые, сквозные классы платформы: конфигурация, утилиты, кеширование, шаблонизация, ассеты, контроль доступа, отладка, валидация данных и загрузка классов. Более специфичные по назначению модули вынесены в поддиректории (интеграции с внешними API, драйверы кеша, HTTP-стек, работа с файлами/ресурсами и т.д. — см. ниже).

Общие сквозные механизмы:
- `Traits\HasConfig` — даёт классам `static::config($key = '')` (объединяет файловый конфиг через `Config::config(static::class)` с `static::getDefaultConfig()`) и `static::applyConfig()`, применяющий схему через `Rule::object()` с синтаксисом ссылок на конфиг вида `'@key'`.
- `Traits\HasEvents` — `on()`/`fire()`/`trigger()` для простой событийной модели.
- `Traits\HasInstance` — синглтон-паттерн `getInstance()`.
- `Rule` — движок валидации/коэрсии, используемый практически всеми остальными классами (в том числе для резолва конфигурации через `HasConfig::applyConfig()`).

## Классы корня `src/`

| Класс | Назначение |
|---|---|
| **Access** | Модуль контроля доступа: credential-гейты для защиты действий/HTML-блоков паролем или HTTP Basic Auth, плюс конфигурируемый IP-фаервол со скользящим рейт-лимитом и баном, верификацией поисковых ботов через reverse-DNS, allow/deny-правилами, гео-IP фильтрацией и поддержкой CORS. |
| **Assets** | Управление CSS/JS/шрифтами/SVG на странице: регистрация, combine+minify, буферизация вывода, генератор PWA-манифеста/favicon; тесно интегрирован с `View`. |
| **Config** | Глобальный статический загрузчик конфигурации: чтение `.env` (`init()`/`env()`), кешируемая обёртка над `ini_get()` (`ini()`), файловая конфигурация из папки или одного файла (`config()`/`setConfig()`/`fillConfig()`), а также отдельное in-memory «immutable»-хранилище конфигурации по классам (`getImmutableConfig()`/`setImmutableConfig()`/`fillImmutableConfig()`), которым пользуется `Traits\HasConfig` для резолва `static::config()` каждого класса. |
| **Debug** | Dev-утилита отладки: backtrace, таймеры/бенчмарк, `php -l` линтер, установщик глобального error/exception/shutdown handler'а с подключаемыми dump-методами (файл/консоль/email/throw/echo). |
| **Loader** | Обёртка над `require`/`include` с glob-резолвом через `Storage\File` и двумя уровнями ленивой автозагрузки классов (`registerClass` точечная, `registerDir` директорийная PSR-4-подобная); `include`/`include_once` отказоустойчивы (линтинг+глотание исключений), `require`/`require_once` строгие как нативный PHP. |
| **Main** | Набор статических util-хелперов без состояния: `timestamp()`, плюрализация (`pluralIndex()`/`pluralForm()`), `basename()`/`glue()`, преобразования регистра (`studlyCase`/`camelCase`/`snakeCase`/`kebabCase`), глубокое слияние массивов/конфигов (`merge()`), детерминированное хеширование значений (`hash()`), генерация UUID (`uuid()`), dot-notation доступ к массивам (`dotGet`/`dotSet`/`dotFlatten`/`arrayIsList`), форматирование байт (`formatBytes()`) и нормализация путей (`preparePath()`). Используется практически всеми остальными классами фреймворка. |
| **Menu** | Рендерит вложенные HTML-меню из массива-описания структуры (или PHP-файла с таким массивом): рекурсивный обход узлов `FIELDS`/`ITEMS`/`PROPERTIES` с настраиваемыми по глубине шаблонами открытия/закрытия списка и рендера пункта (строки или callback-и). |
| **Rule** | Универсальный движок валидации/коэрсии/трансформации данных (DSL-спеки вида `'string\|email\|max:100'`, вложенные схемы, именованные алиасы) — используется практически всеми классами фреймворка. |
| **View** | Шаблонизатор с композицией parent/child (слоты) и многоуровневым кешированием (per-node skeleton + полностью составленный root-кеш); тесно интегрирован с `Assets`. |

## Поддиректории

- **API/** — интеграции со сторонними API.
- **Cache/** — драйверы кеширования.
- **CensorText/** — фильтр нецензурной лексики.
- **Console/** — CLI-команды.
- **HTTP/** — запрос/ответ/роутинг/HTTP-клиент.
- **Schemas/** — структурированные данные schema.org/Яндекс.
- **Storage/** — файлы/ресурсы/MIME.
- **Traits/** — общие примеси `HasConfig`/`HasEvents`/`HasInstance`/`HasAttributes`.

Подробное описание каждой поддиректории — в её собственном `README.md`.
