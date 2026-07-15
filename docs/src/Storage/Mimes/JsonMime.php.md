<!-- DOCGEN:START -->
# JsonMime.php
<!-- DOCGEN:END -->

`ST_system\Storage\Mimes\JsonMime` — MIME-обработчик для контента типа `application/json`. Наследует `Mime` (см. `docs/src/Storage/Mimes/Mime.php.md` за общим контрактом и тем, как обработчик подбирается `Resource`/`File`). Единственная задача — конвертировать между сырым JSON-текстом и PHP-массивом.

## Публичные методы

- **`get($data)`** — декодирует JSON-строку в PHP-значение: `@json_decode($data, true)` (ассоциативные массивы вместо `stdClass`, ошибки декодирования подавлены — на некорректном JSON вернёт `null`).
- **`set($data, int &$flags = 0)`** — кодирует переданное значение обратно в JSON-строку: `@json_encode($data)`.
- **`toHTML(array $config = []): string`** — оборачивает сырое содержимое файла в `<script type='application/json'>...</script>` (сырой текст, не переданный `$data`/декодированное значение).

## Пример использования (через `File`/`Resource`)

```php
$file = \ST_system\Storage\File::make('config.json');

$data = $file->get();          // -> JsonMime::get($file->getRaw()), PHP-массив
$file->set(['a' => 1]);        // -> JsonMime::set([...]), пишет JSON-строку через putContents()
echo $file->toHTML();          // <script type='application/json'>{...}</script>
```
