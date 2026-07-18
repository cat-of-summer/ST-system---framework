<!-- DOCGEN:START -->
# Traits

## Файлы

- [HasMime.php](HasMime.php.md)

<!-- DOCGEN:END -->

Примеси, специфичные для хранилища, — в отличие от общефреймворковых из [`src/Traits`](../../Traits/).

- **`HasMime`** — общий для `File` и `HTTP\UploadedFile` шаг определения MIME по содержимому
  файла (`finfo` с кешированным хендлом, фолбэк на `mime_content_type`). Оба класса сначала
  спрашивают `Resource::getMime()` (override → таблица `mimes.extensions`) и обращаются к
  трейту только когда там пусто.
