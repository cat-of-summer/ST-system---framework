# Подключение библиотеки через Composer

Репозиторий приватный, поэтому нужен GitHub Personal Access Token.

---

## 1. Создать GitHub Personal Access Token

1. GitHub → Settings → Developer settings → Personal access tokens → **Tokens (classic)**
2. Нажать **Generate new token (classic)**
3. Отметить scope: **`repo`** (даёт доступ к приватным репозиториям)
4. Сохрани токен — он показывается только один раз

---

## 2. Авторизовать Composer

Выполни в терминале (один раз на машине, токен сохранится в `~/.composer/auth.json`):

```bash
composer config --global github-oauth.github.com <ВАШ_ТОКЕН>
```

В репозиторий проекта этот файл **не попадёт** — он лежит глобально.

---

## 3. Добавить репозиторий в `composer.json` проекта

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/cat-of-summer/php_classes"
        }
    ],
    "require": {
        "cat-of-summer/php-classes": "dev-main"
    },
    "minimum-stability": "dev"
}
```

Или только секцию `repositories`, а пакет добавить командой из следующего шага.

---

## 4. Установить пакет

```bash
composer require cat-of-summer/php-classes:dev-main
```

Composer скачает библиотеку в `vendor/cat-of-summer/php-classes/`.

---

## 5. Использование в коде

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use ST_system\Config;
use ST_system\HTTP\Request;
// ...
```

В **Laravel** `vendor/autoload.php` уже подключён фреймворком — просто используй `use ST_system\...` в любом месте.

---

## 6. Обновление библиотеки

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
