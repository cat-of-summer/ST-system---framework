<!-- DOCGEN:START -->
# Command.php
<!-- DOCGEN:END -->

`abstract class Command` (`ST_system\Console\Command`) — базовый класс для CLI-команд фреймворка. Конкретная команда объявляется как подкласс с публичным статическим свойством `$signature` (декларативная сигнатура аргументов/опций) и переопределённым `handle()` — точкой входа с бизнес-логикой. [`Kernel`](Kernel.php.md) обнаруживает такие подклассы по каталогу, читает `$signature` каждого и на основе введённых в CLI аргументов создаёт экземпляр и вызывает его `handle()`.

## Формат `$signature`

Токены в фигурных скобках через пробел:

- `{name}` — обязательный позиционный аргумент;
- `{name?}` — необязательный позиционный аргумент (по умолчанию `null`);
- `{name=default}` — необязательный позиционный аргумент со значением по умолчанию;
- `{--flag}` — булев флаг без значения (по умолчанию `false`, `true`, если передан);
- `{--name=}` / `{--name=default}` — опция со значением (`null`/`default`, если не передана);
- `{--f|name=default}` — опция с однобуквенным алиасом (`-f`).

```php
public static string $signature = 'user:create {name} {email?} {--role=user} {--f|force}';
```

## Конструктор

```php
final public function __construct(array $positional = [], array $rawOptions = [])

final public static function fetch(array $positional = [], array $rawOptions = [])
```

Объявлен `final` — подклассы не переопределяют конструктор, а получают уже разобранные значения через `argument()`/`option()`. Внутри: парсится `static::$signature` (`parseSignature()`), затем резолвятся позиционные аргументы (`resolveArguments()` — печатает сообщение об ошибке в STDERR и завершает процесс кодом `1`, если обязательный аргумент не передан) и опции (`resolveOptions()` — учитывает алиасы и значения по умолчанию).

## handle(): void

Абстрактный метод — единственное, что обязана реализовать конкретная команда. Вся бизнес-логика команды пишется здесь.

## line(string $text): void

Печатает строку в stdout с переводом строки (`echo $text . PHP_EOL`) — единообразный вывод вместо голого `echo`.

## option(string $key = '', $default = null)

Без аргумента возвращает весь массив разобранных опций; с `$key` — значение конкретной опции либо `$default`, если её нет.

## argument(string $key = '', $default = null)

То же самое, но для позиционных аргументов.

## Пример собственной CLI-команды

```php
<?php

namespace Console\Commands;

use ST_system\Console\Command;

class UserCreateCommand extends Command {
    public static string $signature = 'user:create {name} {email?} {--role=user} {--f|force}';

    public function handle(): void {
        $name  = $this->argument('name');
        $email = $this->argument('email', 'не указан');
        $role  = $this->option('role');
        $force = $this->option('force');

        $this->line("Создаю пользователя: {$name} <{$email}>, роль: {$role}" . ($force ? ' (force)' : ''));

        // ... бизнес-логика создания пользователя
    }
}
```

Файл должен лежать в каталоге и под неймспейсом, которые настроены в конфиге [`Kernel`](Kernel.php.md) (по умолчанию `~/Console/Commands` / `Console\Commands`), чтобы быть найденным автоматически. Запуск из CLI:

```
php console.php user:create Ivan ivan@example.com --role=admin -f
```
