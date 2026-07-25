<!-- DOCGEN:START -->
# ImageAdapterInterface.php
<!-- DOCGEN:END -->

`ST_system\Captcha\Drivers\Image\ImageAdapterInterface` — контракт бэкенда отрисовки картинки
для `TextCaptchaDriver`. Устроен так же, как адаптеры кеша (`Cache\Drivers\Redis\*`): интерфейс
плюс реализации с проверкой доступности.

```php
public static function isAvailable(): bool;
public static function render(string $text, array $options): string;
```

`isAvailable()` проверяет, что нужное расширение установлено и умеет PNG. `render()` возвращает
готовый бинарный PNG; ошибки отрисовки — `\RuntimeException`.

Ключи `$options`: `width`, `height`, `fonts` (массив путей к TTF), `font_size` (необязательный),
`distortion`, `noise`, `lines`, `contrast`. Значения приходят из конфига `TextCaptchaDriver`.

Реализации: `GdAdapter`, `ImagickAdapter`. Выбор делает `TextCaptchaDriver` по
`ImageMime::getImageDriver()` либо явно ключом `image`.

Свой адаптер достаточно реализовать и передать его в конфиг:

```php
CaptchaManager::setConfig(['drivers.text.image' => 'imagick']);
```

См. также: `Captcha\Drivers\TextCaptchaDriver`, `Storage\Mimes\ImageMime`.
