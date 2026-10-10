SupplyRequest - заявка в снабжение
   id - int PK
   order_id -> Order.id
   date datetime
   status_id -> SupplyStatus.id

SupplyStatus (draft, in_work, delivering (Доставляется), delivered (Доставлен), completed)
    id - int PK
    title - string
    slug  - string UNIQUE

SupplyRequestMaterial
   id - int PK
   supply_request_id -> SupplyRequest.id
   material_id -> Material.id
   quantity - int

SupplyRequestStatusLog
    id - int PK
    supply_request_id -> SupplyRequest.id
    date datetime
    old_status_id -> SupplyStatus.id
    new_status_id -> SupplyStatus.id


SupplyOrder - заказ поставщику
    id - int PK
    supply_request_id -> SupplyRequest.id
    date datetime
    status_id -> SupplyOrderStatus.id

SupplyOrderStatus (draft, in_work, delivering (Доставляется), delivered (Доставлен), completed)
    id - int PK
    title - string
    slug  - string UNIQUE

SupplyOrderMaterial
    supply_order_id -> SupplyOrder.id
    material_id -> Material.id
    quantity - int

SupplyOrderStatusLog
    id - int PK
    supply_order_id -> SupplyOrder.id
    date datetime
    old_status_id -> SupplyOrderStatus.id
    new_status_id -> SupplyOrderStatus.id

## Реализация DDD

Код: `app/src/Modules/Supply/`.
`SupplyRequest` и `SupplyOrder` — отдельные агрегаты со своими позициями
и историей статусов. Публичный API — `Application/SupplyService`.
В заявку попадает только дефицит материалов после резервирования готовых
изделий и доступных материалов. `warehouse_id` заявки определяет склад поставки.

`UNIQUE(order_id)` обеспечивает одну заявку на заказ;
`UNIQUE(supply_request_id)` — один заказ поставщику на заявку.
Повторные вызовы создания возвращают существующую запись;
попытка изменить состав уже созданной заявки отклоняется.

Учебная команда `app:supply:fulfill` фиксирует выполнение поставщиком
всех этапов `draft → in_work → delivering → delivered → completed`.
Каждый переход записывается в журнал. Saga сначала принимает и резервирует
материалы на складе, затем завершает заявку. До выполнения поставщиком
завершить заявку нельзя.

Таблицы находятся в схеме `supply`: `requests`, `orders`, позиции, статусы
и журналы. ORM-модели и репозитории: `Infrastructure/Persistence`,
модульные миграции: `Infrastructure/Migrations`.
FK связывают только собственные агрегаты,
статусы и позиции Supply; материалы и клиентский заказ представлены ID.
