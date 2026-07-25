<!-- DOCGEN:START -->
# InvisibleCaptchaDriver.php
<!-- DOCGEN:END -->

`ST_system\Captcha\Drivers\InvisibleCaptchaDriver` — капча без интерфейса. Пользователь не видит
и не делает ничего; вердикт полностью строится на поведенческих факторах.

Имя драйвера — `invisible`.

## Как работает

`render()` не выводит ничего — в форму попадают только скрытые поля базового класса и honeypot.
JS-подкласс сразу при монтировании записывает в поле ответа одноразовый `nonce`, выданный
сервером, так что запрос без исполнения JS не пройдёт проверку ответа вовсе.

Всё остальное решает `Captcha\Behavior`: `forcedSignals()` включает все четыре группы
(`basic`, `env`, `pow`, `fingerprint`) — выключить их параметром `behavior` нельзя. Порог
доверия поднят: `min_score` по умолчанию `0.65` вместо общих `0.5`.

## Когда применять

Подходит для форм с умеренным риском, где важно не мешать пользователю: обратная связь,
подписка, комментарии. Останавливает массовую автоматизацию — headless-браузеры, скрипты на
Puppeteer/Playwright, прямые POST без JS. Против целевой атаки, воспроизводящей человеческое
поведение, не поможет: там нужен `text` или `smart`.

Ложноположительные срабатывания возможны у пользователей с отключённым JS, жёсткими
anti-fingerprint расширениями или в старых браузерах без WebCrypto. Если это критично, снизьте
`min_score` или уберите группу `fingerprint`:

```php
$captcha = CaptchaManager::invisible('feedback', [
    'min_score' => 0.5,
    'behavior'  => ['basic', 'env', 'pow'],
]);
```

Отслеживать причины отказов удобно через атрибуты после `check()`:

```php
if (!$captcha->check(Request::post()))
    Debug::toFile(['score' => $captcha->score, 'reasons' => $captcha->reasons]);
```

См. также: `Captcha\Behavior`, `Captcha\CaptchaDriver`,
`Captcha\Drivers\CheckboxCaptchaDriver`.
