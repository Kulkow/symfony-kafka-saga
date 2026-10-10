Product - Изделие
 id - int PK
 title - str
 price - int
 article - str - UNIQUE

Изделие - производится из материалов  c определенным кол-вом

ProductComposition
 id - int PK
 product_id -> Product.id
 material_id -> Material.id
 quantity - int


Material - материал
 id - int PK
 title - str
 measure_unit_id -> MeasureUnit.id

MeasureUnit - единица измерения
  id - int PK
  title - str UNIQUE

## Реализация DDD

Код: `app/src/Modules/Catalog/`.
`Product` — корень агрегата; состав принадлежит изделию и содержит уникальные
материалы с положительным количеством. `Material` и `MeasureUnit` — отдельные
корни каталога. Цена хранится целым числом в минимальных денежных единицах.

Публичный контракт для других модулей — `Application/Contract/CatalogReader`.
Он возвращает DTO изделия/материала и суммарную потребность в материалах
для заданного количества изделий. Состав нужен только для изделий,
которые действительно требуется произвести; складские готовые изделия его не требуют.
Пустой состав запрещает производство, повторение материала в составе запрещено.

Таблицы находятся в схеме `catalog`: `measure_units`, `materials`, `products`,
`product_compositions`. FK используются только внутри Catalog.
ORM-модели и репозитории: `Infrastructure/Persistence`,
модульные миграции: `Infrastructure/Migrations`.

