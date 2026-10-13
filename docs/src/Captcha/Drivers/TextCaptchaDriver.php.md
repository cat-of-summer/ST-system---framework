<!-- DOCGEN:START -->
# TextCaptchaDriver.php
<!-- DOCGEN:END -->

`ST_system\Captcha\Drivers\TextCaptchaDriver` — классическая капча: картинка со случайным
текстом, набранным случайными шрифтами, искажённая волной и зашумлённая. Пользователь
распознаёт текст и вводит его.

Имя драйвера — `text`.

## Конфигурация

| Ключ | По умолчанию | Назначение |
|------|--------------|------------|
| `length` | `4` | длина текста, 3..12 |
| `char_pool` | `ABCDEFGHJKLMNPQRSTUVWXYZ23456789` | алфавит без визуально спорных символов (`I`, `O`, `0`, `1`) |
| `width` / `height` | `240` / `80` | размер PNG |
| `font_size` | `0` | размер шрифта; `0` — считается от высоты (52%) |
| `distortion` | `4` | амплитуда волны в пикселях, `0` — выключить |
| `noise` | `6` | доля зашумлённых пикселей в процентах, максимум 30 |
| `lines` | `3` | число случайных линий поверх текста |
| `contrast` | `10` | понижение контраста |
| `fonts` | `'~/captcha_fonts'` | где искать шрифты: путь, шаблон, `Storage\File` или массив |
| `font_types` | `['ttf', 'otf']` | какие расширения считать шрифтами |
| `max_fonts` | `50` | сколько шрифтов брать максимум; `0` — без ограничения |
| `case_sensitive` | `false` | учитывать регистр. При `false` текст на картинке всегда заглавными (даже если в `char_pool` строчные), а вводить можно в любом регистре |
| `image` | `'auto'` | адаптер отрисовки: `auto`, `gd`, `imagick` или имя класса с `Image\ImageAdapterInterface` |

Плюс общие ключи `CaptchaDriver` (`ttl`, `attempts`, `min_score`, `behavior`, ...).

## Шрифты

**Шрифты поставляет приложение, а не библиотека.** По умолчанию драйвер ищет их в
`~/captcha_fonts` (`~` резолвится в `DOCUMENT_ROOT`, иначе `COMPOSER_ROOT`). Директория
`assets/captcha/fonts` в репозитории — только для разработки и демо: корневой `assets/` помечен
`export-ignore` в `.gitattributes` и в composer-архив не попадает.

Если ни одного шрифта не найдено, `isAvailable()` вернёт `false`, и `CaptchaManager` бросит
`RuntimeException` с именем драйвера - без положенных шрифтов `text` не создаётся.

Шрифты собираются через `Storage\File::find()`, поэтому поддерживаются директории
(рекурсивно), шаблоны, отдельные файлы, готовые объекты `Storage\File` и любые их комбинации
массивом:

```php
CaptchaManager::text('login_form', ['fonts' => '~/captcha_fonts']);
CaptchaManager::text('login_form', ['fonts' => '~/captcha_fonts/Display.*\.ttf']);
CaptchaManager::text('login_form', ['fonts' => [
    '~/captcha_fonts',
    '/usr/share/fonts/truetype/dejavu/DejaVuSerif.ttf',
]]);
```

Шаблоны в `File::find()` — **регулярные выражения, а не shell-glob**: `special*.ttf` означает
«specia», затем сколько угодно «l», затем любой символ и «ttf», и файл `special_elite.ttf` не
найдёт; корректный вариант — `special.*\.ttf`.

Абсолютные пути и пути с `~` берутся как есть, остальные считаются относительными корня
приложения. Найденное фильтруется по расширениям из `font_types` **и** по mime-сервису: файл
принимается, только если `Storage\File::getServiceName()` отдаёт `Storage\Mimes\FontMime` —
посторонние файлы в директории со шрифтами игнорируются.

Итоговый список путей лежит в атрибуте `fonts` — именно он уходит в адаптер отрисовки:

```php
$captcha = CaptchaManager::text('login_form');
$captcha->fonts;   // ['/var/www/captcha_fonts/minecraft.ttf', ...]
```

## Отрисовка

Картинка генерируется в момент `putCaptcha()` и уходит в HTML как `data:image/png;base64,…` —
ни второго запроса, ни роута не нужно. Правильный ответ хранится только в зашифрованном
состоянии на сервере.

Адаптер выбирается через `Image\Adapters::resolve()`: при `auto` - тот, что выбран для
`ImageMime::getImageDriver()` (Imagick даёт искажения лучше), затем второй. Явно задаётся ключом
`image`, в том числе именем своего класса - так тесты подставляют заглушку. Если нет ни одного
бэкенда или не найдено ни одного TTF, `isAvailable()` вернёт `false`, и `CaptchaManager` бросит
`RuntimeException`.

Шрифт выбирается случайно **для каждого символа**, каждый символ поворачивается на случайный
угол и центрируется по вертикали через метрики глифа.

## Особенности

Драйвер сам рисует поле ответа (`providesAnswerField()` возвращает `true`) — видимый
`<input class="st-captcha-input" name="st-captcha-answer">` и есть поле ответа, скрытое база не
добавляет. JS-подкласс только отмечает виджет решённым, когда введена нужная длина.

Сравнение — `hash_equals()` после нормализации (по умолчанию регистронезависимо через
`mb_strtoupper`).

```php
$captcha = CaptchaManager::text('login_form', ['length' => 5, 'width' => 320]);

Assets::addString($captcha->includeCss(), 'head');
Assets::addString($captcha->includeJs(),  'footer');
echo '<form method="post">'.$captcha->putCaptcha().'<button>Send</button></form>';
```

Обновление картинки - `refresh()`: гасит старый челлендж и выдаёт новый HTML вместе с новым
`id`. Роут для этого регистрирует приложение, в поставку он не входит.

## Наследование

Наследник меняет задание, не трогая рисование, шрифты и проверку, - так устроен
[MathCaptchaDriver](MathCaptchaDriver.php.md):

| Метод | Что задаёт |
|-------|-----------|
| `challenge(): array` | `[текст на картинке, ожидаемый ответ]`; ответ уже нормализован |
| `normalize(string): string` | как приводится введённое перед сравнением |
| `answerLength(string): int` | сколько символов ждёт клиент, прежде чем считать поле заполненным (`length` в публичном конфиге) |
| `inputAttributes(array $public): string` | атрибуты поля ответа сверх `name` и `class` |
| `adapter(): ?string` | выбор адаптера отрисовки |

JS регистрируется под именем драйвера (`static::name()`), поэтому наследнику свой скрипт не нужен.

См. также: `Captcha\CaptchaDriver`, `Captcha\Drivers\Image\GdAdapter`,
`Captcha\Drivers\Image\ImagickAdapter`, `Storage\Mimes\ImageMime`.
