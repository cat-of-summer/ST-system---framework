# JsonMime.php

## 1. Концепция

MIME-сервис для `application/json`. Автоматически сериализует/десериализует при записи/чтении через `Cache`.

```php
$json = File::make('~/data/config.json');
$data = $json->getContents(); // десериализует JSON
echo $json->toHTML();         // <script type='application/json'>...</script>
```

## 2. Публичные методы

### `get(mixed $data): mixed`
`json_decode($data, true)` — десериализация при чтении из кэша.

### `set(mixed $data, int &$flags = 0): mixed`
`json_encode($data)` — сериализация при записи в кэш.

### `toHTML(array $config = []): string`
`<script type='application/json'>...</script>`.