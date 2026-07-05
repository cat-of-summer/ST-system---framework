# TextPlainMime.php

## 1. Концепция

MIME-сервис для `text/plain`. `toHTML()` возвращает сырое содержимое файла.

## 2. Публичные методы

### `toHTML(array $config = []): string`
Возвращает `$this->file->getContents()`.