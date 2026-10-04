<!-- DOCGEN:START -->
# Forms.php
<!-- DOCGEN:END -->

`namespace ST_system\MCP\Elicitation`

## Назначение

`Forms` — схемы форм для elicitation (плоский объект из примитивных полей) под версию
протокола клиента. Экземпляр под нужную версию даёт `$ctx->forms()`.

| Метод | Форма |
|---|---|
| `choice($field, $title, ['value' => 'Подпись', …], $description = '')` | выбор варианта. В 2025-11-25 — `oneOf [{const, title}]`, в более ранних — `enum` + `enumNames` |
| `confirm($field, $title, $description = '')` | поле boolean: клиенты показывают его кнопками «Да/Нет» |
| `text($field, $title, $description = '', ?$default = null, $required = true)` | строка |

## Forms::match()

Клиент без кнопок присылает не значение варианта, а то, что напечатал человек: «Да», «2»,
«поправить». `Forms::match($raw, $options, $aliases = [])` ищет вариант:
1. по значению;
2. по номеру варианта;
3. по подписи;
4. по корням слов из `$aliases`. Корень ищется с начала слова, поэтому «нет» не найдётся в «интернет».

Если ничего не подошло, возвращается `null`. Булево значение сравнивается как `'true'`/`'false'`.
