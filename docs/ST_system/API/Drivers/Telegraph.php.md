# Telegraph

> Документация сгенерирована AI-агентом на основе исходного кода.

## 1. Концепция

Драйвер [Telegra.ph](https://telegra.ph) API. При первом создании автоматически регистрирует аккаунт и кэширует токен доступа **навсегда** (TTL=-1). Метод `content` принимает HTML-строку, `DOMDocument` или нативный массив нод Telegraph.

```php
$telegraph = Telegraph::create([
    'short_name'  => 'MyBlog',
    'author_name' => 'Иван Иванов',
    'base_url'    => 'https://example.com',
]);

$page = $telegraph->call('createPage', [
    'title'   => 'Моя статья',
    'content' => '<p>Текст статьи с <strong>форматированием</strong>.</p>',
]);
// $page['url'] — ссылка на созданную страницу

// Редактирование
$telegraph->call('editPage/my-article-01-01', [
    'title'   => 'Новый заголовок',
    'content' => '<p>Обновлённый текст.</p>',
]);

// Получение
$page = $telegraph->call('getPage/my-article-01-01', ['return_content' => true]);
```

## 2. Публичные методы

### `static create(array $PARAMS): static`

| Параметр | Описание |
|---|---|
| `short_name` | Имя аккаунта (обязательно) |
| `author_name` | Отображаемое имя автора |
| `author_url` | URL профиля автора (`nullable url`) |
| `base_url` | Базовый URL сайта (определяется автоматически из `$_SERVER`) |

### `call(string $method, array $params): mixed`

| Метод | Описание |
|---|---|
| `createAccount` | Создание нового аккаунта Telegraph. Вызывается автоматически при инициализации. |
| `createPage` | Создание новой страницы. `title` (обязательно), `content`, `author_name`, `author_url`, `return_content`. |
| `editPage/{path}` | Редактирование страницы. Параметры те же, плюс `path`. |
| `getPage/{path}` | Получение страницы. `return_content` — включить содержимое в ответ. |.php
