<!-- DOCGEN:START -->
# GdAdapter.php
<!-- DOCGEN:END -->

`ST_system\Captcha\Drivers\Image\GdAdapter` — отрисовка картинки для `TextCaptchaDriver` через
расширение GD. Гарантированный запасной вариант: GD есть практически везде, где нет Imagick.

`isAvailable()` требует `gd_info()`, `imagettftext()` (то есть GD, собранный с FreeType) и
поддержку PNG в `imagetypes()`.

## Конвейер отрисовки

1. Холст `imagecreatetruecolor()` с фоном `rgb(245,245,245)`.
2. Каждый символ рисуется `imagettftext()` **случайным шрифтом из набора**, со случайным углом
   (±24°) и тёмным случайным цветом. Вертикальное положение считается из метрик глифа
   (`imagettfbbox()`), поэтому текст центрируется независимо от шрифта и кегля.
3. Волновое искажение: изображение пересобирается поколоночно (`imagecopy()` по одному пикселю
   ширины) со сдвигом по синусоиде со случайной фазой.
4. Случайные линии `imageline()` поверх текста.
5. Точечный шум `imagesetpixel()` — доля пикселей задаётся `noise` в процентах (максимум 30).
6. `imagefilter(IMG_FILTER_CONTRAST, -$contrast)`.
7. PNG с максимальным сжатием возвращается бинарной строкой.

Кегль по умолчанию — 52 % высоты холста, но не более 80 %. Горизонтальный шаг равен
`(width - 12) / количество символов` плюс случайное дрожание ±2 пикселя.

Любая неудача (не создался холст, шрифт не отрисовал или не измерил глиф, PNG не
закодировался) — `\RuntimeException` с указанием проблемного шрифта.

По сравнению с `ImagickAdapter` искажения проще: волна вместо `waveImage`/`swirlImage`.
Читаемость и стойкость к распознаванию настраиваются ключами `distortion`, `noise`, `lines`,
`contrast` в конфиге `TextCaptchaDriver`.

См. также: `Captcha\Drivers\TextCaptchaDriver`,
`Captcha\Drivers\Image\ImageAdapterInterface`, `Captcha\Drivers\Image\ImagickAdapter`.
