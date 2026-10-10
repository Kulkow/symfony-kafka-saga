<?php
declare(strict_types=1);
namespace App\Modules\Order\Application;
use App\Modules\Catalog\Application\Contract\CatalogReader;
use App\Modules\Order\Application\Contract\OrderData;
use App\Modules\Order\Domain\{Order, OrderRepository};
use App\Modules\Warehouse\Application\Contract\WarehouseDirectory;
use App\Shared\Application\{NotFound, TransactionManager};
use App\Shared\Domain\{Assert, DomainViolation};

final readonly class OrderService
{
    public function __construct(
        private OrderRepository $orders,
        private CatalogReader $catalog,
        private WarehouseDirectory $warehouses,
        private TransactionManager $transactions,
    ) {}

    public function create(string $number, int $warehouseId, array $products): int
    {
        return $this->transactions->run(function () use ($number, $warehouseId, $products): int {
            $products = Assert::quantities($products);
            if ($this->orders->findByNumber($number) !== null) {
                throw new DomainViolation('Order number already exists.');
            }
            if (!$this->warehouses->warehouseExists($warehouseId)) {
                throw new NotFound('Warehouse not found.');
            }
            foreach ($products as $id => $quantity) { $this->catalog->product($id); }
            $order = Order::create($this->orders->nextIdentity(), $number, $warehouseId, $products);
            $this->orders->save($order);
            return $order->id;
        });
    }

    public function get(int $id): OrderData
    {
        $order = $this->requireOrder($id);
        $positions = [];
        foreach ($order->positions() as $position) {
            $positions[] = ['productId' => $position->productId, 'quantity' => $position->quantity,
                'reservedQuantity' => $position->reservedQuantity, 'productionQuantity' => $position->productionQuantity()];
        }
        return new OrderData($order->id, $order->number, $order->warehouseId,
            $order->status()->value, $order->supplyStatus()->value, $positions);
    }

    public function findByNumber(string $number): ?OrderData
    {
        $order = $this->orders->findByNumber($number);
        return $order === null ? null : $this->get($order->id);
    }

    public function allocate(int $id, array $reservedProducts, bool $needsSupply): void
    {
        $this->transactions->run(function () use ($id, $reservedProducts, $needsSupply): void {
            $order = $this->requireOrder($id);
            $order->allocate($reservedProducts, $needsSupply);
            $this->orders->save($order);
        });
    }

    public function startProduction(int $id, bool $supplyCompleted = false): void
    {
        $this->transactions->run(function () use ($id, $supplyCompleted): void {
            $order = $this->requireOrder($id);
            if ($supplyCompleted) { $order->completeSupply(); }
            $order->startProduction();
            $this->orders->save($order);
        });
    }

    private function requireOrder(int $id): Order
    {
        return $this->orders->find($id) ?? throw new NotFound('Order not found: '.$id);
    }
}
