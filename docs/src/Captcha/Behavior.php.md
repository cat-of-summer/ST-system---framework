<!-- DOCGEN:START -->
# Behavior.php
<!-- DOCGEN:END -->

`ST_system\Captcha\Behavior` — поведенческий слой капчи. Отвечает за обе стороны: генерирует
клиентский класс `Behavior` (собирает сигналы в браузере) и считает по ним итоговый скор на
сервере. Используется всеми драйверами через `CaptchaDriver`.

## API

```php
public static function score(?string $raw, array $state, array $config): array;
public static function bootstrapJs(): string;
public const GROUPS = ['basic', 'env', 'pow', 'fingerprint'];
```

`score()` возвращает `['score' => float, 'report' => [группа => float], 'reasons' => [строки]]`.
`bootstrapJs()` возвращает тело JS-класса и встраивается в бутстрап `CaptchaDriver`.

## Группы сигналов

**`basic` — взаимодействие.** Клиент пишет децимированную траекторию курсора, тайминги
`pointerdown/up`, dwell/flight клавиш, `pointerType`, факт вставки из буфера, время до первого
события и время решения, флаг `Event.isTrusted` и состояние honeypot.

Сервер проверяет: заполненный honeypot и любое нетrusted-событие обнуляют группу; решение
быстрее `basic.min_solve` (700 мс) и старт быстрее 80 мс штрафуются; отсутствие движений
курсора и тачей; доля строго прямых сегментов траектории (> 0.92 — прямая линия, робот);
нормированная энтропия Шеннона по распределению скоростей (< 0.35 — равномерное движение);
полное отсутствие разворотов направления (нет микродрожания); коэффициент вариации ритма
клавиш (< 0.08 — машинный ввод); вставка из буфера.

**`env` — окружение браузера.** `navigator.webdriver` и маркеры CDP / Selenium / Puppeteer /
Playwright / PhantomJS / Nightmare (включая `cdc_*` в `document`) обнуляют группу сразу.
Дальше штрафуются: `HeadlessChrome` в UA, пустые `languages` и `plugins`, нулевые `screen` и
viewport, `hardwareConcurrency < 2`, отсутствие таймзоны, расхождение UA клиента с заголовком
`User-Agent` и несоответствие UA ↔ `navigator.platform`.

**`pow` — proof-of-work.** Сервер выдаёт случайный `challenge` и сложность в битах
(`behavior.pow.difficulty`, по умолчанию 15). Клиент через WebCrypto пачками по 256 ищет
`nonce`, при котором `sha256(challenge + nonce)` начинается с нужного числа нулевых бит.
Сервер проверяет одним хешированием. Пустой или неверный nonce обнуляет группу.

**`fingerprint` — отпечаток устройства.** Хеши canvas, рендерер WebGL
(`WEBGL_debug_renderer_info`), сигнатура `OfflineAudioContext` и число доступных шрифтов.
Штрафуются программные рендереры (SwiftShader, llvmpipe, Mesa OffScreen, softpipe, virgl),
отсутствие WebGL, canvas или audio, менее трёх шрифтов.

## Итоговый скор

Каждая группа даёт значение в `[0, 1]`. Итог — взвешенное среднее по `behavior.weights`,
нормированное на сумму весов включённых групп:

```php
'weights' => ['basic' => 0.4, 'env' => 0.3, 'pow' => 0.15, 'fingerprint' => 0.15]
```

Пустой или неразбираемый `st-captcha-behavior` при включённых сигналах даёт `0.0` и причину
`no_behavior_data`. Если сигналов нет вовсе, `score()` возвращает `1.0` — поведенческая
проверка не влияет на вердикт.

Результат сравнивается с `min_score` драйвера в `CaptchaDriver::check()`.

## Настройка набора сигналов

Параметр `behavior` при создании инстанса:

```php
CaptchaManager::make('login', ['driver' => 'swipe', 'behavior' => true]);   // набор по умолчанию
CaptchaManager::make('login', ['driver' => 'swipe', 'behavior' => false]);  // только принудительные
CaptchaManager::make('login', ['driver' => 'swipe', 'behavior' => ['basic', 'env']]);

CaptchaManager::make('login', ['driver' => 'swipe', 'behavior' => [
    'signals' => ['basic', 'env', 'pow'],
    'pow'     => ['difficulty' => 12],
    'basic'   => ['min_solve' => 1200],
    'weights' => ['basic' => 0.5, 'env' => 0.3, 'pow' => 0.2],
]]);
```

К любому заданному набору всегда добавляются `forcedSignals()` драйвера — их нельзя выключить.
`invisible` требует все четыре группы, `checkbox` — `basic` и `env`, `swipe` — `basic`.
У `smart` поведенческий слой по умолчанию отключён: проверку выполняет Yandex.

## Что это ловит и чего не ловит

Слой рассчитан на массовую автоматизацию: headless-браузеры, скрипты на Puppeteer/Playwright,
прямые POST-запросы без исполнения JS, машинный ввод. Целевую атаку, где сложные сигналы
воспроизводятся человекоподобно, поведенческий скоринг не остановит — для таких сценариев
используйте `text` или `smart` как основную проверку, а поведение как дополнительный фильтр.

Группа `fingerprint` собирает отпечаток устройства — учитывайте это в политике приватности.
При необходимости выключается через `behavior.signals`.

См. также: `Captcha\CaptchaDriver`, `Captcha\CaptchaManager`.
