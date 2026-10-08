<!-- DOCGEN:START -->
# Command.php
<!-- DOCGEN:END -->

`abstract class Command` (`ST_system\Console\Command`) — базовый класс для CLI-команд фреймворка. Конкретная команда объявляется как подкласс с защищённым статическим свойством `$signature` (декларативная сигнатура аргументов/опций) и переопределённым `handle()` — точкой входа с бизнес-логикой. [`Kernel`](Kernel.php.md) обнаруживает такие подклассы по каталогу, регистрирует каждый под именем из сигнатуры (`getName()`) и на основе введённых в CLI аргументов создаёт экземпляр и вызывает его `handle()`.

## `$signature`, `getSignature()` и `getName()`

```php
protected static string $signature = '';

final public static function getSignature(): string

final public static function getName(): string
```

Свойство **`protected`**: сигнатура - декларация класса, а не runtime-состояние, и переопределять её на лету снаружи нельзя. Наружу она отдаётся только на чтение, через `final public static getSignature()` - `final`, чтобы источником значения всегда оставалось само свойство. Читается через позднее статическое связывание (`static::$signature`), поэтому подкласс объявляет своё значение обычным переопределением.

`getName()` - имя команды: первое слово сигнатуры до пробела или `{` (`'backup_cron {--user}'` → `backup_cron`). Под этим именем `Kernel` регистрирует команду и по нему же ищет её в `$argv[1]`. Команда без имени - пустая сигнатура или сигнатура из одних токенов (`'{--user}'`) - в `Kernel` не регистрируется.

## Формат `$signature`

Первое слово - имя команды, за ним токены в фигурных скобках через пробел:

- `{name}` — обязательный позиционный аргумент;
- `{name?}` — необязательный позиционный аргумент (по умолчанию `null`);
- `{name=default}` — необязательный позиционный аргумент со значением по умолчанию;
- `{--flag}` — булев флаг без значения (по умолчанию `false`, `true`, если передан);
- `{--name=}` / `{--name=default}` — опция со значением (`null`/`default`, если не передана);
- `{--f|name=default}` — опция с однобуквенным алиасом (`-f`).

```php
protected static string $signature = 'user:create {name} {email?} {--role=user} {--f|force}';
```

## Конструктор

```php
final public function __construct(array $positional = [], array $rawOptions = [])

final public static function fetch(array $positional = [], array $rawOptions = [])
```

Объявлен `final` — подклассы не переопределяют конструктор, а получают уже разобранные значения через `argument()`/`option()`. Внутри: парсится `static::getSignature()` (`parseSignature()`), затем резолвятся позиционные аргументы (`resolveArguments()` — печатает сообщение об ошибке в STDERR и завершает процесс кодом `1`, если обязательный аргумент не передан) и опции (`resolveOptions()` - учитывает алиасы и значения по умолчанию; опции, не объявленные в сигнатуре, не отбрасываются и тоже доступны через `option()`). Объявлять опцию в сигнатуре стоит ради значения по умолчанию и алиаса.

Каждая команда, помимо своих опций, принимает глобальный флаг `--no-interaction` (алиас `-n`, если команда не заняла `-n` своей опцией). Он виден и в `option()`.

## handle()

Абстрактный метод — единственное, что обязана реализовать конкретная команда. Вся бизнес-логика команды пишется здесь. Тип возврата в базовом классе не объявлен: если `handle()` вернёт `int`, [`Kernel`](Kernel.php.md) сделает его кодом выхода процесса, иначе код будет `0`. Команды с `handle(): void` совместимы и завершаются с `0`.

## Вывод

- **`line(string $text): void`** — строка в STDOUT с переводом строки.
- **`error(string $text): void`** — строка в STDERR. Диагностика не смешивается с полезным выводом, который могут перенаправлять в файл или пайп.

## Ввод

Устроен как в Laravel (Symfony Console): вопросы задаются только при живом терминале, а в скриптах и CI команда не зависает.

- **`isInteractive(): bool`** — `false`, если передан `-n`/`--no-interaction` или STDIN не терминал (пайп, перенаправление, `docker run` без `-t`, cron).
- **`ask(string $question, ?string $default = null): ?string`** — задаёт вопрос и читает строку из STDIN. Пустой ответ и неинтерактивный режим дают `$default`.
- **`confirm(string $question, bool $default = false): bool`** — да/нет. Принимаются `y`, `yes`, `д`, `да` в любом регистре, любой другой ответ даёт `false`. Пустой ответ и неинтерактивный режим дают `$default`.
- **`secret(string $question): ?string`** — как `ask()`, но на *nix ввод не отображается (`stty -echo`). На Windows ввод виден. В неинтерактивном режиме возвращает `null`.
- **`stdin(): string`** — всё, что передано в команду пайпом или перенаправлением (`cat dump.sql | php console.php db:import`). При вводе с терминала возвращает пустую строку, а не ждёт EOF. Поток читается один раз, повторные вызовы отдают то же содержимое.

`stdin()` и вопросы делят один поток: если команда читает пайп, `isInteractive()` уже `false`, и `ask()` отдаёт значения по умолчанию.

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
    protected static string $signature = 'user:create {name} {email?} {--role=user} {--f|force}';

    public function handle(): int {
        $name  = $this->argument('name');
        $email = $this->argument('email', 'не указан');
        $role  = $this->option('role');
        $force = $this->option('force');

        if (!$force && !$this->confirm("Создать пользователя {$name}?", true)) {
            $this->error('Отменено');
            return 1;
        }

        $this->line("Создаю пользователя: {$name} <{$email}>, роль: {$role}" . ($force ? ' (force)' : ''));

        // ... бизнес-логика создания пользователя
        return 0;
    }
}
```

Файл должен лежать в каталоге и под неймспейсом, которые настроены в конфиге [`Kernel`](Kernel.php.md) (по умолчанию `~/Console/Commands` / `Console\Commands`), чтобы быть найденным автоматически. Запуск из CLI:

```
php console.php user:create Ivan ivan@example.com --role=admin -f
```
