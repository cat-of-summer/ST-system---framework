<!-- DOCGEN:START -->
# ImageSources.php
<!-- DOCGEN:END -->

`ST_system\Captcha\Drivers\Game\ImageSources` - trait игр, которые рисуют пользовательские
картинки: [пазла](Puzzle/PuzzleCaptchaDriver.php.md) и [Memory](Memory/MemoryCaptchaDriver.php.md).

Ключи конфига драйвера:

- `images` - `File`, путь к файлу или каталогу (обходится рекурсивно), URL - по одному или списком.
  Относительный путь считается от корня проекта (`~/`), URL скачивается и кешируется через
  `File::fetch()`;
- `extensions` - расширения, которые берутся из каталогов (регистр не важен);
- `max_images` - сколько картинок найти, не больше.

Методы: `resolveImages($value)` - список `File`, `local(File)` - локальный путь (URL скачивается),
`dataUri($bytes)` - картинка как `data:` URI с типом по сигнатуре (JPEG, WebP, иначе PNG).
