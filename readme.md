# Symfony 8.1, Kafka и saga

Учебный модульный монолит с DDD на Symfony 8.1 и FrankenPHP.
Инфраструктура: PostgreSQL, Redis, Kafka, VictoriaMetrics и Grafana.
Приложение находится в `app/`; описание архитектуры — в [app/README.md](app/README.md).

## Окружение для разработки

Перед первым запуском создайте локальный `APP_SECRET` (существующий файл
эта команда не изменяет):

```bash
php -r 'if (!file_exists("app/.env.local")) { file_put_contents("app/.env.local", "APP_SECRET=".bin2hex(random_bytes(32)).PHP_EOL); }'
```

```bash
docker compose build app
docker compose run --rm --no-deps app composer install --no-interaction
docker compose up -d --build
docker compose exec app composer check
docker compose exec app php bin/console app:infrastructure:check
```

После изменений зависимостей снова выполните `composer install` в контейнере;
`composer.lock` включает Doctrine ORM 3.6.9 и его зависимости.

Compose поднимает FrankenPHP (PHP 8.5 с Composer и расширениями
`pdo_pgsql`, `redis`, `rdkafka`, `intl`, `mbstring`, `zip`, `opcache`, `pcntl`), PostgreSQL,
Redis, Kafka в режиме KRaft, VictoriaMetrics и Grafana.
Каталог `app/` монтируется в `/app`; FrankenPHP обслуживает `/app/public`.
После установки Composer проверка приложения доступна на
http://localhost:8099/health. Этот endpoint проверяет только запуск приложения;
доступность внешних сервисов проверяет команда `app:infrastructure:check`.

После первой установки сохраните `app/composer.lock` в Git.
Файл фиксирует выбранные версии зависимостей для последующих установок.

| Сервис | Адрес с хоста | Адрес внутри Docker |
| --- | --- | --- |
| Приложение | http://localhost:8099 | http://app:80 |
| PostgreSQL | localhost:5433 | postgres:5432 |
| Redis | localhost:6380 | redis:6379 |
| Kafka | localhost:29092 | kafka:9092 |
| VictoriaMetrics | http://localhost:8428 | http://victoriametrics:8428 |
| Grafana | http://localhost:3000 | http://grafana:3000 |

По умолчанию база, пользователь и пароль PostgreSQL — `app`.
Вход в Grafana — `admin` / `admin`. Порты и учётные данные можно
переопределить через переменные окружения, указанные в `docker-compose.yml`.
Все опубликованные порты доступны только с локального компьютера.
Данные сервисов сохраняются в именованных томах Docker.

VictoriaMetrics автоматически собирает собственные метрики.
Источник данных VictoriaMetrics уже настроен в Grafana;
метрики приложения и Kafka потребуют отдельного подключения.

Автоматическое создание топиков Kafka отключено. Пример создания топика:

```bash
docker compose exec kafka /opt/kafka/bin/kafka-topics.sh \
  --bootstrap-server kafka:9092 --create --if-not-exists \
  --topic saga.events --partitions 3 --replication-factor 1
```

## Модули и учебная saga

Реализованы Catalog, Order, Supply и Warehouse со слоями Domain, Application,
Infrastructure. Бизнес-репозитории используют Doctrine ORM; ORM-модели
и миграции находятся в Infrastructure каждого модуля. Каждый модуль имеет
свою схему PostgreSQL: `catalog`, `orders`, `supply`, `warehouse`;
схемы `saga` и `shared` содержат inbox и outbox.
Документация: [Catalog](docs/modules/catalog.md),
[Order](docs/modules/order.md), [Supply](docs/modules/supply.md),
[Warehouse](docs/modules/warehouse.md), [saga 1](docs/saga/saga-1.md).

После установки зависимостей и запуска инфраструктуры примените модульные
миграции и создайте топик командой выше. При наличии старых таблиц в `public`
миграции перенесут их с сохранением данных:

```bash
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec app composer test
docker compose exec app php bin/console app:saga:demo DEMO-1
```

Демо создаёт заказ на 10 изделий: 5 готовых есть на складе,
для остальных нужны по 5 единиц трёх материалов. Два материала доступны,
третий требуется заказать. Команда выводит ID заказа; повторный запуск
с тем же номером возвращает существующий заказ.

В двух отдельных терминалах запустите relay и потребителя:

```bash
docker compose exec app php bin/console app:outbox:publish --watch
```

```bash
docker compose exec app php bin/console app:saga:consume
```

Посмотрите заказ, подставив выведенный ID:

```bash
docker compose exec app php bin/console app:order:show <order-id>
```

После обработки начальных событий появятся одна заявка и один заказ поставщику.
Его ID находится в `supplyRequest.supplyOrderId`. Зафиксируйте выполнение:

```bash
docker compose exec app php bin/console app:supply:fulfill <supply-order-id>
docker compose exec app php bin/console app:order:show <order-id>
```

Workers примут и зарезервируют поступивший материал, завершат заявку
и переведут заказ в `in_production`. Это происходит асинхронно;
при необходимости повторите команду просмотра после обработки событий.
Изменять число партиций топика во время выполняющихся saga не следует.

Остановка с сохранением данных:

```bash
docker compose down
```
