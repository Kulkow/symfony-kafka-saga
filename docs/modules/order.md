Order заказ клиента
  id - int PK
  date - datatime
  number - str UNIQUE

OrderStatus (new, in_work, in_production, producted, completed)
  id - int PK
  title - string
  slug  - string UNIQUE

OrderSupplyStatus (draft, in_work, delivering (Доставляется), delivered (Доставлен), completed)
    id - int PK
    title - string
    slug  - string UNIQUE


OrderPosition
    order_id -> Order.id
    product_id -> Product.id
    quantity - int

## Реализация DDD

Код: `app/src/Modules/Order/`.
`Order` — корень агрегата, `OrderPosition` принадлежит заказу.
Статусы представлены enum; переходы контролирует агрегат.
Публичный API — `Application/OrderService`, результат чтения — DTO `OrderData`.

Для saga добавлены `warehouse_id`, `status_id`, `supply_status_id` в заказ
и `reserved_quantity` в позицию. Количество для производства:
`quantity - reserved_quantity`, где резерв относится к готовым изделиям.

После распределения запасов заказ переходит в `in_work`.
Если есть дефицит материалов, производство ждёт завершения снабжения;
если дефицита нет, начинается сразу. Если все изделия были на складе,
заказ сразу готов (`producted`), производство и снабжение не создаются.
Slug `producted` сохранён из исходной спецификации.

Таблицы находятся в схеме `orders`: `orders`, `positions`, `statuses`,
`supply_statuses`. ORM-модели и репозитории: `Infrastructure/Persistence`,
модульные миграции: `Infrastructure/Migrations`.
Ссылки на изделия и склад — идентификаторы
других модулей; их проверяет Application через публичные контракты.
