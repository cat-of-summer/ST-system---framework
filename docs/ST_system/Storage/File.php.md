# File

> Документация сгенерирована AI-агентом на основе исходного кода.

## 1. Концепция

Единый объект для работы с локальными файлами и удалёнными URL. Локальный путь `~` раскрывается через `Main::preparePath`. URL-ресурсы скачиваются, кэшируются и возвращаются как локальный `File`. MIME определяется автоматически по расширению или `finfo`; на основе MIME выбирается соответствующий сервис (`CssMime`, `JavaScriptMime`, `SvgMime`, `ImageMime`, `FontMime`…).

```php
// Локальный файл
$file = File::make('~/assets/app.css');
echo $file->getMime();     // 'text/css'
echo $file->getSize('kb'); // размер в КБ

// Минификация (для CSS, JS)
$min = $file->minify();
echo $min->getPathname();  // ~/cache/app.min.css

// toHTML() — тег <link>/<script>
echo $file->toHTML(['media' => 'screen']);

// Загрузка удалённого ресурса с кэшированием
$remote = File::make('https://cdn.example.com/lib.js');
$local  = $remote->fetch();  // скачивает в ~/cache/

// Поиск файлов
$files = File::find('~/assets/*.js');        // glob
$files = File::find('~/assets/', ['extension' => 'css']); // по расширению

// Очистка кэша
$file->purgeCache();
File::purgeAllCache();
```

## 2. Публичные методы

### `static make(string $path): static`
Создаёт объект файла. `~` в начале — корень приложения.

### `static find(string|array $input, array $config = []): array`
Поиск файлов. `$input` — путь, директория или glob-паттерн. Конфиг: `extension`, `max_files` (50), `sym_links`, `recursive` (true), `hidden_files`.

### `fetch(bool $force = false): static`
Для URI: скачивает файл в кэш (повторяет до `fetch.max_attempts` раз). Для локального — возвращает `$this`.

### `getMeta(bool $force = false): array`
Для URI: делает HEAD-запрос, кэширует заголовки. Возвращает массив с `http_code`, `content-type`, `content-length`, `expires_in` и т.д.

### `getMime(): string`
MIME-тип. Сначала из таблицы расширений, потом из заголовков URI или `finfo`.

### `getSize(string $unit = 'b'): int|float`
Размер в байтах/KB/MB/GB.

### `isUri(): bool`
`true` если файл — URL.

### `getOriginal(bool $force = false): static|null`
Исходный объект (`$force = true` — корень цепочки fetch).

### `purgeCache(): static`
Удаляет кэш-файл текущего объекта (и его оригинала).

### `static purgeAllCache(): void`
Удаляет всю директорию кэша.

### `getServiceName(): string`
Имя класса MIME-сервиса (`CssMime`, `SvgMime`, `Default` и т.п.).

### Делегированные методы (через `__call`)
Все методы `SplFileInfo`: `getPathname()`, `getFilename()`, `getExtension()`, `getBasename()`, `getDirectory()` и т.д. А также методы MIME-сервиса: `toHTML()`, `minify()`, `toSprite()`, `convert()` и т.п..php
