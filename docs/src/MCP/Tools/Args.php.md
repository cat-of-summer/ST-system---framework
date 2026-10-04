<!-- DOCGEN:START -->
# Args.php
<!-- DOCGEN:END -->

`namespace ST_system\MCP\Tools`

## Назначение

`Args` проверяет аргументы `tools/call`. Источник проверки — `inputSchema` инструмента,
JSON Schema и контракт для клиента. `Args::object($schema, $rules)` компилирует её в
`Rule::object`, и проверку выполняет `Rule`.

```php
$args = Args::validate($tool->inputSchema(), $arguments, $tool->rules());
// \InvalidArgumentException — модель получит «Неверные параметры: …»
```

## Что понимается из JSON Schema

| Ключевое слово | Правило |
|---|---|
| `required` | `required` (`null` и `''` — нет значения) |
| `default` | `Rule::default()`; подставляется, если параметра нет, он `null` или `''` |
| необязательный без `default` | нет значения или `null` — остальные правила пропускаются, ключа в результате нет |
| `type`: `string`, `integer`, `number`, `boolean`, `array`, `object`, список с `null` | проверка типа; `"20"` → `20`, `"0.5"` → `0.5`, `"true"` → `true`, `2.0` → `2` |
| `minLength`/`minimum`/`minItems`, `maxLength`/`maximum`/`maxItems` | `min:N` / `max:N` — `Rule` сам меряет длину строки, размер массива или величину числа |
| `enum` | строгое сравнение (`Rule::in` сравнивает нестрого) |
| `pattern` | `Rule::regex('/…/u')` |
| `items` | каждый элемент по своей схеме, ошибка с номером: `ids.1.…` |
| `properties` у вложенного `object` | вложенный `Rule::object` |
| `additionalProperties` | `false` или нет ключа — неизвестные параметры отклоняются до проверки, в том числе во вложенных объектах и в объектах-элементах массивов (`ftp.0.pasword`); `true`/схема — сохраняются как есть |

Остальные ключевые слова (`format`, `oneOf`, `exclusiveMinimum`…) не проверяются. Нужное из
них задаётся правилами `Rule`.

## Дополнительные правила

`Tool::rules()` / `->rules([...])` — правила `Rule` по именам параметров. Они добавляются к
скомпилированным в тот же пайплайн и сортируются по `order`: преобразования (`trim`)
выполнятся до проверки типа.

```php
['email' => 'trim|email', 'name' => ['trim', Rule::create(fn (&$v) => $v !== 'root')->handleError(fn () => 'имя занято')]]
```

Ключи — имена параметров верхнего уровня; dot-нотацию здесь не используют. Сообщения
встроенных правил `Rule` — английские (`This field is required`), проверок типов из схемы —
русские.
