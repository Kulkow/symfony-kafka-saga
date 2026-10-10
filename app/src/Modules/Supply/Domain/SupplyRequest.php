<?php
declare(strict_types=1);
namespace App\Modules\Supply\Domain;
use App\Shared\Domain\Aggregate;
use App\Shared\Domain\Assert;
use App\Shared\Domain\DomainViolation;

final class SupplyRequest extends Aggregate
{
    public readonly array $materials;
    /** @var list<SupplyRequestMaterial> */
    public readonly array $positions;
    /** @param array<int, int> $materials @param list<StatusChange> $history */
    public function __construct(
        public readonly int $id,
        public readonly int $orderId,
        public readonly int $warehouseId,
        public readonly \DateTimeImmutable $date,
        array $materials,
        private SupplyStatus $status = SupplyStatus::Draft,
        private array $history = [],
    ) {
        Assert::positive($id, 'requestId');
        Assert::positive($orderId, 'orderId');
        Assert::positive($warehouseId, 'warehouseId');
        $this->materials = Assert::quantities($materials);
        $positions = [];
        foreach ($this->materials as $materialId => $quantity) {
            $positions[] = new SupplyRequestMaterial($materialId, $quantity);
        }
        $this->positions = $positions;
    }

    public static function create(int $id, int $orderId, int $warehouseId, array $materials): self
    {
        $request = new self($id, $orderId, $warehouseId, new \DateTimeImmutable(), $materials);
        $request->record('supply.request_created', 'order:'.$orderId,
            ['requestId' => $id, 'orderId' => $orderId]);
        return $request;
    }

    public function status(): SupplyStatus { return $this->status; }
    public function history(): array { return $this->history; }

    public function start(): void { $this->advance(SupplyStatus::InWork); }

    public function complete(): void
    {
        if ($this->status !== SupplyStatus::InWork) {
            throw new DomainViolation('Only a request in work can be completed after delivery.');
        }
        $this->advance(SupplyStatus::Delivering);
        $this->advance(SupplyStatus::Delivered);
        $this->advance(SupplyStatus::Completed);
        $this->record('supply.request_completed', 'order:'.$this->orderId,
            ['requestId' => $this->id, 'orderId' => $this->orderId]);
    }

    private function advance(SupplyStatus $next): void
    {
        if ($this->status->next() !== $next) {
            throw new DomainViolation('Invalid supply request status transition.');
        }
        $this->history[] = new StatusChange($this->status, $next, new \DateTimeImmutable());
        $this->status = $next;
    }
}
