<!-- DOCGEN:START -->
# HasMime.php
<!-- DOCGEN:END -->

`namespace ST_system\Storage\Traits`

## Назначение

Общая для [[File.php]] и [[UploadedFile.php]] часть определения MIME — **чтение типа с диска**.
Оба класса резолвят MIME по одной и той же схеме:

1. `Resource::getMime()` — явный `mime_override`, затем таблица типов `mimes.extensions`;
2. *(только `File`)* content-type из HTTP-метаданных, если ресурс — URI или получен из URI;
3. **общий шаг** — определить тип по содержимому файла.

Различаются только промежуточные шаги, поэтому в трейт вынесен именно последний.

## `detectMime()`

```php
protected function detectMime(string $path): string
```

- Возвращает `''`, если путь пуст или файла нет — вызывающему не нужно проверять это самому.
- Пробует `finfo_open(FILEINFO_MIME_TYPE)` + `finfo_file()`; хендл `finfo` кешируется в
  статической переменной, поэтому переоткрытия на каждый вызов нет.
- Если `finfo` недоступен или ничего не дал — фолбэк на `mime_content_type()`.
- Ошибки подавляются (`@`): недоступный/битый файл даёт `''`, а не warning.

### Запоминание значения

Результат кладётся в `private array $detected_mime` — **свойство инстанса**, ключ — `путь:mtime`.
Повторные `getMime()` на том же объекте файл не перечитывают.

Ключ решает обе задачи инвалидации: `mtime` снимает запись при перезаписи содержимого
(`File::putContents()`), путь — при переносе файла (`UploadedFile::save()`).

> Важно, почему это **не** `static` внутри метода: статическая переменная метода общая для всех
> инстансов класса, поэтому первый вычисленный тип возвращался бы для всех последующих файлов.
> Кеш «на файл» обязан жить на объекте (или быть статическим массивом с ключом по пути).
> Статическим здесь остаётся только хендл `finfo` — он действительно один на процесс.

## Использование

```php
use ST_system\Storage\Traits\HasMime;

class Whatever extends Resource {

    use HasMime;

    public function getMime(): string {
        $mime = parent::getMime();          // override → таблица типов
        if ($mime !== '') return $mime;

        return $this->detectMime($this->path);
    }
}
```

Так это и сделано в [[File.php]] (`detectMime($this->getPathname())`, после веток для URI) и в
[[UploadedFile.php]] (`detectMime($this->getPath())` — временный файл загрузки).

## Связанные страницы

[[File.php]] · [[Resource.php]] · [[UploadedFile.php]]
