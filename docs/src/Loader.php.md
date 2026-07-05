# Loader.php

## 1. Концепция

`Loader` — **утилита динамической загрузки PHP-файлов** с двумя режимами работы: статическим (напрямую) и объектным (с привязкой исходного пути). Финальный класс.

Ключевые идеи:

1. **Два режима.** `Loader::require('/path/to/dir')` — статический вызов. `Loader::create('/base/dir')->require('subdir')` — объектный, с базовым путём.

2. **Четыре способа подключения.** `require`, `require_once`, `include`, `include_once` — называются как методы через `__callStatic` / `__call`.

3. **`include`-режим с линтером.** Перед подключением через `include` или `include_once` запускается `Debug::linter()`. Файлы с ошибками пропускаются без сбоя.

4. **Регистрация классов.** `registerClass()` добавляет одноразовый `spl_autoload_register`, `registerDir()` рекурсивно сканирует директорию и регистрирует классы по PSR-4.

```php
// Загрузить все .php файлы из директории:
Loader::require('/var/www/html/helpers');

// С базовым путём:
$loader = Loader::create('/var/www/html');
$loader->require('helpers'); // загрузит html/helpers/**/*.php

// Регистрация класса в автолоадер:
Loader::create('/src')
    ->registerDir([
        'namespace' => 'App\\Models',
        'dir'       => 'Models',
    ]);
```

---

## 2. Публичные методы

### `static create(string $path): self`

Фабрика аналогична `new Loader($path)`. Создаёт экземпляр `Loader` с привязкой к директории `$path`.

**Бросает:** `Exception` если передан URI (не локальный путь).

---

### `static __callStatic(string $name, array $args): void`

Обрабатывает статические вызовы:

| Имя метода | Параметры | Описание |
|-----------|------------|----------|
| `require` | `$path, $opts=[]` | Загрузает найденные PHP-файлы через `require` |
| `require_once` | `$path, $opts=[]` | То же через `require_once` |
| `include` | `$path, $opts=[]` | Загрузает с проверкой линтера |
| `include_once` | `$path, $opts=[]` | То же через `include_once` |
| `registerDir` | `$path, $opts=[]` | Регистрирует директорию в autoload |
| `registerClass` | `$path, $class, $prefix` | Регистрирует один класс |

`$path` — абсолютный путь или glob-шаблон для `File::find()`.  
`$opts` — дополнительные опции для `File::find()` (например, `recursive => false`).

```php
// Загрузить все .php рекурсивно:
Loader::require('/var/www/html/src');

// Загрузить без рекурсии:
Loader::require('/var/www/html/helpers', ['recursive' => false]);

// include с проверкой линтера:
Loader::include('/var/www/html/optional/script.php');
```

---

### `__call(string $name, array $args): void`

Аналог `__callStatic` для инстанс-режима. Использует базовый путь из конструктора для разрешения относительных путей.

```php
$loader = Loader::create('/var/www/html');
$loader->require('src/Controllers'); // загрузит html/src/Controllers/**/*.php
$loader->registerDir(['namespace' => 'App']);
```

---

### `__construct(string $path)`

Создаёт `Loader` с базовым путём. Внутри создаёт экземпляр `File` для последующей работы с файловой системой.

**Бросает:** `Exception` если `$path` является URI.
