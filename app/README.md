# Symfony 8.1: модульный монолит

Модули `Catalog`, `Order`, `Supply`, `Warehouse` реализуют учебную saga
создания заказа и снабжения производства. `Saga` координирует процесс.
Бизнес-спецификация и уточнения реализации — в [docs](../docs/).

## Структура

```text
src/Modules/<Module>/
  Domain/          # агрегаты, сущности, инварианты, статусы, интерфейсы репозиториев
  Application/     # сценарии и публичные API; Contract/ содержит DTO и контракты чтения
  Infrastructure/
    Persistence/   # ORM-репозитории и Entity/ с отдельными ORM-моделями
    Migrations/    # миграции только собственной схемы PostgreSQL
    Console/       # CLI
src/Shared/
  Domain/          # проверки инвариантов, агрегат и конверт события
  Application/     # порты транзакций, идентификаторов и публикации событий
  Infrastructure/  # ORM-транзакции, PostgreSQL, outbox relay, Kafka и HTTP health check
```

Domain не зависит от Symfony, Doctrine, Redis, Kafka и доменов других модулей.
Модули вызывают публичные Application API и передают DTO/ID; каждый репозиторий
работает только со своими таблицами. Межмодульных FK нет.
У Catalog агрегаты Product, Material, MeasureUnit; у Order — Order;
у Supply — SupplyRequest и SupplyOrder; склад контролирует позиции и резервы.
Цена — целое число в минимальных денежных единицах, количество — целое положительное
число. Конвертация единиц и дробные количества не предусмотрены исходной моделью.

## Saga 1

Сначала резервируются готовые изделия, затем материалы только для недостающих
изделий. Одна заявка и один заказ поставщику покрывают только дефицит материалов.
После поставки материалы принимаются и резервируются, заявка завершается,
заказ переходит в производство. Если все изделия уже на складе, он сразу готов.
Сценарий и события: [saga-1](../docs/saga/saga-1.md).

Каждый сценарий Application выполняется в транзакции. PostgreSQL блокирует
заказ/заявку при изменении и склад перед операциями с запасами.
События записываются в outbox той же транзакцией; relay публикует их в Kafka.
Доставка допускает повторы; inbox исключает повторную обработку event ID
в consumer group. Статусы и уникальные ограничения дополнительно защищают процесс.

Kafka используется через native `rdkafka`. Это отдельный адаптер;
синхронные Messenger `command.bus`/`event.bus` сохранены для будущих локальных обработчиков.
Redis предоставляет кеш и LockFactory; согласованность текущего резерва обеспечивают
транзакции и блокировки PostgreSQL.

## Проверки

```bash
composer test
composer check
php bin/console app:infrastructure:check
```

`composer test` выполняет бизнес-сценарии и репозитории на SQLite in-memory
с тестовым генератором ID, без Kafka/Redis/PostgreSQL. Проверяются смешанные
остатки, дефицит, повторная доставка, откат транзакций, статусы и границы DDD.
`composer check` проверяет YAML, DI-контейнер и прогрев кеша.
Реальная доставка через Kafka проверяется запуском workers и демо в корневом README.

## ORM и схемы PostgreSQL

| Модуль | Схема | Миграции |
| --- | --- | --- |
| Catalog | `catalog` | `src/Modules/Catalog/Infrastructure/Migrations` |
| Order | `orders` | `src/Modules/Order/Infrastructure/Migrations` |
| Supply | `supply` | `src/Modules/Supply/Infrastructure/Migrations` |
| Warehouse | `warehouse` | `src/Modules/Warehouse/Infrastructure/Migrations` |
| Saga | `saga` | `src/Modules/Saga/Infrastructure/Migrations` |
| Shared | `shared` | `src/Shared/Infrastructure/Migrations` |

Таблицы именуются внутри схемы: `catalog.products`, `orders.orders`,
`supply.requests`, `warehouse.positions`, `saga.inbox`, `shared.outbox`.
Order использует `orders`, поскольку `ORDER` — ключевое слово SQL.

Бизнес-репозитории используют Doctrine ORM и переводят ORM-модели
из `Infrastructure/Persistence/Entity` в чистые агрегаты Domain и обратно.
ORM attributes находятся только в Infrastructure. Ссылки на другие агрегаты
представлены идентификаторами; необходимые FK внутри модуля создаёт миграция.
ID корня выделяется из PostgreSQL sequence до создания агрегата и назначается
ORM-модели явно. ID внутренних строк состава/журналов генерирует ORM.
После внешней транзакции Unit of Work очищается; при rollback очищается
и управляемое состояние. Inbox/outbox используют DBAL для атомарного claim
и блокировки публикации на том же соединении и в той же транзакции ORM.

Каждая миграция модуля напрямую наследует `Doctrine\\Migrations\\AbstractMigration`
и создаёт таблицы только в своей схеме. История прежней версии `DoctrineMigrations\\Version20261010000100` сохранена
неактивным legacy marker в Shared; он не создаёт бизнес-таблиц.
Общий журнал версий остаётся в `public.doctrine_migration_versions`.

Сортировка миграций идёт по `VersionYYYYMMDDHHMMSS`, независимо от namespace.
Для новых изменений создавайте миграцию в namespace нужного модуля:

```bash
php bin/console doctrine:migrations:generate --namespace='App\Modules\Catalog\Infrastructure\Migrations'
```

SQL новой миграции должен обращаться к собственной схеме. Проверка
`doctrine:schema:validate --skip-sync` проверяет ORM mapping; структуру и ограничения
БД контролируют модульные миграции.

Saga 1 заканчивается на `in_production` (либо готовом складском заказе).
Отмена, компенсации, списание материалов при производстве, автоматический DLQ
и бизнес-метрики добавляются отдельными сценариями. VictoriaMetrics/Grafana
уже доступны в Compose.
