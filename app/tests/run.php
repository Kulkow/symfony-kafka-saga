<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use App\Modules\Catalog\Application\CatalogService;
use App\Modules\Catalog\Domain\{Product, ProductComposition};
use App\Modules\Catalog\Infrastructure\Persistence\{OrmMaterialRepository, OrmMeasureUnitRepository, OrmProductRepository};
use App\Modules\Order\Application\OrderService;
use App\Modules\Order\Domain\{Order, OrderStatus};
use App\Modules\Order\Infrastructure\Persistence\OrmOrderRepository;
use App\Modules\Saga\Application\ProductionSaga;
use App\Modules\Saga\Infrastructure\Messaging\ProductionSagaProcessor;
use App\Modules\Supply\Application\SupplyService;
use App\Modules\Supply\Domain\SupplyStatus;
use App\Modules\Supply\Infrastructure\Persistence\{OrmSupplyOrderRepository, OrmSupplyRequestRepository};
use App\Modules\Warehouse\Application\WarehouseService;
use App\Modules\Warehouse\Domain\{StockType, WarehousePosition};
use App\Modules\Warehouse\Infrastructure\Persistence\{OrmReservationPlanRepository, OrmWarehouseRepository};
use App\Shared\Application\IdentityGenerator;
use App\Shared\Application\EventSender;
use App\Shared\Domain\{Assert, DomainEvent, DomainViolation};
use App\Shared\Infrastructure\Messaging\DbalOutboxEventPublisher;
use App\Shared\Infrastructure\Messaging\OutboxRelay;
use App\Shared\Infrastructure\Persistence\OrmTransactionManager;
use Doctrine\DBAL\{Connection, DriverManager};
use Doctrine\ORM\{EntityManager, ORMSetup};
use App\Shared\Infrastructure\Migrations\Version20261010000200 as SharedMigration;
use App\Modules\Catalog\Infrastructure\Migrations\Version20261010000300 as CatalogMigration;
use App\Modules\Order\Infrastructure\Migrations\Version20261010000400 as OrderMigration;
use App\Modules\Supply\Infrastructure\Migrations\Version20261010000500 as SupplyMigration;
use App\Modules\Warehouse\Infrastructure\Migrations\Version20261010000600 as WarehouseMigration;
use App\Modules\Saga\Infrastructure\Migrations\Version20261010000700 as SagaMigration;
const MODULE_MIGRATIONS = [CatalogMigration::class, OrderMigration::class, SupplyMigration::class, WarehouseMigration::class, SagaMigration::class];
const MIGRATIONS = [SharedMigration::class, ...MODULE_MIGRATIONS];

if (!class_exists(EntityManager::class)) {
    fwrite(STDERR, "Doctrine ORM is not installed. Run composer install in the app container.\n");
    exit(1);
}


function same(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected '.var_export($expected, true).', got '.var_export($actual, true));
    }
}
function fails(callable $operation, string $type = DomainViolation::class): void
{
    try { $operation(); } catch (Throwable $error) {
        if (!$error instanceof $type) { throw $error; }
        return;
    }
    throw new RuntimeException('Expected exception '.$type);
}

function createTestSchema(Connection $db): void
{
    $schemas = [
        'shared' => [
            'CREATE TABLE outbox (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, event_id VARCHAR(32) NOT NULL, partition_key VARCHAR(255) NOT NULL, payload CLOB NOT NULL, created_at DATETIME NOT NULL, published_at DATETIME DEFAULT NULL)',
            'CREATE UNIQUE INDEX outbox_event_id_uniq ON outbox (event_id)',
            'CREATE INDEX outbox_unpublished_idx ON outbox (published_at, id)',
        ],
        'catalog' => [
            'CREATE TABLE measure_units (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, title VARCHAR(255) NOT NULL)',
            'CREATE UNIQUE INDEX measure_units_title_uniq ON measure_units (title)',
            'CREATE TABLE materials (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, title VARCHAR(255) NOT NULL, measure_unit_id BIGINT NOT NULL, FOREIGN KEY (measure_unit_id) REFERENCES measure_units (id))',
            'CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, title VARCHAR(255) NOT NULL, price BIGINT NOT NULL, article VARCHAR(255) NOT NULL)',
            'CREATE UNIQUE INDEX products_article_uniq ON products (article)',
            'CREATE TABLE product_compositions (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, product_id BIGINT NOT NULL, material_id BIGINT NOT NULL, quantity BIGINT NOT NULL, FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE, FOREIGN KEY (material_id) REFERENCES materials (id))',
            'CREATE UNIQUE INDEX product_compositions_uniq ON product_compositions (product_id, material_id)',
        ],
        'orders' => [
            'CREATE TABLE statuses (id INTEGER NOT NULL PRIMARY KEY, title VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL UNIQUE)',
            'CREATE TABLE supply_statuses (id INTEGER NOT NULL PRIMARY KEY, title VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL UNIQUE)',
            'CREATE TABLE orders (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, date DATETIME NOT NULL, number VARCHAR(255) NOT NULL UNIQUE, warehouse_id BIGINT NOT NULL, status_id INTEGER NOT NULL, supply_status_id INTEGER NOT NULL, FOREIGN KEY (status_id) REFERENCES statuses (id), FOREIGN KEY (supply_status_id) REFERENCES supply_statuses (id))',
            'CREATE TABLE positions (order_id BIGINT NOT NULL, product_id BIGINT NOT NULL, quantity BIGINT NOT NULL, reserved_quantity BIGINT NOT NULL, PRIMARY KEY (order_id, product_id), FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE)',
        ],
        'supply' => [
            'CREATE TABLE statuses (id INTEGER NOT NULL PRIMARY KEY, title VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL UNIQUE)',
            'CREATE TABLE order_statuses (id INTEGER NOT NULL PRIMARY KEY, title VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL UNIQUE)',
            'CREATE TABLE requests (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, order_id BIGINT NOT NULL UNIQUE, warehouse_id BIGINT NOT NULL, date DATETIME NOT NULL, status_id INTEGER NOT NULL, FOREIGN KEY (status_id) REFERENCES statuses (id))',
            'CREATE TABLE request_materials (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, supply_request_id BIGINT NOT NULL, material_id BIGINT NOT NULL, quantity BIGINT NOT NULL, UNIQUE (supply_request_id, material_id), FOREIGN KEY (supply_request_id) REFERENCES requests (id) ON DELETE CASCADE)',
            'CREATE TABLE orders (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, supply_request_id BIGINT NOT NULL UNIQUE, order_id BIGINT NOT NULL, date DATETIME NOT NULL, status_id INTEGER NOT NULL, FOREIGN KEY (supply_request_id) REFERENCES requests (id), FOREIGN KEY (status_id) REFERENCES order_statuses (id))',
            'CREATE TABLE order_materials (supply_order_id BIGINT NOT NULL, material_id BIGINT NOT NULL, quantity BIGINT NOT NULL, PRIMARY KEY (supply_order_id, material_id), FOREIGN KEY (supply_order_id) REFERENCES orders (id) ON DELETE CASCADE)',
            'CREATE TABLE request_status_logs (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, supply_request_id BIGINT NOT NULL, version INTEGER NOT NULL, date DATETIME NOT NULL, old_status_id INTEGER NOT NULL, new_status_id INTEGER NOT NULL, UNIQUE (supply_request_id, version), FOREIGN KEY (supply_request_id) REFERENCES requests (id) ON DELETE CASCADE, FOREIGN KEY (old_status_id) REFERENCES statuses (id), FOREIGN KEY (new_status_id) REFERENCES statuses (id))',
            'CREATE TABLE order_status_logs (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, supply_order_id BIGINT NOT NULL, version INTEGER NOT NULL, date DATETIME NOT NULL, old_status_id INTEGER NOT NULL, new_status_id INTEGER NOT NULL, UNIQUE (supply_order_id, version), FOREIGN KEY (supply_order_id) REFERENCES orders (id) ON DELETE CASCADE, FOREIGN KEY (old_status_id) REFERENCES order_statuses (id), FOREIGN KEY (new_status_id) REFERENCES order_statuses (id))',
        ],
        'warehouse' => [
            'CREATE TABLE warehouses (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, title VARCHAR(255) NOT NULL)',
            'CREATE TABLE positions (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, warehouse_id BIGINT NOT NULL, type INTEGER NOT NULL, item_id BIGINT NOT NULL, quantity BIGINT NOT NULL, reserved_quantity BIGINT NOT NULL, measure_unit_id BIGINT NOT NULL, UNIQUE (warehouse_id, type, item_id), FOREIGN KEY (warehouse_id) REFERENCES warehouses (id))',
            'CREATE TABLE position_reserves (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, warehouse_position_id BIGINT NOT NULL, quantity BIGINT NOT NULL, order_id BIGINT NOT NULL, UNIQUE (order_id, warehouse_position_id), FOREIGN KEY (warehouse_position_id) REFERENCES positions (id))',
            'CREATE TABLE reservation_plans (order_id BIGINT NOT NULL PRIMARY KEY, warehouse_id BIGINT NOT NULL, plan CLOB NOT NULL, FOREIGN KEY (warehouse_id) REFERENCES warehouses (id))',
            'CREATE TABLE receipts (supply_order_id BIGINT NOT NULL PRIMARY KEY, order_id BIGINT NOT NULL, FOREIGN KEY (order_id) REFERENCES reservation_plans (order_id))',
        ],
        'saga' => [
            'CREATE TABLE inbox (consumer VARCHAR(255) NOT NULL, event_id VARCHAR(32) NOT NULL, processed_at DATETIME NOT NULL, PRIMARY KEY (consumer, event_id))',
        ],
    ];

    foreach ($schemas as $name => $statements) {
        $db->executeStatement("ATTACH DATABASE ':memory:' AS ".$name);
        foreach ($statements as $statement) {
            $statement = preg_replace('/^(CREATE (?:UNIQUE )?(?:TABLE|INDEX) )/', '$1'.$name.'.', $statement);
            $db->executeStatement($statement);
        }
    }

    foreach (['orders.statuses', 'orders.supply_statuses', 'supply.statuses', 'supply.order_statuses'] as $table) {
        $statuses = str_contains($table, 'orders.statuses')
            ? ['new', 'in_work', 'in_production', 'producted', 'completed']
            : ['draft', 'in_work', 'delivering', 'delivered', 'completed'];
        foreach ($statuses as $id => $status) {
            $db->insert($table, ['id' => $id + 1, 'title' => $status, 'slug' => $status]);
        }
    }
}

final class TestIdentities implements IdentityGenerator
{
    private array $ids = [];
    public function next(string $table): int { return $this->ids[$table] = ($this->ids[$table] ?? 0) + 1; }
}

final class Environment
{
    public Connection $db;
    public EntityManager $em;
    public CatalogService $catalog;
    public WarehouseService $warehouse;
    public OrderService $orders;
    public SupplyService $supply;
    public OrmWarehouseRepository $stock;
    public OrmSupplyRequestRepository $requests;
    public OrmSupplyOrderRepository $supplierOrders;
    public DbalOutboxEventPublisher $events;
    public ProductionSagaProcessor $processor;
    public OrmTransactionManager $transactions;

    public function __construct()
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->db->executeStatement('PRAGMA foreign_keys = ON');
        createTestSchema($this->db);
        $paths = [dirname(__DIR__).'/src/Shared/Infrastructure/Persistence/Entity'];
        foreach (['Catalog', 'Order', 'Supply', 'Warehouse'] as $module) {
            $paths[] = dirname(__DIR__).'/src/Modules/'.$module.'/Infrastructure/Persistence/Entity';
        }
        $configuration = ORMSetup::createAttributeMetadataConfiguration($paths, true);
        $configuration->enableNativeLazyObjects(true);
        $this->em = new EntityManager($this->db, $configuration);
        $ids = new TestIdentities();
        $this->events = new DbalOutboxEventPublisher($this->db);
        $this->transactions = new OrmTransactionManager($this->em);
        $this->catalog = new CatalogService(new OrmProductRepository($this->em, $ids),
            new OrmMaterialRepository($this->em, $ids), new OrmMeasureUnitRepository($this->em, $ids), $this->transactions);
        $this->stock = new OrmWarehouseRepository($this->em, $ids);
        $this->warehouse = new WarehouseService($this->stock, new OrmReservationPlanRepository($this->em),
            $this->catalog, $this->transactions, $this->events);
        $this->orders = new OrderService(new OrmOrderRepository($this->em, $ids, $this->events),
            $this->catalog, $this->warehouse, $this->transactions);
        $this->requests = new OrmSupplyRequestRepository($this->em, $ids, $this->events);
        $this->supplierOrders = new OrmSupplyOrderRepository($this->em, $ids, $this->events);
        $this->supply = new SupplyService($this->requests, $this->supplierOrders, $this->catalog, $this->transactions);
        $this->processor = new ProductionSagaProcessor($this->db,
            new ProductionSaga($this->orders, $this->warehouse, $this->supply), $this->transactions);
    }

    /** @return array{unit:int, materials:array, product:int, warehouse:int} */
    public function fixture(int $finished = 5, array $materialStock = [5, 5, 0], array $composition = [1, 1, 1]): array
    {
        $unit = $this->catalog->createMeasureUnit('шт');
        $materials = [];
        foreach (['A', 'B', 'C'] as $name) { $materials[] = $this->catalog->createMaterial($name, $unit); }
        $bom = [];
        foreach ($composition as $index => $quantity) { $bom[$materials[$index]] = $quantity; }
        $product = $this->catalog->createProduct('Изделие', 10000, 'P-1', $bom);
        $warehouse = $this->warehouse->createWarehouse('Основной склад');
        if ($finished > 0) { $this->warehouse->receiveStock($warehouse, 1, $product, $finished, $unit); }
        foreach ($materialStock as $index => $quantity) {
            if ($quantity > 0) { $this->warehouse->receiveStock($warehouse, 2, $materials[$index], $quantity, $unit); }
        }
        return compact('unit', 'materials', 'product', 'warehouse');
    }

    /** Drain committed events directly through the same processor used by the Kafka worker. */
    public function drain(): void
    {
        $iterations = 0;
        while (($row = $this->db->fetchAssociative('SELECT * FROM shared.outbox WHERE published_at IS NULL ORDER BY id LIMIT 1')) !== false) {
            if (++$iterations > 100) { throw new RuntimeException('Event loop did not terminate.'); }
            $event = DomainEvent::fromArray(json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR));
            same(true, $this->processor->process($event, 'saga-test'));
            same(false, $this->processor->process($event, 'saga-test')); // Simulate replay after an offset-commit crash.
            $this->db->executeStatement('UPDATE shared.outbox SET published_at = CURRENT_TIMESTAMP WHERE id = ?', [$row['id']]);
        }
    }

    public function count(string $table): int { return (int) $this->db->fetchOne('SELECT COUNT(*) FROM '.$table); }
}

$tests = [];

$tests['mixed stock: 10 ordered, 5 finished, material C purchased only for 5'] = function (): void {
    $e = new Environment();
    $f = $e->fixture();
    $orderId = $e->orders->create('O-1', $f['warehouse'], [$f['product'] => 10]);
    $e->drain();
    $plan = $e->warehouse->plan($orderId);
    same([$f['product'] => 5], $plan->reservedProducts);
    same([$f['product'] => 5], $plan->productionProducts);
    same(array_fill_keys($f['materials'], 5), $plan->requiredMaterials);
    same([$f['materials'][2] => 5], $plan->shortages);
    $request = $e->supply->requestForOrder($orderId);
    same($plan->shortages, $request->materials);
    same('in_work', $e->orders->get($orderId)->status);
    same(1, $e->count('supply.requests'));
    same(1, $e->count('supply.orders'));
    $e->supply->fulfillSupplierOrder($request->supplyOrderId);
    $e->supply->fulfillSupplierOrder($request->supplyOrderId);
    $e->drain();
    same('in_production', $e->orders->get($orderId)->status);
    same('completed', $e->orders->get($orderId)->supplyStatus);
    same('completed', $e->supply->requestForOrder($orderId)->status);
    same(1, $e->count('warehouse.receipts'));
    $delivered = $e->stock->findPosition($f['warehouse'], StockType::Material, $f['materials'][2]);
    same(5, $delivered->quantity());
    same(5, $delivered->reservedQuantity());
    same(0, $delivered->availableQuantity());
    same(4, count($e->requests->find($request->id)->history()));
    same(4, count($e->supplierOrders->find($request->supplyOrderId)->history()));
};

$tests['partial material stock purchases only the remaining quantity'] = function (): void {
    $e = new Environment();
    $f = $e->fixture(materialStock: [5, 5, 2]);
    $id = $e->orders->create('O-1', $f['warehouse'], [$f['product'] => 10]);
    $e->drain();
    same([$f['materials'][2] => 3], $e->supply->requestForOrder($id)->materials);
    $e->supply->fulfillSupplierOrder($e->supply->requestForOrder($id)->supplyOrderId);
    $e->drain();
    $stock = $e->stock->findPosition($f['warehouse'], StockType::Material, $f['materials'][2]);
    same(5, $stock->reservedQuantity());
    same(5, $stock->quantity());
};

$tests['all products in stock: no production, no procurement'] = function (): void {
    $e = new Environment();
    $f = $e->fixture(finished: 10, materialStock: []);
    $id = $e->orders->create('O-1', $f['warehouse'], [$f['product'] => 10]);
    $e->drain();
    same('producted', $e->orders->get($id)->status);
    same([], $e->warehouse->plan($id)->productionProducts);
    same(0, $e->count('supply.requests'));
    same(0, $e->count('supply.orders'));
};

$tests['all materials available: production starts without a supply request'] = function (): void {
    $e = new Environment();
    $f = $e->fixture(finished: 0, materialStock: [10, 10, 10]);
    $id = $e->orders->create('O-1', $f['warehouse'], [$f['product'] => 10]);
    $e->drain();
    same('in_production', $e->orders->get($id)->status);
    same(0, $e->count('supply.requests'));
    same(array_fill_keys($f['materials'], 10), $e->warehouse->plan($id)->requiredMaterials);
};

$tests['materials shared by different products are combined in one supply request'] = function (): void {
    $e = new Environment();
    $f = $e->fixture(finished: 0, materialStock: []);
    $other = $e->catalog->createProduct('Другое изделие', 20000, 'P-2', [$f['materials'][0] => 2]);
    $id = $e->orders->create('O-1', $f['warehouse'], [$f['product'] => 3, $other => 4]);
    $e->drain();
    same([$f['materials'][0] => 11, $f['materials'][1] => 3, $f['materials'][2] => 3],
        $e->supply->requestForOrder($id)->materials);
    same(1, $e->count('supply.requests'));
    same(1, $e->count('supply.orders'));
};

$tests['repeated reservation and receipt do not reserve or receive twice'] = function (): void {
    $e = new Environment();
    $f = $e->fixture();
    $id = $e->orders->create('O-1', $f['warehouse'], [$f['product'] => 10]);
    $e->drain();
    $before = $e->count('shared.outbox');
    $e->warehouse->reserveForOrder($id, $f['warehouse'], [$f['product'] => 10]);
    same($before, $e->count('shared.outbox'));
    $request = $e->supply->requestForOrder($id);
    $e->supply->fulfillSupplierOrder($request->supplyOrderId);
    $e->drain();
    $e->warehouse->receiveSupply($request->supplyOrderId, $id, $f['warehouse'], $request->materials);
    same(5, $e->stock->findPosition($f['warehouse'], StockType::Material, $f['materials'][2])->quantity());
    same(5, $e->stock->findPosition($f['warehouse'], StockType::Product, $f['product'])->reservedQuantity());
};

$tests['a second order cannot reserve finished stock already held by the first'] = function (): void {
    $e = new Environment();
    $f = $e->fixture();
    $first = $e->orders->create('O-1', $f['warehouse'], [$f['product'] => 4]);
    $e->drain();
    $second = $e->orders->create('O-2', $f['warehouse'], [$f['product'] => 4]);
    $e->drain();
    same([$f['product'] => 4], $e->warehouse->plan($first)->reservedProducts);
    same([$f['product'] => 1], $e->warehouse->plan($second)->reservedProducts);
    same([$f['product'] => 3], $e->warehouse->plan($second)->productionProducts);
    same(0, $e->stock->findPosition($f['warehouse'], StockType::Product, $f['product'])->availableQuantity());
};

$tests['failed BOM lookup rolls back reservations, new events and inbox claim'] = function (): void {
    $e = new Environment();
    $f = $e->fixture(composition: []);
    $id = $e->orders->create('O-1', $f['warehouse'], [$f['product'] => 10]);
    fails(fn () => $e->drain());
    same(0, $e->stock->findPosition($f['warehouse'], StockType::Product, $f['product'])->reservedQuantity());
    same(null, $e->warehouse->plan($id));
    same(1, $e->count('shared.outbox')); // Original order.created remains committed.
    same(0, $e->count('saga.inbox'));
    same('new', $e->orders->get($id)->status);
};

$tests['existing finished stock does not require a BOM'] = function (): void {
    $e = new Environment();
    $f = $e->fixture(finished: 10, composition: []);
    $id = $e->orders->create('O-1', $f['warehouse'], [$f['product'] => 10]);
    $e->drain();
    same('producted', $e->orders->get($id)->status);
};

$tests['supplier completion is required before completing a request'] = function (): void {
    $e = new Environment();
    $f = $e->fixture();
    $id = $e->orders->create('O-1', $f['warehouse'], [$f['product'] => 10]);
    $e->drain();
    $request = $e->supply->requestForOrder($id);
    fails(fn () => $e->supply->completeRequest($request->id));
    same(SupplyStatus::InWork, $e->requests->find($request->id)->status());
    same(1, $e->supply->createSupplierOrder($request->id));
    same(1, $e->count('supply.orders'));
    same($request->id, $e->supply->createRequest($id, $f['warehouse'], $request->materials));
    fails(fn () => $e->supply->createRequest($id, $f['warehouse'], [$f['materials'][2] => 100]));
};

$tests['inbox scope is per consumer group'] = function (): void {
    $e = new Environment();
    $event = DomainEvent::record('test.observation', 'order:1', []);
    same(true, $e->processor->process($event, 'group-a'));
    same(false, $e->processor->process($event, 'group-a'));
    same(true, $e->processor->process($event, 'group-b'));
};

$tests['outbox forbids writes outside a transaction and rolls back with business writes'] = function (): void {
    $e = new Environment();
    $event = DomainEvent::record('test.event', 'order:1', []);
    fails(fn () => $e->events->publish($event), LogicException::class);
    fails(fn () => $e->transactions->run(function () use ($e, $event): void {
        $e->events->publish($event);
        throw new DomainViolation('Abort');
    }));
    same(0, $e->count('shared.outbox'));
};

$tests['invalid allocation leaves the aggregate unchanged; production is gated by supply'] = function (): void {
    $order = Order::create(1, 'O-1', 1, [1 => 10, 2 => 3]);
    fails(fn () => $order->allocate([1 => 5, 2 => 4], true));
    same(0, $order->positions()[0]->reservedQuantity);
    same(OrderStatus::New, $order->status());
    fails(fn () => $order->startProduction());
    $order->allocate([1 => 5, 2 => 3], true);
    fails(fn () => $order->startProduction());
    $order->completeSupply();
    $order->startProduction();
    same(OrderStatus::InProduction, $order->status());
    fails(fn () => $order->complete());
    $order->markProduced();
    $order->complete();
    same(OrderStatus::Completed, $order->status());
};

$tests['outbox relay retains unacknowledged events and retries the same event ID'] = function (): void {
    $e = new Environment();
    $event = DomainEvent::record('test.delivery', 'order:1', []);
    $e->transactions->run(fn () => $e->events->publish($event));
    $sender = new class implements EventSender {
        public bool $fail = true;
        public array $ids = [];
        public function send(DomainEvent $event): void
        {
            $this->ids[] = $event->id;
            if ($this->fail) { throw new RuntimeException('Simulated uncertain delivery'); }
        }
    };
    $relay = new OutboxRelay($e->db, $sender);
    fails(fn () => $relay->publishNext(), RuntimeException::class);
    same(null, $e->db->fetchOne('SELECT published_at FROM shared.outbox'));
    $sender->fail = false;
    same(true, $relay->publishNext());
    same(false, $relay->publishNext());
    same([$event->id, $event->id], $sender->ids);
};

$tests['database enforces one request per order and one supplier order per request'] = function (): void {
    $e = new Environment();
    $f = $e->fixture();
    $id = $e->orders->create('O-1', $f['warehouse'], [$f['product'] => 10]);
    $e->drain();
    $request = $e->supply->requestForOrder($id);
    fails(fn () => $e->db->insert('supply.requests', ['id' => 999, 'order_id' => $id,
        'warehouse_id' => $f['warehouse'], 'date' => '2026-10-10 12:00:00', 'status_id' => 1]),
        Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
    fails(fn () => $e->db->insert('supply.orders', ['id' => 999, 'supply_request_id' => $request->id,
        'order_id' => $id, 'date' => '2026-10-10 12:00:00', 'status_id' => 1]),
        Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
};

$tests['demo and show/fulfill CLI commands run the documented workflow'] = function (): void {
    $e = new Environment();
    $seed = new Symfony\Component\Console\Tester\CommandTester(
        new App\Modules\Saga\Infrastructure\Console\SeedDemoCommand($e->catalog, $e->warehouse, $e->orders, $e->transactions));
    same(0, $seed->execute(['number' => 'DEMO-CLI']));
    same(0, $seed->execute(['number' => 'DEMO-CLI']));
    same(1, $e->count('orders.orders'));
    same(1, $e->count('catalog.products'));
    $e->drain();
    $show = new Symfony\Component\Console\Tester\CommandTester(
        new App\Modules\Order\Infrastructure\Console\ShowOrderCommand($e->orders, $e->warehouse, $e->supply));
    same(0, $show->execute(['id' => '1']));
    $data = json_decode($show->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
    same('in_work', $data['order']['status']);
    $fulfill = new Symfony\Component\Console\Tester\CommandTester(
        new App\Modules\Supply\Infrastructure\Console\FulfillSupplierOrderCommand($e->supply));
    same(0, $fulfill->execute(['id' => (string) $data['supplyRequest']['supplyOrderId']]));
    $e->drain();
    same(0, $show->execute(['id' => '1']));
    same('in_production', json_decode($show->getDisplay(), true, 512, JSON_THROW_ON_ERROR)['order']['status']);
};

$tests['positive quantities, nonnegative prices and overflow protection'] = function (): void {
    fails(fn () => new ProductComposition(1, 0));
    fails(fn () => new Product(1, 'P', -1, 'P-1', []));
    fails(fn () => new Product(1, 'P', 10, 'P-1', [new ProductComposition(1, 1), new ProductComposition(1, 2)]));
    fails(fn () => Assert::multiply(PHP_INT_MAX, 2));
    fails(fn () => Assert::add(PHP_INT_MAX, 1));
    fails(fn () => Order::create(1, 'O', 1, []));
    $stock = new WarehousePosition(1, 1, StockType::Material, 1, 1, 5);
    same(5, $stock->reserveUpTo(10));
    same(0, $stock->reserveUpTo(1));
    fails(fn () => $stock->release(6));
    $stock->release(2);
    same(2, $stock->availableQuantity());
};

$tests['warehouse units must match material units and duplicate order numbers are rejected'] = function (): void {
    $e = new Environment();
    $f = $e->fixture();
    $unit = $e->catalog->createMeasureUnit('кг');
    fails(fn () => $e->warehouse->receiveStock($f['warehouse'], 2, $f['materials'][0], 1, $unit));
    $e->orders->create('O-1', $f['warehouse'], [$f['product'] => 1]);
    fails(fn () => $e->orders->create('O-1', $f['warehouse'], [$f['product'] => 1]));
    same(1, $e->count('orders.orders'));
};

$tests['Domain has no framework or external module dependencies; Application has no Infrastructure dependencies'] = function (): void {
    $root = dirname(__DIR__).'/src';
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') { continue; }
        $path = str_replace($root.'/', '', $file->getPathname());
        $code = file_get_contents($file->getPathname());
        if (str_contains($path, '/Domain/')) {
            if (preg_match('/(?:Symfony|Doctrine|RdKafka|Redis)\\\\/', $code)) {
                throw new RuntimeException('Framework dependency in '.$path);
            }
            if (preg_match('#^Modules/([^/]+)/Domain/#', $path, $own)) {
                preg_match_all('/App\\\\Modules\\\\([^\\\\]+)\\\\/', $code, $matches);
                foreach ($matches[1] as $module) { same($own[1], $module); }
            }
        }
        if (str_contains($path, '/Application/') && str_contains($code, '\\Infrastructure\\')) {
            throw new RuntimeException('Infrastructure dependency in '.$path);
        }
    }
};

$tests['ORM models map the columns and primary keys of their own module schema'] = function (): void {
    $e = new Environment();
    same([], (new Doctrine\ORM\Tools\SchemaValidator($e->em))->validateMapping());
    $count = 0;
    foreach ($e->em->getMetadataFactory()->getAllMetadata() as $metadata) {
        if ($metadata->isMappedSuperclass) { continue; }
        ++$count;
        preg_match('/App\\\\Modules\\\\([^\\\\]+)\\\\/', $metadata->name, $matches);
        $schema = $matches[1] === 'Order' ? 'orders' : strtolower($matches[1]);
        same($schema, $metadata->table['schema']);
    }
    same(21, $count);
};

$tests['migrations use direct Doctrine DDL without deprecated schema builders'] = function (): void {
    foreach (MIGRATIONS as $class) {
        same(Doctrine\Migrations\AbstractMigration::class, get_parent_class($class));
        $source = file_get_contents((new ReflectionClass($class))->getFileName());
        same(false, str_contains($source, 'createTable'));
        same(true, str_contains($source, 'addSql('));
    }
};

$tests['migration discovery finds each module and orders timestamps across namespaces'] = function (): void {
    $configuration = Symfony\Component\Yaml\Yaml::parseFile(dirname(__DIR__).'/config/packages/doctrine_migrations.yaml');
    $finder = new Doctrine\Migrations\Finder\GlobFinder();
    $versions = [];
    foreach ($configuration['doctrine_migrations']['migrations_paths'] as $namespace => $path) {
        $path = str_replace('%kernel.project_dir%', dirname(__DIR__), $path);
        foreach ($finder->findMigrations($path, $namespace) as $class) {
            $versions[] = new Doctrine\Migrations\Version\Version($class);
        }
    }
    same(7, count($versions));
    $comparator = new App\Shared\Infrastructure\Persistence\MigrationComparator();
    usort($versions, $comparator->compare(...));
    $expected = ['DoctrineMigrations\\Version20261010000100', ...MIGRATIONS];
    same($expected, array_map(strval(...), $versions));
};

$tests['Unit of Work is cleared at the application transaction boundary'] = function (): void {
    $e = new Environment();
    $e->fixture();
    same(0, $e->em->getUnitOfWork()->size());
    $e->catalog->material(1);
    same(true, $e->em->getUnitOfWork()->size() > 0);
    $e->transactions->run(static fn () => null);
    same(0, $e->em->getUnitOfWork()->size());
};

$failed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo 'PASS '.$name.PHP_EOL;
    } catch (Throwable $error) {
        ++$failed;
        fwrite(STDERR, 'FAIL '.$name.': '.$error::class.' '.$error->getMessage().PHP_EOL);
        fwrite(STDERR, $error->getTraceAsString().PHP_EOL);
    }
}
echo count($tests).' tests, '.$failed.' failures'.PHP_EOL;
exit($failed === 0 ? 0 : 1);
