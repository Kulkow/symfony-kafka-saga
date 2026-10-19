# Запуск тестов Wallet

Тесты используют PHPUnit 12 и конфигурацию `app/phpunit.xml.dist`.

| Набор | Что проверяет | Нужна PostgreSQL |
| --- | --- | --- |
| `Wallet Unit` | Домен Wallet и списание с mock-объектами | Нет |
| `Wallet Integration` | Репозиторий, списание и перевод через контейнер Symfony | Да |
| `Wallet Concurrency` | Параллельные списания и переводы с барьером Redis | Да, и Redis |

## Через Docker

Команды выполняются из корня репозитория. Запустите контейнеры и установите зависимости, включая dev-зависимости:

```sh
docker compose up -d app
docker compose exec app composer install
```

Unit-тесты:

```sh
docker compose exec app composer test:wallet
```

Перед запуском тестов с БД подготовьте тестовую базу (её содержимое будет сброшено):

```sh
docker compose exec app composer test:wallet:setup
```

Интеграционные тесты:

```sh
docker compose exec app composer test:wallet:integration
```

Тест конкурентного списания:

```sh
docker compose exec app composer test:wallet:concurrency
```

Все наборы Wallet:

```sh
docker compose exec app vendor/bin/phpunit --testsuite 'Wallet Unit,Wallet Integration,Wallet Concurrency'
```

Один класс или метод интеграционного теста:

```sh
docker compose exec app vendor/bin/phpunit --testsuite 'Wallet Integration' --filter DoctrineWalletRepositoryTest
docker compose exec app vendor/bin/phpunit --testsuite 'Wallet Integration' --filter testFailedTransactionRollsBackBalance
```

Docker Compose задаёт `WALLET_TEST_DATABASE_URL` автоматически: база называется `${POSTGRES_DB:-app}_test`. После изменения переменных в `docker-compose.yml` пересоздайте контейнер:

```sh
docker compose up -d --force-recreate app
```

Тестовый bootstrap также загружает `.env` и `.env.test`. В `.env.test` задан стандартный URL для Docker; переопределить его можно переменной окружения или в `app/.env.test.local`.

## Локально

Нужны PHP и расширения из `composer.json`, Composer и установленная dev-зависимость PHPUnit. Все команды ниже выполняются из каталога `app`:

```sh
cd app
composer install
composer test:wallet
```

Для интеграционных тестов и конкурентного списания запустите PostgreSQL. Например, из корня репозитория:

```sh
docker compose up -d postgres redis
```

Затем из каталога `app` задайте URL тестовой БД и запустите тесты:

```sh
export WALLET_TEST_DATABASE_URL='postgresql://app:app@127.0.0.1:5433/app_test'
composer test:wallet:setup
composer test:wallet:integration
REDIS_URL='redis://127.0.0.1:6380' composer test:wallet:concurrency
```

В примере указаны стандартные учётные данные и порт Docker Compose. При другой конфигурации замените их своими значениями.

Прямой запуск PHPUnit из каталога `app`:

```sh
# По умолчанию запускается только Wallet Unit.
vendor/bin/phpunit

vendor/bin/phpunit --testsuite 'Wallet Integration'
vendor/bin/phpunit --testsuite 'Wallet Concurrency'
vendor/bin/phpunit --testsuite 'Wallet Unit,Wallet Integration,Wallet Concurrency'
vendor/bin/phpunit --testsuite 'Wallet Integration' --filter DoctrineWalletRepositoryTest
```

## Тестовая база и окружение

Имя БД должно заканчиваться на `_test` и отличаться от базы в `DATABASE_URL`. При отсутствии `WALLET_TEST_DATABASE_URL` тесты с БД завершаются ошибкой.

Команда `test:wallet:setup` вызывает общую команду из `Shared`:

```sh
# Из каталога app:
php bin/console test:database:prepare --env=test --no-debug
# Или через Composer:
composer test:database:prepare
```

Она создаёт `test_template`, если шаблона ещё нет, применяет к нему недостающие миграции всех модулей и `Shared` из `config/packages/doctrine_migrations.yaml`, затем пересоздаёт БД из `WALLET_TEST_DATABASE_URL` через `CREATE DATABASE … TEMPLATE test_template`. Повторный запуск обновляет шаблон и сбрасывает тестовую БД до его состояния. Рабочая БД не изменяется. При ошибке миграции существующая тестовая БД сохраняется.

Пользователю PostgreSQL требуется право `CREATEDB` и владение тестовой БД для её пересоздания. Шаблон и тестовая БД создаются на одном сервере PostgreSQL. Подготовку запускайте до тестов: активные подключения к тестовой БД или шаблону препятствуют пересозданию; команда не завершает их принудительно. Параллельные запуски подготовки сериализуются блокировкой PostgreSQL.

Миграции вручную запускать не требуется. Тесты Wallet дополнительно создают отдельную схему `wallet_test_*` и удаляют её после завершения; для этого нужны права на создание схем.

Интеграционные тесты наследуют `KernelTestCase` через `DatabaseTestCase`, запускают ядро в окружении `test` и получают сервисы Wallet из контейнера. Подключение берётся из `WALLET_TEST_DATABASE_URL` через `config/packages/test/doctrine.yaml`.

Тест конкурентного списания наследует `KernelTestCase` через `DatabaseTestCase`. Он запускает два независимых процесса команды `bin/console test:wallet:withdraw --env=test` через Symfony `Process`. Команда `TestWithdrawCommand` зарегистрирована только в тестовом окружении и получает сервисы из контейнера Symfony. Каждый читает начальный баланс 100 и сообщает о готовности через Redis. Когда оба достигли барьера, основной процесс одновременно разрешает им списать по 80. Проверяются одно успешное списание, один отказ из-за недостатка средств и итоговый баланс 20.

`ConcurrentTransferTest` запускает два процесса команды `test:wallet:transfer` с тем же барьером Redis. Проверяются переводы с общим отправителем (один успех и один отказ при недостатке средств), встречные переводы и переводы общему получателю. Каждый процесс предварительно читает оба кошелька; тест проверяет остатки каждого кошелька и сохранение общей суммы. В use case кошельки блокируются в одинаковом порядке по ID, чтобы встречные переводы не создавали взаимную блокировку.

Запуск только конкурентных тестов перевода:

```sh
docker compose exec app vendor/bin/phpunit --testsuite 'Wallet Concurrency' --filter ConcurrentTransferTest
```

Redis уже есть в Docker Compose (`redis:8-alpine`), PHP-расширение `redis` установлено в образе app. В Docker используется `REDIS_URL=redis://redis:6379`, локально — `redis://127.0.0.1:6380` при стандартных портах. Ключи барьера уникальны для тестовой схемы, имеют TTL и удаляются после теста. Ожидание барьера и время работы процессов ограничены. Функция `proc_open` должна быть доступна.

Проверки перевода:

| Метод | Сценарий |
| --- | --- |
| `testConcurrentTransfers` | Два параллельных перевода общему получателю сохраняют деньги |
| `testTransferWaitsForLockedWallet` | PostgreSQL подтверждает ожидание `FOR UPDATE`; после освобождения блокировки перевод завершается |
| `testOppositeTransfersDoNotDeadlock` | Переводы A → B и B → A завершаются без deadlock |
| `testInsufficientFundsUnderConcurrency` | Из двух переводов по 80 с баланса 100 проходит только один |
| `testTransferRollback` | Ошибка сохранения получателя после списания откатывает оба баланса |

Первые четыре метода находятся в `ConcurrentTransferTest`, проверка отката — в `TransferMoneyIntegrationTest`.
