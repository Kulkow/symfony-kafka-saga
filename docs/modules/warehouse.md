Warehouse склад - тут находятся и Product и Material
  id - int PK
  title - str

WarehousePosition
    id - int PK
    type - int (Product - 1, Material - 2)
    quantity - int
    measure_unit_id -> MeasureUnit.id

WarehousePositionReserve
    id - int PK
    type - int (Product - 1, Material - 2)
    quantity - int
    measure_unit_id -> MeasureUnit.id
    order_id -> Order.id

## Реализация DDD

Код: `app/src/Modules/Warehouse/`.
`WarehousePosition` контролирует остаток и резерв; готовые изделия
и материалы различаются `StockType` (`1` и `2`). Публичный API —
`Application/WarehouseService` и контракт чтения `WarehouseDirectory`.

В складскую позицию добавлены необходимые `warehouse_id`, `item_id`
и `reserved_quantity`. Уникальность позиции: `(warehouse_id, type, item_id)`.
`quantity` — физический остаток; доступно `quantity - reserved_quantity`.
Резерв ссылается на `warehouse_position_id`, поэтому тип, изделие/материал
и единицу измерения получает из позиции без дублирования полей.

Для заказа сначала резервируются доступные готовые изделия. Состав
рассчитывается только для оставшихся изделий; доступные материалы
резервируются, недостающие попадают в план снабжения. Количества не могут
быть отрицательными; единица материала должна совпадать с Catalog.
План сохраняется в `warehouse.reservation_plans`, повторный вызов его не меняет.

Поставка недостающих материалов принимается и резервируется для исходного
заказа одной транзакцией. `warehouse.receipts` исключает повторное поступление
одного заказа поставщику. При изменениях блокируется строка склада в PostgreSQL;
это сериализует операции с запасами одного склада и предотвращает двойной резерв.

Таблицы находятся в схеме `warehouse`: `warehouses`, `positions`,
`position_reserves`, `reservation_plans`, `receipts`. Межмодульных FK нет.
ORM-модели и репозитории: `Infrastructure/Persistence`,
модульные миграции: `Infrastructure/Migrations`.
Резервы сохраняются до последующего процесса выдачи/производства;
списание и отмена заказа не входят в saga 1.
