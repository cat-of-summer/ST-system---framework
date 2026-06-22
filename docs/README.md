<!-- DOCGEN:START -->
# ST-system---framework

## Папки

- [src](src/)

## Файлы

- [LICENSE](LICENSE.md)

<!-- DOCGEN:END -->

# ST_system

Модульная PHP-библиотека (namespace `ST_system`): конфигурация, маршрутизация,
HTTP, кэш, файловое хранилище, генерация структурированных данных (Schema.org /
Яндекс), интеграции с внешними API и консольные команды.

- **PHP:** `>= 7.4`
- **Зависимости:** нет (рантайм без сторонних пакетов)
- **Autoload:** PSR-4, `ST_system\\` → `src/`
- **Лицензия:** [MIT](LICENSE.md)

---

## Установка

Репозиторий публичный — токен/авторизация Composer не нужны.

### Вариант A — через Packagist (если пакет опубликован)

```bash
composer require cat-of-summer/st-system
```

### Вариант B — напрямую из GitHub (VCS)

В `composer.json` проекта:

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

Затем:

```bash
composer update cat-of-summer/st-system
```

> Для стабильных версий вместо `dev-main` используйте теги:
> ```bash
> git tag v1.0.0 && git push origin v1.0.0
> ```
> и в проекте `"cat-of-summer/st-system": "^1.0"` (тогда `minimum-stability` не требуется).

---

## Использование

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use ST_system\Config;
use ST_system\HTTP\Request;
// ...
```

В **Laravel** `vendor/autoload.php` подключается фреймворком — достаточно
`use ST_system\...` в любом месте.

---

## Перечень классов

Каждая ссылка ведёт на страницу с описанием класса.

### Ядро

| Класс | Назначение | Документация |
|-------|------------|--------------|
| `Main` | Статические утилиты: строки, даты, склонения, dot-нотация массивов | [src/Main.php.md](src/Main.php.md) |
| `Config` | Конфигурация: переменные окружения и `.env`, `ini`, файлы конфигов | [src/Config.php.md](src/Config.php.md) |
| `Loader` | Загрузка PHP-файлов (`require`/`include`) с проверкой синтаксиса | [src/Loader.php.md](src/Loader.php.md) |
| `Rule` | Конструктор правил/middleware: callback, `before`/`after`, порядок, обработка ошибок | [src/Rule.php.md](src/Rule.php.md) |
| `Access` | Контроль доступа: авторизация, учётные данные, CORS | [src/Access.php.md](src/Access.php.md) |
| `Debug` | Обработчик ошибок/исключений/shutdown, форматирование и линтинг | [src/Debug.php.md](src/Debug.php.md) |
| `Daemon` | Базовый класс долгоживущих фоновых процессов (интервал, повторы, события) | [src/Daemon.php.md](src/Daemon.php.md) |
| `Menu` | Построение HTML-меню из массива или файла | [src/Menu.php.md](src/Menu.php.md) |
| `Assets` | Менеджер ассетов: сборка/минификация через `Mimes` и кэш | [src/Assets.php.md](src/Assets.php.md) |
| `CensorText` | Цензурирование текста (стоп-слова) | [src/CensorText/CensorText.php.md](src/CensorText/CensorText.php.md) |

### HTTP

| Класс | Назначение | Документация |
|-------|------------|--------------|
| `HTTP\Request` | Обёртка HTTP-запроса | [src/HTTP/Request.php.md](src/HTTP/Request.php.md) |
| `HTTP\Response` | Построение HTTP-ответа | [src/HTTP/Response.php.md](src/HTTP/Response.php.md) |
| `HTTP\Route` | Определение маршрутов с префиксами и middleware | [src/HTTP/Route.php.md](src/HTTP/Route.php.md) |

### API

| Класс | Назначение | Документация |
|-------|------------|--------------|
| `API\Router` | Маршрутизация API-запросов по URL-правилам | [src/API/Router.php.md](src/API/Router.php.md) |
| `API\IntegrationDriver` | Абстрактная база драйверов интеграций (endpoint, кэш, жизненный цикл curl) | [src/API/IntegrationDriver.php.md](src/API/IntegrationDriver.php.md) |

#### Драйверы интеграций (`API\Drivers`)

| Класс | Назначение | Документация |
|-------|------------|--------------|
| `Drivers\SmsRu` | Отправка SMS через sms.ru | [src/API/Drivers/SmsRu.php.md](src/API/Drivers/SmsRu.php.md) |
| `Drivers\Sdek` | Интеграция службы доставки СДЭК | [src/API/Drivers/Sdek.php.md](src/API/Drivers/Sdek.php.md) |
| `Drivers\Isdayoff` | Производственный календарь (isdayoff.ru) | [src/API/Drivers/Isdayoff.php.md](src/API/Drivers/Isdayoff.php.md) |
| `Drivers\IpInfo` | Геоданные по IP-адресу | [src/API/Drivers/IpInfo.php.md](src/API/Drivers/IpInfo.php.md) |
| `Drivers\SmartCaptcha` | Yandex SmartCaptcha (проверка токена) | [src/API/Drivers/SmartCaptcha.php.md](src/API/Drivers/SmartCaptcha.php.md) |
| `Drivers\Telegraph` | Публикация статей в Telegra.ph | [src/API/Drivers/Telegraph.php.md](src/API/Drivers/Telegraph.php.md) |

**Эквайринг (`API\Drivers\Acquiring`)**

| Класс | Назначение | Документация |
|-------|------------|--------------|
| `Acquiring\CloudPayments` | Платежи CloudPayments | [src/API/Drivers/Acquiring/CloudPayments.php.md](src/API/Drivers/Acquiring/CloudPayments.php.md) |
| `Acquiring\Robokassa` | Платежи Robokassa | [src/API/Drivers/Acquiring/Robokassa.php.md](src/API/Drivers/Acquiring/Robokassa.php.md) |
| `Acquiring\TBank` | Платежи Т-Банк | [src/API/Drivers/Acquiring/TBank.php.md](src/API/Drivers/Acquiring/TBank.php.md) |

**Боты (`API\Drivers\Bots`)**

| Класс | Назначение | Документация |
|-------|------------|--------------|
| `Bots\TelegramBot` | Бот Telegram | [src/API/Drivers/Bots/TelegramBot.php.md](src/API/Drivers/Bots/TelegramBot.php.md) |
| `Bots\VkBot` | Бот ВКонтакте | [src/API/Drivers/Bots/VkBot.php.md](src/API/Drivers/Bots/VkBot.php.md) |
| `Bots\MaxBot` | Бот мессенджера MAX | [src/API/Drivers/Bots/MaxBot.php.md](src/API/Drivers/Bots/MaxBot.php.md) |

**CRM (`API\Drivers\CRM`)**

| Класс | Назначение | Документация |
|-------|------------|--------------|
| `CRM\Bitrix24` | Интеграция Bitrix24 | [src/API/Drivers/CRM/Bitrix24.php.md](src/API/Drivers/CRM/Bitrix24.php.md) |
| `CRM\RentalCRM` | Интеграция RentalCRM | [src/API/Drivers/CRM/RentalCRM.php.md](src/API/Drivers/CRM/RentalCRM.php.md) |

**AI (`API\Drivers\AI`)**

| Класс | Назначение | Документация |
|-------|------------|--------------|
| `AI\OpenAICompatibleDriver` | Базовый драйвер для OpenAI-совместимых LLM API | [src/API/Drivers/AI/OpenAICompatibleDriver.php.md](src/API/Drivers/AI/OpenAICompatibleDriver.php.md) |
| `AI\Mistral` | Драйвер Mistral AI | [src/API/Drivers/AI/Mistral.php.md](src/API/Drivers/AI/Mistral.php.md) |

**Парсеры (`API\Drivers\Parsers`)**

| Класс | Назначение | Документация |
|-------|------------|--------------|
| `Parsers\DefaultParser` | Базовый парсер | [src/API/Drivers/Parsers/DefaultParser.php.md](src/API/Drivers/Parsers/DefaultParser.php.md) |
| `Parsers\Prodoctorov\DoctorDetailParser` | Парсер карточки врача (Prodoctorov) | [src/API/Drivers/Parsers/Prodoctorov/DoctorDetailParser.php.md](src/API/Drivers/Parsers/Prodoctorov/DoctorDetailParser.php.md) |
| `Parsers\Prodoctorov\DoctorsReviewsParser` | Парсер отзывов о врачах (Prodoctorov) | [src/API/Drivers/Parsers/Prodoctorov/DoctorsReviewsParser.php.md](src/API/Drivers/Parsers/Prodoctorov/DoctorsReviewsParser.php.md) |

**Трейты драйверов (`API\Drivers\Traits`)**

| Трейт | Назначение | Документация |
|-------|------------|--------------|
| `Drivers\Traits\HasHTMLRules` | Правила извлечения данных из HTML | [src/API/Drivers/Traits/HasHTMLRules.php.md](src/API/Drivers/Traits/HasHTMLRules.php.md) |
| `Drivers\Traits\HasXmlResponse` | Разбор XML-ответов | [src/API/Drivers/Traits/HasXmlResponse.php.md](src/API/Drivers/Traits/HasXmlResponse.php.md) |

### Cache

| Класс | Назначение | Документация |
|-------|------------|--------------|
| `Cache\Manager` | Фасад кэша, выбор и конфигурация драйвера | [src/Cache/Manager.php.md](src/Cache/Manager.php.md) |
| `Cache\CacheDriver` | Абстрактный драйвер кэша | [src/Cache/CacheDriver.php.md](src/Cache/CacheDriver.php.md) |
| `Drivers\FileSystemCacheDriver` | Кэш в файловой системе | [src/Cache/Drivers/FileSystemCacheDriver.php.md](src/Cache/Drivers/FileSystemCacheDriver.php.md) |
| `Drivers\RedisCacheDriver` | Кэш в Redis | [src/Cache/Drivers/RedisCacheDriver.php.md](src/Cache/Drivers/RedisCacheDriver.php.md) |
| `Drivers\DatabaseCacheDriver` | Кэш в БД | [src/Cache/Drivers/DatabaseCacheDriver.php.md](src/Cache/Drivers/DatabaseCacheDriver.php.md) |
| `Drivers\SessionCacheDriver` | Кэш в сессии | [src/Cache/Drivers/SessionCacheDriver.php.md](src/Cache/Drivers/SessionCacheDriver.php.md) |
| `Drivers\Redis\RedisAdapterInterface` | Контракт Redis-адаптера | [src/Cache/Drivers/Redis/RedisAdapterInterface.php.md](src/Cache/Drivers/Redis/RedisAdapterInterface.php.md) |
| `Drivers\Redis\PhpRedisAdapter` | Адаптер расширения phpredis | [src/Cache/Drivers/Redis/PhpRedisAdapter.php.md](src/Cache/Drivers/Redis/PhpRedisAdapter.php.md) |
| `Drivers\Redis\PredisAdapter` | Адаптер библиотеки Predis | [src/Cache/Drivers/Redis/PredisAdapter.php.md](src/Cache/Drivers/Redis/PredisAdapter.php.md) |
| `Drivers\Database\DatabaseAdapterInterface` | Контракт адаптера БД | [src/Cache/Drivers/Database/DatabaseAdapterInterface.php.md](src/Cache/Drivers/Database/DatabaseAdapterInterface.php.md) |
| `Drivers\Database\MysqlAdapter` | Адаптер MySQL | [src/Cache/Drivers/Database/MysqlAdapter.php.md](src/Cache/Drivers/Database/MysqlAdapter.php.md) |
| `Drivers\Database\PostgresAdapter` | Адаптер PostgreSQL | [src/Cache/Drivers/Database/PostgresAdapter.php.md](src/Cache/Drivers/Database/PostgresAdapter.php.md) |

### Storage

| Класс | Назначение | Документация |
|-------|------------|--------------|
| `Storage\File` | Работа с файлами: поиск, чтение, кэш, mime | [src/Storage/File.php.md](src/Storage/File.php.md) |
| `Storage\Mimes\Mime` | Базовый обработчик MIME-типа | [src/Storage/Mimes/Mime.php.md](src/Storage/Mimes/Mime.php.md) |
| `Mimes\CssMime` | CSS | [src/Storage/Mimes/CssMime.php.md](src/Storage/Mimes/CssMime.php.md) |
| `Mimes\JsMime` | JavaScript | [src/Storage/Mimes/JsMime.php.md](src/Storage/Mimes/JsMime.php.md) |
| `Mimes\JsonMime` | JSON | [src/Storage/Mimes/JsonMime.php.md](src/Storage/Mimes/JsonMime.php.md) |
| `Mimes\HtmlMime` | HTML | [src/Storage/Mimes/HtmlMime.php.md](src/Storage/Mimes/HtmlMime.php.md) |
| `Mimes\ImageMime` | Изображения | [src/Storage/Mimes/ImageMime.php.md](src/Storage/Mimes/ImageMime.php.md) |
| `Mimes\SvgMime` | SVG | [src/Storage/Mimes/SvgMime.php.md](src/Storage/Mimes/SvgMime.php.md) |
| `Mimes\FontMime` | Шрифты | [src/Storage/Mimes/FontMime.php.md](src/Storage/Mimes/FontMime.php.md) |
| `Mimes\TextPlainMime` | Текст | [src/Storage/Mimes/TextPlainMime.php.md](src/Storage/Mimes/TextPlainMime.php.md) |
| `Mimes\Traits\Minifiable` | Минификация содержимого | [src/Storage/Mimes/Traits/Minifiable.php.md](src/Storage/Mimes/Traits/Minifiable.php.md) |
| `Mimes\Traits\Combinable` | Объединение ассетов | [src/Storage/Mimes/Traits/Combinable.php.md](src/Storage/Mimes/Traits/Combinable.php.md) |

### Console

| Класс | Назначение | Документация |
|-------|------------|--------------|
| `Console\Kernel` | Реестр и запуск консольных команд | [src/Console/Kernel.php.md](src/Console/Kernel.php.md) |
| `Console\Command` | Базовый класс консольной команды | [src/Console/Command.php.md](src/Console/Command.php.md) |

### Schemas — структурированные данные

| Класс | Назначение | Документация |
|-------|------------|--------------|
| `Schemas\DefaultSchema` | База для построения схем (вложенность, hooks, рендер) | [src/Schemas/DefaultSchema.php.md](src/Schemas/DefaultSchema.php.md) |

**Schema.org (`Schemas\SchemaOrg`)**

| Класс | Назначение | Документация |
|-------|------------|--------------|
| `SchemaOrg\FaqPage` | Разметка FAQPage | [src/Schemas/SchemaOrg/FaqPage.php.md](src/Schemas/SchemaOrg/FaqPage.php.md) |
| `SchemaOrg\FaqPage\Question` | Вопрос FAQPage | [src/Schemas/SchemaOrg/FaqPage/Question.php.md](src/Schemas/SchemaOrg/FaqPage/Question.php.md) |
| `SchemaOrg\ItemList` | Разметка ItemList | [src/Schemas/SchemaOrg/ItemList.php.md](src/Schemas/SchemaOrg/ItemList.php.md) |
| `SchemaOrg\ItemList\ListItem` | Элемент списка | [src/Schemas/SchemaOrg/ItemList/ListItem.php.md](src/Schemas/SchemaOrg/ItemList/ListItem.php.md) |
| `SchemaOrg\Service` | Разметка Service | [src/Schemas/SchemaOrg/Service.php.md](src/Schemas/SchemaOrg/Service.php.md) |
| `SchemaOrg\Service\Offer` | Предложение услуги | [src/Schemas/SchemaOrg/Service/Offer.php.md](src/Schemas/SchemaOrg/Service/Offer.php.md) |
| `SchemaOrg\Service\OfferCatalog` | Каталог предложений | [src/Schemas/SchemaOrg/Service/OfferCatalog.php.md](src/Schemas/SchemaOrg/Service/OfferCatalog.php.md) |
| `SchemaOrg\Service\Provider` | Поставщик услуги | [src/Schemas/SchemaOrg/Service/Provider.php.md](src/Schemas/SchemaOrg/Service/Provider.php.md) |
| `SchemaOrg\Service\PostalAddress` | Почтовый адрес | [src/Schemas/SchemaOrg/Service/PostalAddress.php.md](src/Schemas/SchemaOrg/Service/PostalAddress.php.md) |
| `SchemaOrg\MedicalProcedure` | Медицинская процедура | [src/Schemas/SchemaOrg/MedicalProcedure.php.md](src/Schemas/SchemaOrg/MedicalProcedure.php.md) |

**Яндекс (`Schemas\Yandex`)**

| Класс | Назначение | Документация |
|-------|------------|--------------|
| `Yandex\MedicalFeed` | Медицинский фид Яндекса | [src/Schemas/Yandex/MedicalFeed.php.md](src/Schemas/Yandex/MedicalFeed.php.md) |
| `MedicalFeed\Doctor` | Врач | [src/Schemas/Yandex/MedicalFeed/Doctor.php.md](src/Schemas/Yandex/MedicalFeed/Doctor.php.md) |
| `MedicalFeed\Clinic` | Клиника | [src/Schemas/Yandex/MedicalFeed/Clinic.php.md](src/Schemas/Yandex/MedicalFeed/Clinic.php.md) |
| `MedicalFeed\Service` | Услуга | [src/Schemas/Yandex/MedicalFeed/Service.php.md](src/Schemas/Yandex/MedicalFeed/Service.php.md) |
| `MedicalFeed\Price` | Цена | [src/Schemas/Yandex/MedicalFeed/Price.php.md](src/Schemas/Yandex/MedicalFeed/Price.php.md) |
| `MedicalFeed\Offer` | Предложение | [src/Schemas/Yandex/MedicalFeed/Offer.php.md](src/Schemas/Yandex/MedicalFeed/Offer.php.md) |
| `MedicalFeed\Review` | Отзыв | [src/Schemas/Yandex/MedicalFeed/Review.php.md](src/Schemas/Yandex/MedicalFeed/Review.php.md) |
| `MedicalFeed\Education` | Образование | [src/Schemas/Yandex/MedicalFeed/Education.php.md](src/Schemas/Yandex/MedicalFeed/Education.php.md) |
| `MedicalFeed\Job` | Должность/опыт | [src/Schemas/Yandex/MedicalFeed/Job.php.md](src/Schemas/Yandex/MedicalFeed/Job.php.md) |
| `MedicalFeed\Certificate` | Сертификат | [src/Schemas/Yandex/MedicalFeed/Certificate.php.md](src/Schemas/Yandex/MedicalFeed/Certificate.php.md) |

### Traits

| Трейт | Назначение | Документация |
|-------|------------|--------------|
| `Traits\HasInstance` | Синглтон-экземпляр | [src/Traits/HasInstance.php.md](src/Traits/HasInstance.php.md) |
| `Traits\HasConfig` | Конфигурация класса (значения по умолчанию, переопределение) | [src/Traits/HasConfig.php.md](src/Traits/HasConfig.php.md) |
| `Traits\HasEvents` | События и слушатели | [src/Traits/HasEvents.php.md](src/Traits/HasEvents.php.md) |
| `Traits\HasAttributes` | Доступ к атрибутам/свойствам | [src/Traits/HasAttributes.php.md](src/Traits/HasAttributes.php.md) |

---

> Страницы документации генерируются автоматически (workflow `docgen`) и
> зеркалят структуру `src/`. Описания дополняются вручную ниже маркера
> `<!-- DOCGEN:END -->` в соответствующих `.md`-файлах.
