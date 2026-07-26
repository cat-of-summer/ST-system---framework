<!-- DOCGEN:START -->
# CaptchaDriver.php
<!-- DOCGEN:END -->

`ST_system\Captcha\CaptchaDriver` — абстрактная база всех драйверов капчи. Содержит весь общий
конвейер: генерацию `id`, шифрование и хранение состояния, скрытые поля формы, honeypot,
proof-of-work, поведенческий скоринг, защиту от повтора и подсчёт попыток. Наследникам остаются
только хуки: что загадать, как отрисовать, как проверить ответ и какой у них JS.

Устроен так же, как `Cache\CacheDriver`: конструктор `final`, весь публичный API `final`,
подключены `HasConfig`, `HasAttributes` и `HasEvents`.

## Контракт наследника

```php
abstract protected function __init(array $config): void;      // разбор конфига
protected function __rebind(array $override): void {}         // перепривязка при spawn()

abstract public function isAvailable(): bool;                 // доступность драйвера

abstract protected function issue(array $params): array;      // [$secret, $public]
abstract protected function render(array $public, string $id): string;   // HTML виджета
abstract protected function verify(array $payload, array $state): bool;  // проверка ответа
abstract protected function driverJs(): string;               // тело JS-подкласса

protected function driverCss(): string { return ''; }
protected function forcedSignals(): array { return []; }      // сигналы, которые нельзя выключить
protected function providesAnswerField(): bool { return false; }
```

`issue()` возвращает пару: `$secret` уходит в зашифрованное состояние на сервере и виден только
в `verify()`, `$public` попадает в HTML и в JS-конфиг виджета. `providesAnswerField()` возвращает
`true`, если драйвер сам рисует поле ответа (так делает `TextCaptchaDriver` — его видимый
`<input>` и есть поле ответа), иначе база добавит скрытое.

## Публичный API

```php
final public function putCaptcha(array $params = []): string   // выдать челлендж, вернуть HTML
final public function check($payload): bool                    // проверить ответ и поведение
final public function includeJs(): string                      // <script> базового класса + драйвера
final public function includeCss(): string                     // <style>
final public function refresh(string $id = ''): array          // ['id' => ..., 'html' => ...]
final public function spawn($key, array $override = []): self  // клон с другим ключом

public static function resetEmitted(): void                    // сбросить флаги выдачи JS/CSS
```

Атрибуты после `check()` (доступны через `HasAttributes`): `passed`, `error`, `score`, `report`,
`reasons`. После `putCaptcha()` — `issued_id`.

`includeJs()` и `includeCss()` возвращают готовые строки и защищены от повторной выдачи:
базовый JS-класс и CSS отдаются один раз за **рендер**, JS каждого драйвера — один раз на драйвер.
Куда их положить, решает вызывающий код:

```php
Assets::addString($captcha->includeCss(), 'head');
Assets::addString($captcha->includeJs(),  'footer');
echo '<form method="post">'.$captcha->putCaptcha().'<button>Send</button></form>';
```

Флаги выдачи статические, то есть живут в процессе, а не в объекте, — иначе две капчи на одной
странице продублировали бы базовый скрипт. Сбрасывает их контрибьютор `CaptchaManager` на событии
`render_open` [`View`](../View.php.md) (`CaptchaDriver::resetEmitted()`): без сброса в долгоживущем
воркере (Swoole/RoadRunner) второй запрос не получил бы ни базового JS, ни CSS вовсе — флаги уже
взведены, а разметка первого запроса ушла другому клиенту. Вне `View` (короткий процесс на запрос)
разницы нет.

## Капча и кеш `View`

`putCaptcha()` чеканит одноразовый `id`, пишет под него состояние с TTL и вставляет `id` в разметку.
Скелет-кеш [`View`](../View.php.md) запекает вывод шаблона и переиспользует его, пока не сдвинутся
mtime зависимостей — то есть закешированный узел раздавал бы **один и тот же** челлендж всем
посетителям сколь угодно долго. Через `ttl` секунд запись состояния протухает, и форма начинает
вечно отвечать `expired`; после первого успешного прохода — `replayed`.

Штатное решение — вынести капчу в **некешируемый** узел: в скелет родителя попадёт только
hole-токен, а компонент будет рендериться заново на каждом запросе (см. «Живой регион» в
[View.php](../View.php.md)).

```php
// component/captcha/index.php — источник зарегистрирован как
// 'component' => ['path' => …, 'cache' => ['use' => false]]
$captcha = CaptchaManager::checkbox(View::get('form_key'));

Assets::addString($captcha->includeCss(), 'head');
Assets::addString($captcha->includeJs(),  'footer');

echo $captcha->putCaptcha();
```

Скелет родителя при этом пишется и переиспользуется как обычно; платой идёт composed-кеш всей
страницы — любой динамический узел в дереве его отключает.

На случай, когда `putCaptcha()` позвали прямо в теле кешируемого шаблона, у метода есть страховка:
первым делом он зовёт `CaptchaManager::markLive()`, а тот — `View::live()`. Узел просто перестаёт
кешироваться, вместо того чтобы молча заморозить челлендж. Вне рендера `View` (например, AJAX-хендлер
`refresh()`) это no-op.

Результат `check()` в кешируемый шаблон надо передавать пропсами или через `View::set()`: чтение
`$_POST` прямо из шаблона механизмом `reads` не отслеживается и на кеш-хите не выполнится вовсе.

## Состояние челленджа

Ключ формы обязателен: конструктор и `spawn()` бросают `\InvalidArgumentException` на пустом
значении (`''`, `null`, `[]`, `false`). Его хэш кладётся в состояние и сверяется при проверке,
поэтому челлендж нельзя переиспользовать на другой форме.

При `putCaptcha()` генерируется `id` (`bin2hex(random_bytes(16))`) и собирается состояние:
драйвер, класс, хэш ключа формы, время выдачи и истечения, счётчик попыток, набор
поведенческих сигналов, PoW-задача и случайное имя honeypot-поля, плюс `$secret` драйвера.

Состояние шифруется `Access::seal($state, $salt)` (XOR-поток на SHA-256 + 8-байтовый HMAC-тег)
и кладётся в `CacheManager` под ключом `['st_captcha', $id]`. Клиенту уходит только `id`.

**Соль обязательна.** Если не задана ни она сама (`CaptchaManager::config('default.salt')` либо
опция `salt` конкретного драйвера), ни общая `Access::config('salt')`, конструктор драйвера —
а значит и любой `CaptchaManager::<драйвер>()` — бросает `\LogicException`. То же проверяется в
`spawn()`, если клону явно передали пустую соль.

Проверка живёт здесь, а не в `Access`, намеренно. `Access::seal($state, $salt = '')` — примитив
общего назначения с осознанной веткой деградации: без соли он возвращает открытый JSON, и для
задач, где нужна просто обфускация, это законный режим. У капчи иначе: в состоянии лежит
**правильный ответ**, поэтому открытый JSON — не деградация, а дыра. Заодно `unseal()` — путь
**чтения**: бросай он на пустой соли, опечатка в конфиге превращалась бы в 500 на каждом запросе,
читающем уже сохранённый блоб. Драйвер же падает один раз, в момент создания, ровно там, где
ошибка конфигурации.

Пустая соль **самого драйвера** при этом законна, если задана общая `Access::config('salt')` —
`seal()`/`unseal()` подставят её сами, и дублировать значение в конфиг капчи не нужно.

## Поля формы

Имена строятся от `field_prefix` (по умолчанию `st-captcha`):

| Поле | Назначение |
|------|------------|
| `st-captcha-id` | идентификатор челленджа |
| `st-captcha-answer` | ответ пользователя (скрытое поле либо видимый ввод драйвера) |
| `st-captcha-behavior` | сериализованные поведенческие сигналы |
| `c_<8 hex>` | honeypot: случайное имя, скрыто через `.st-captcha-hp`, должно остаться пустым |

## Конвейер `check()`

1. Достать `id`, прочитать и расшифровать состояние. Нет или протухло → `expired`.
2. Уже использован → `replayed`.
3. Не совпал хэш ключа формы → `mismatch`.
4. Превышено число попыток → `attempts`, запись удаляется.
5. Заполнен honeypot → `honeypot`, запись удаляется.
6. `verify()` — проверка ответа драйвером.
7. `Behavior::score()` — скоринг сигналов, результат в `score` / `report` / `reasons`.
8. Успех = верный ответ **и** `score >= min_score`. При успехе запись заменяется на «надгробие»
   (`used`) до конца TTL — так повтор отличается от протухания. При неудаче инкрементится
   счётчик попыток; `error` = `answer` (ответ неверен) или `behavior` (ответ верен, но доверия
   не хватило).

## События

`HasEvents`, зарезервированы `issue`, `verify`, `score` — их нельзя вызвать через `trigger()`,
но можно слушать:

```php
$captcha->on('score', function (array $result, array $state, bool $passed) {
    Debug::toFile(['captcha' => $result, 'passed' => $passed]);
});
```

## Клиентская часть

`includeJs()` отдаёт IIFE с `window.STCaptcha`, внутри — классы `Driver` и `Behavior` и реестр
драйверов. Драйверы наследуют базовый JS-класс:

```js
STCaptcha.register('text', class extends STCaptcha.Driver {
    onMount() { /* ... */ }
});
```

Порядок подключения не важен: `putCaptcha()` пишет запрос на монтирование в
`window.STCaptchaQueue`, а `STCaptcha.flush()` разбирает очередь при загрузке базового класса,
при регистрации каждого драйвера и на `DOMContentLoaded`.

Хуки подкласса: `onMount()` — навесить свои обработчики; `execute()` — вернуть `Promise` для
драйверов, которым нужен токен до отправки формы (геттер `deferSubmit`). Готовые методы:
`solve(value)`, `fail(reason)`, `reset()`, `answer(value)`, `setState(state)`, `field(name)`.

База сама перехватывает `submit` ближайшей формы: если PoW ещё считается или капча
`deferSubmit` не решена, отправка откладывается до готовности, затем поля заполняются и форма
отправляется повторно. Несколько капч в одной форме поддерживаются.

Состояние виджета отражается в атрибуте `data-captcha-state` (`pending` / `valid` / `invalid`) и
всплывающих событиях `captcha:ready`, `captcha:success`, `captcha:fail`, `captcha:reset` —
слушать можно и на элементе, и на `window`.

CSS минимальный: только позиционирование honeypot, дорожки свайпа, картинки и поля ввода. Ни
цветов, ни оформления.

См. также: `Captcha\CaptchaManager`, `Captcha\Behavior`, `Access`, `Cache\CacheManager`.
