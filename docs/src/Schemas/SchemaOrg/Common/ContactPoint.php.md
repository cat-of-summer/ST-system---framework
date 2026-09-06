<!-- DOCGEN:START -->
# ContactPoint.php
<!-- DOCGEN:END -->

`ST_system\Schemas\SchemaOrg\Common\ContactPoint` — точка контакта организации
([schema.org/ContactPoint](https://schema.org/ContactPoint)): отдел продаж, поддержка,
пресс-служба. Вложенная схема, используется массивом в поле `contact_points` схемы
`../Organization.php`.

## Поля

- **`contact_type`** (обязательное) — назначение точки: `sales`, `customer support`,
  `technical support`, `billing support` и т.п.
- `telephone`, `email` (валидируется как e-mail), `url` — контакты, все необязательные.
- `area_served` — регион обслуживания (`"RU"`).
- `available_language` — язык или список языков; скаляр приводится к массиву.

## Вывод

```json
{
  "@type": "ContactPoint",
  "contactType": "sales",
  "telephone": "+7-495-933-06-03",
  "email": "sales@hevelsolar.com",
  "areaServed": "RU",
  "availableLanguage": ["Russian"]
}
```
