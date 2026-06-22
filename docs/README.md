<!-- DOCGEN:START -->
# ST-system---framework

## Папки

- [src](src/)

## Файлы

- [LICENSE](LICENSE.md)

<!-- DOCGEN:END -->

# Подключение библиотеки через Composer

Репозиторий приватный, поэтому нужен GitHub Personal Access Token.

---

## 1. Авторизовать Composer

Выполни в терминале (один раз на машине, токен сохранится в `~/.composer/auth.json`):

```bash
composer config --global github-oauth.github.com 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
```

В репозиторий проекта этот файл **не попадёт** — он лежит глобально.

---

## 2. Добавить репозиторий в `composer.json` проекта

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/cat-of-summer/ST-system---framework"
        }
    ],
    "require": {
        "cat-of-summer/st-system": "dev-main"
    },
    "minimum-stability": "dev"
}
```

Или только секцию `repositories`, а пакет добавить командой из следующего шага.

---

## 3. Установить пакет

```bash
composer require cat-of-summer/php-classes:dev-main
```

Composer скачает библиотеку в `vendor/cat-of-summer/php-classes/`.

---

## 4. Использование в коде

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use ST_system\Config;
use ST_system\HTTP\Request;
// ...
```

В **Laravel** `vendor/autoload.php` уже подключён фреймворком — просто используй `use ST_system\...` в любом месте.

---

## 5. Обновление библиотеки

После пуша изменений в репозиторий библиотеки выполни в проекте:

```bash
composer update cat-of-summer/php-classes
```

---

## Семантическое версионирование (опционально)

Чтобы фиксировать стабильные версии вместо `dev-main`:

```bash
# В репозитории библиотеки
git tag v1.0.0
git push origin v1.0.0
```

Тогда в проектах можно использовать:

```json
"cat-of-summer/php-classes": "^1.0"
```
