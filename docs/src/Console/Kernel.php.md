<!-- DOCGEN:START -->
# Kernel.php
<!-- DOCGEN:END -->

`final class Kernel` (`ST_system\Console\Kernel`) — ядро обнаружения, регистрации и диспатча CLI-команд ([`Command`](Command.php.md)). Использует трейты `HasConfig` (конфигурация каталога и неймспейса поиска команд по умолчанию) и [`HasInstance`](../Traits/HasInstance.php.md) (ленивая одноразовая инициализация). Публичный API статический, но внутри однократно создаётся единственный экземпляр через `HasInstance::getInstance()`: приватный конструктор при первом обращении автоматически сканирует конфигурный каталог (`registerDir()`), поэтому логика инициализации живёт в одном месте, а не дублируется по методам. Реестр команд хранится в статическом свойстве `self::$commands`.

## Конфиг по умолчанию

```php
[
    'default' => [
        'dir'       => '~/Console/Commands',
        'namespace' => 'Console\Commands',
    ],
]
```

`dir` разрешается через `Main::preparePath()` — префикс `~` заменяется на `DOCUMENT_ROOT`/`COMPOSER_ROOT` из окружения, то есть по умолчанию команды ищутся в `<корень проекта>/Console/Commands` под неймспейсом `Console\Commands`.

## registerDir(string $dir, string $namespace): void

Рекурсивно сканирует каталог `$dir` на `*.php`-файлы (через `File::find()`). Для каждого файла восстанавливает полное имя класса как `$namespace . '\\' . <относительный путь без .php, / заменены на \\>`, и если такой класс существует, является подклассом `Command` и имеет непустой `$signature` — регистрирует его через `register()`. Файлы, не подошедшие под эти условия (класс не найден, не подкласс `Command`, либо `$signature === ''`), молча пропускаются.

## register(string $name, string $class): void

Кладёт пару `сигнатура => полное имя класса команды` в статический реестр `self::$commands`. Обычно вызывается косвенно из `registerDir()`, но доступен и для ручной регистрации одной команды без сканирования каталога:

```php
Kernel::register('user:create', \Console\Commands\UserCreateCommand::class);
// self::$commands['user:create'] === 'Console\\Commands\\UserCreateCommand'
```

## handleCLI(array $argv): void

Точка входа для запуска команд из CLI-скрипта:

```php
#!/usr/bin/env php
<?php
require __DIR__ . '/vendor/autoload.php';
\ST_system\Console\Kernel::handleCLI($argv);
```

1. Проверяет, что скрипт выполняется под CLI SAPI — иначе бросает `\RuntimeException`.
2. Вызывает `self::getInstance()` — единая точка инициализации: при первом обращении в рамках процесса конструктор автоматически регистрирует команды из конфигурного каталога по умолчанию через `registerDir()`. Повторные вызовы `handleCLI()`/`getAvailableCommands()` переиспользуют уже созданный экземпляр и не пересканируют каталог заново.
3. Имя команды берётся из `$argv[1]`:
   - если имя **не передано** — печатается только список доступных команд (`Available: ...`, `implode(', ', array_keys(self::getAvailableCommands()))`) без строки `Unknown command`, процесс завершается кодом `0` (это справочный вывод, а не ошибка);
   - если имя передано, но **не зарегистрировано** — печатается `Unknown command: <name>` и тот же список доступных команд, процесс завершается кодом `1`.
4. Остаток аргументов (`array_slice($argv, 2)`) разбирается на позиционные значения и опции:
   - `--name=value` → `$rawOptions['name'] = 'value'`;
   - `--flag` (без `=`) → `$rawOptions['flag'] = true`;
   - `-x` / `-xvalue` (короткая опция, `/^-([a-zA-Z])(.*)$/`) → `$rawOptions['x'] = true` или значение после буквы;
   - всё остальное считается позиционным аргументом.
5. Создаёт экземпляр найденного класса команды (`new self::$commands[$name]($positional, $rawOptions)`), вызывает `handle()` и завершает процесс через `exit(0)`. `handleCLI()` — **терминальная операция**: код после её вызова не выполняется (по аналогии с [`Route::handleRequest()`](../HTTP/Route.php.md), который завершается через `Response::send()`).

```
$ php console.php user:create Ivan ivan@example.com --role=admin -f
```

`user:create` — имя команды (сигнатура), `Ivan`/`ivan@example.com` — позиционные аргументы, `--role=admin` и `-f` — опции; всё это передаётся в конструктор `UserCreateCommand`, чья разобранная сигнатура сопоставляет их с `name`/`email`/`role`/`force` (см. [Command.php.md](Command.php.md)).

## getAvailableCommands(): array

Гарантирует инициализацию (`self::getInstance()`) и возвращает весь реестр команд в виде `сигнатура => полное имя класса команды`. Удобно для интроспекции доступных команд — список сигнатур и классов, где они определены:

```php
foreach (Kernel::getAvailableCommands() as $signature => $class) {
    echo "{$signature} => {$class}" . PHP_EOL;
}
```
