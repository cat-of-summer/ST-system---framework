<!-- DOCGEN:START -->
# ImagickAdapter.php
<!-- DOCGEN:END -->

`ST_system\Captcha\Drivers\Image\ImagickAdapter` — отрисовка картинки для `TextCaptchaDriver`
через расширение Imagick. Предпочтительный бэкенд, когда он доступен: искажения качественнее,
чем поколоночная волна в GD.

`isAvailable()` требует классы `Imagick` и `ImagickDraw` и поддержку PNG в
`Imagick::queryFormats('PNG*')`.

## Конвейер отрисовки

1. `newImage()` с фоном `rgb(245,245,245)`.
2. Каждый символ рисуется `annotateImage()` **случайным шрифтом из набора**, со случайным углом
   (±24°) и тёмным случайным цветом. Вертикальное центрирование — через
   `ImagickDraw::setGravity(Imagick::GRAVITY_WEST)`, поэтому метрики глифов считать не нужно.
3. `waveImage()` с виртуальными пикселями `VIRTUALPIXELMETHOD_BACKGROUND`; волна увеличивает
   высоту, поэтому результат обрезается обратно `cropImage()` + `setImagePage()`.
4. Случайные линии через `ImagickDraw::line()`.
5. Точечный шум `ImagickDraw::point()` — доля пикселей задаётся `noise` в процентах
   (максимум 30). Встроенный `addNoiseImage()` намеренно не используется: он неуправляем по
   плотности и съедает глифы.
6. `contrastImage(false)` повторяется `contrast / 10` раз.
7. PNG возвращается через `getImageBlob()`; ресурсы освобождаются в `finally`.

Ключи `distortion`, `noise`, `lines`, `contrast` совпадают по смыслу с `GdAdapter`, так что
конфиг `TextCaptchaDriver` переносится между бэкендами без изменений.

Выбор адаптера — `ImageMime::getImageDriver()` (Imagick приоритетнее) либо явно
`['image' => 'imagick']`. Если Imagick собран без FreeType и не может подставить шрифт,
`render()` бросит исключение — в этом случае форсируйте GD ключом `image`.

См. также: `Captcha\Drivers\TextCaptchaDriver`,
`Captcha\Drivers\Image\ImageAdapterInterface`, `Captcha\Drivers\Image\GdAdapter`.
