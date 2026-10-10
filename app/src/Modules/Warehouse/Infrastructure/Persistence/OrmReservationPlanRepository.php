<?php
declare(strict_types=1);
namespace App\Modules\Warehouse\Infrastructure\Persistence;
use App\Modules\Warehouse\Application\Contract\ReservationPlan;
use App\Modules\Warehouse\Application\ReservationPlanRepository;
use App\Modules\Warehouse\Infrastructure\Persistence\Entity\ReservationPlanRecord;
use App\Shared\Application\NotFound;
use Doctrine\ORM\EntityManagerInterface;

final class OrmReservationPlanRepository implements ReservationPlanRepository
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    public function find(int $orderId): ?ReservationPlan
    {
        $record = $this->entityManager->find(ReservationPlanRecord::class, $orderId);
        return $record === null ? null : new ReservationPlan($record->plan['reservedProducts'],
            $record->plan['productionProducts'], $record->plan['requiredMaterials'], $record->plan['shortages']);
    }
    public function save(int $orderId, int $warehouseId, ReservationPlan $plan): void
    {
        $this->requireTransaction();
        $record = new ReservationPlanRecord();
        $record->orderId = $orderId;
        $record->warehouseId = $warehouseId;
        $record->plan = $plan->toArray();
        $this->entityManager->persist($record);
        $this->entityManager->flush();
    }
    public function warehouseForOrder(int $orderId): int
    {
        return ($this->entityManager->find(ReservationPlanRecord::class, $orderId) ?? throw new NotFound('Reservation plan not found.'))->warehouseId;
    }

    private function requireTransaction(): void
    {
        if (!$this->entityManager->getConnection()->isTransactionActive()) {
            throw new \LogicException('Repository writes require an application transaction.');
        }
    }
}
