<?php
declare(strict_types=1);
namespace App\Modules\Order\Domain;
use App\Shared\Domain\Aggregate;
use App\Shared\Domain\Assert;
use App\Shared\Domain\DomainViolation;

final class Order extends Aggregate
{
    /** @param list<OrderPosition> $positions */
    public function __construct(
        public readonly int $id,
        public readonly string $number,
        public readonly int $warehouseId,
        public readonly \DateTimeImmutable $date,
        private array $positions,
        private OrderStatus $status = OrderStatus::New,
        private OrderSupplyStatus $supplyStatus = OrderSupplyStatus::Draft,
    ) {
        Assert::positive($id, 'orderId');
        Assert::positive($warehouseId, 'warehouseId');
        Assert::text($number, 'number');
        if ($positions === []) {
            throw new DomainViolation('An order must contain products.');
        }
        $seen = [];
        foreach ($positions as $position) {
            if (!$position instanceof OrderPosition || isset($seen[$position->productId])) {
                throw new DomainViolation('Order products must be unique.');
            }
            $seen[$position->productId] = true;
        }
    }

    public static function create(int $id, string $number, int $warehouseId, array $quantities): self
    {
        $positions = [];
        foreach (Assert::quantities($quantities) as $productId => $quantity) {
            $positions[] = new OrderPosition($productId, $quantity);
        }
        $order = new self($id, $number, $warehouseId, new \DateTimeImmutable(), $positions);
        $order->record('order.created', 'order:'.$id,
            ['orderId' => $id, 'warehouseId' => $warehouseId, 'products' => $quantities]);
        return $order;
    }

    public function status(): OrderStatus { return $this->status; }
    public function supplyStatus(): OrderSupplyStatus { return $this->supplyStatus; }
    /** @return list<OrderPosition> */
    public function positions(): array { return $this->positions; }

    public function allocate(array $reservedProducts, bool $needsSupply): void
    {
        if ($this->status !== OrderStatus::New) {
            throw new DomainViolation('Product allocation is only allowed for a new order.');
        }
        $reservedProducts = Assert::quantities($reservedProducts, true);
        $remaining = $reservedProducts;
        $positions = [];
        foreach ($this->positions as $position) {
            $positions[] = new OrderPosition($position->productId, $position->quantity,
                $reservedProducts[$position->productId] ?? 0);
            unset($remaining[$position->productId]);
        }
        if ($remaining !== []) {
            throw new DomainViolation('Allocation contains products outside the order.');
        }
        $this->positions = $positions;
        $this->status = OrderStatus::InWork;
        $this->supplyStatus = $needsSupply ? OrderSupplyStatus::InWork : OrderSupplyStatus::Completed;
    }

    public function completeSupply(): void
    {
        if ($this->status !== OrderStatus::InWork || $this->supplyStatus !== OrderSupplyStatus::InWork) {
            throw new DomainViolation('The order is not awaiting supply.');
        }
        $this->supplyStatus = OrderSupplyStatus::Completed;
    }

    public function startProduction(): void
    {
        if ($this->status !== OrderStatus::InWork || $this->supplyStatus !== OrderSupplyStatus::Completed) {
            throw new DomainViolation('Production requires allocation and completed supply.');
        }
        $needsProduction = false;
        foreach ($this->positions as $position) {
            $needsProduction = $needsProduction || $position->productionQuantity() > 0;
        }
        $this->status = $needsProduction ? OrderStatus::InProduction : OrderStatus::Produced;
        $this->record($needsProduction ? 'order.in_production' : 'order.ready',
            'order:'.$this->id, ['orderId' => $this->id]);
    }

    public function markProduced(): void
    {
        if ($this->status !== OrderStatus::InProduction) {
            throw new DomainViolation('Only an order in production can be marked produced.');
        }
        $this->status = OrderStatus::Produced;
    }

    public function complete(): void
    {
        if ($this->status !== OrderStatus::Produced) {
            throw new DomainViolation('Only a ready order can be completed.');
        }
        $this->status = OrderStatus::Completed;
    }
}
