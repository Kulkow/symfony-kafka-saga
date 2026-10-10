<?php
declare(strict_types=1);
namespace App\Modules\Supply\Domain;
use App\Shared\Domain\Aggregate;
use App\Shared\Domain\Assert;
use App\Shared\Domain\DomainViolation;

final class SupplyOrder extends Aggregate
{
    public readonly array $materials;
    /** @var list<SupplyOrderMaterial> */
    public readonly array $positions;
    public function __construct(
        public readonly int $id,
        public readonly int $supplyRequestId,
        public readonly int $orderId,
        public readonly \DateTimeImmutable $date,
        array $materials,
        private SupplyStatus $status = SupplyStatus::Draft,
        private array $history = [],
    ) {
        Assert::positive($id, 'supplyOrderId');
        Assert::positive($supplyRequestId, 'supplyRequestId');
        Assert::positive($orderId, 'orderId');
        $this->materials = Assert::quantities($materials);
        $positions = [];
        foreach ($this->materials as $materialId => $quantity) {
            $positions[] = new SupplyOrderMaterial($materialId, $quantity);
        }
        $this->positions = $positions;
    }

    public static function create(int $id, SupplyRequest $request): self
    {
        $order = new self($id, $request->id, $request->orderId, new \DateTimeImmutable(), $request->materials);
        $order->record('supply.order_created', 'order:'.$request->orderId,
            ['supplyOrderId' => $id, 'requestId' => $request->id, 'orderId' => $request->orderId]);
        return $order;
    }

    public function status(): SupplyStatus { return $this->status; }
    public function history(): array { return $this->history; }

    public function fulfill(): void
    {
        if ($this->status === SupplyStatus::Completed) {
            return;
        }
        while (($next = $this->status->next()) !== null) {
            $this->history[] = new StatusChange($this->status, $next, new \DateTimeImmutable());
            $this->status = $next;
        }
        $this->record('supply.order_completed', 'order:'.$this->orderId,
            ['supplyOrderId' => $this->id, 'requestId' => $this->supplyRequestId, 'orderId' => $this->orderId]);
    }
}
