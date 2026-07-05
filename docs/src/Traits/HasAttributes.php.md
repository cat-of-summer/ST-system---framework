# HasAttributes.php

## 1. Концепция

`HasAttributes` — **трейт динамического доступа к атрибутам** через магические методы `__get` / `__set`.

Ключевые идеи:

1. **Защищённый массив `$attributes`.** Всё состояние объекта хранится в одном массиве `protected array $attributes = []`. Тип и ключи определяются конкретным классом.

2. **Аксессоры и мутаторы (accessor / mutator pattern).** Если в классе определён метод `getFooAttribute()` — он будет вызван при обращении к `$obj->foo`. Аналогично `setFooAttribute($value)` — при записи. Это позволяет добавлять ленивые вычисления, каст типов и защиту без изменения публичного API.

3. **Fallback на массив.** Если акссесор не определён — значение берётся напрямую из `$this->attributes[$name]`, или возвращается `null`.

**Когда использовать:**
- В классах-сущностях (ORM-подобные модели, DTO), где нужна чистая инкапсуляция.
- Когда нужна возможность перехватить чтение/запись конкретных полей через методы.

```php
// Пример класса использующего трейт
class User {
    use \ST_system\Traits\HasAttributes;

    // Мутатор — автоматически хэширует пароль при записи
    protected function setPasswordAttribute(string $value): void {
        $this->attributes['password'] = password_hash($value, PASSWORD_BCRYPT);
    }

    // Акссесор — возвращает имя в uppercase
    protected function getNameAttribute(): string {
        return strtoupper($this->attributes['name'] ?? '');
    }
}

$user = new User();
$user->name = 'ivan';      // напрямую в $attributes['name']
$user->password = 'qwerty'; // вызовет setPasswordAttribute()

echo $user->name;           // вызовет getNameAttribute() → 'IVAN'
echo $user->missing;        // null (ключ не существует)
```

---

## 2. Публичные методы

### `__get(string $name): mixed`

Вызывается при чтении недоступного свойства.

| Параметр | Тип | Описание |
|----------|-----|----------|
| `$name` | `string` | Имя свойства |

**Логика:**
1. Если существует метод `get{Name}Attribute()` — вызвать и вернуть результат.
2. Иначе — вернуть `$this->attributes[$name] ?? null`.

```php
$value = $obj->someField; // эквивалент $obj->__get('someField')
```

---

### `__set(string $name, mixed $value): void`

Вызывается при записи в недоступное свойство.

| Параметр | Тип | Описание |
|----------|-----|----------|
| `$name` | `string` | Имя свойства |
| `$value` | `mixed` | Значение для записи |

**Логика:**
1. Если существует метод `set{Name}Attribute($value)` — вызвать его.
2. Иначе — записать в `$this->attributes[$name]`.

```php
$obj->someField = 'value'; // эквивалент $obj->__set('someField', 'value')
```

---

## 3. Паттерн аксессоров/мутаторов

| PHP-метод | Вызывается при | Пример |
|-----------|----------------|--------|
| `get{Name}Attribute(): mixed` | чтении `$obj->name` | `getCreatedAtAttribute()` |
| `set{Name}Attribute($v): void` | записи `$obj->name = $v` | `setEmailAttribute(string $v)` |

Имя метода формируется через `ucfirst($name)`: свойство `createdAt` → метод `getCreatedAtAttribute()`.

> **Важно:** аксессоры и мутаторы должны быть `protected` или `public` — `private` не будет найден через `method_exists`.
