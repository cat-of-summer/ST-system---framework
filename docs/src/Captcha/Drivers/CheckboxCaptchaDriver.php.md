<!-- DOCGEN:START -->
# CheckboxCaptchaDriver.php
<!-- DOCGEN:END -->

`ST_system\Captcha\Drivers\CheckboxCaptchaDriver` — чекбокс, который нужно отметить.

Имя драйвера — `checkbox`.

## Как работает

`render()` выводит `<label class="st-captcha-box"><input type="checkbox" class="st-captcha-checkbox"></label>`
без подписи. JS-подкласс слушает `change` и записывает в поле ответа одноразовый `nonce`
**только если `event.isTrusted === true`** — программная установка `checked` через
`element.checked = true; dispatchEvent(...)` снимает галочку обратно и переводит виджет в
состояние `invalid`.

Отмеченный чекбокс снять нельзя: при взведённом `solvedFlag` клик отменяется `preventDefault()`,
поэтому галочка остаётся на месте, а курсор становится `default`. Иначе поле ответа осталось бы
заполненным при снятой галочке — виджет выглядел бы нерешённым, будучи решённым. Штатный возврат в
исходное — `reset()`, драйвер расширяет базовый метод, чтобы заодно снять галочку. То же поведение у
[swipe](SwipeCaptchaDriver.php.md).

Сам по себе клик по чекбоксу защитой не является: `nonce` лежит в DOM. Барьером служит
поведенческий слой — `forcedSignals()` принудительно включает группы `basic` и `env`, их нельзя
отключить параметром `behavior`. Группы `pow` и `fingerprint` входят в набор по умолчанию, но
могут быть выключены.

```php
$captcha = CaptchaManager::checkbox('login_form');

Assets::addString($captcha->includeCss(), 'head');
Assets::addString($captcha->includeJs(),  'footer');
echo '<form method="post">'.$captcha->putCaptcha().'<button>Send</button></form>';
```

## Конфигурация

Собственных ключей нет — только общие ключи `CaptchaDriver`: `ttl`, `attempts`, `min_score`,
`field_prefix`, `salt`, `cache`, `behavior`.

CSS минимальный: `.st-captcha-box` — инлайн-флекс с рамкой и отступом, `.st-captcha-checkbox` —
20×20 пикселей. Ни цветов, ни анимаций; оформление добавляет приложение.

См. также: `Captcha\Behavior`, `Captcha\CaptchaDriver`,
`Captcha\Drivers\InvisibleCaptchaDriver`.
