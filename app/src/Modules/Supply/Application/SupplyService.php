<?php
declare(strict_types=1);
namespace App\Modules\Supply\Application;
use App\Modules\Catalog\Application\Contract\CatalogReader;
use App\Modules\Supply\Application\Contract\SupplyRequestData;
use App\Modules\Supply\Domain\{SupplyOrder, SupplyOrderRepository, SupplyRequest, SupplyRequestRepository, SupplyStatus};
use App\Shared\Application\{NotFound, TransactionManager};
use App\Shared\Domain\{Assert, DomainViolation};

final readonly class SupplyService
{
    public function __construct(
        private SupplyRequestRepository $requests,
        private SupplyOrderRepository $orders,
        private CatalogReader $catalog,
        private TransactionManager $transactions,
    ) {}

    public function createRequest(int $orderId, int $warehouseId, array $materials): int
    {
        return $this->transactions->run(function () use ($orderId, $warehouseId, $materials): int {
            $materials = Assert::quantities($materials);
            $existing = $this->requests->findByOrder($orderId);
            if ($existing !== null) {
                if ($existing->materials !== $materials || $existing->warehouseId !== $warehouseId) {
                    throw new DomainViolation('An order already has a different supply request.');
                }
                return $existing->id;
            }
            foreach ($materials as $materialId => $quantity) { $this->catalog->material($materialId); }
            $request = SupplyRequest::create($this->requests->nextIdentity(), $orderId, $warehouseId, $materials);
            $this->requests->save($request);
            return $request->id;
        });
    }

    public function createSupplierOrder(int $requestId): int
    {
        return $this->transactions->run(function () use ($requestId): int {
            $request = $this->requireRequest($requestId);
            $existing = $this->orders->findByRequest($requestId);
            if ($existing !== null) { return $existing->id; }
            $order = SupplyOrder::create($this->orders->nextIdentity(), $request);
            $request->start();
            $this->requests->save($request);
            $this->orders->save($order);
            return $order->id;
        });
    }

    /** Educational command: the supplier has performed all delivery stages. */
    public function fulfillSupplierOrder(int $supplyOrderId): void
    {
        $this->transactions->run(function () use ($supplyOrderId): void {
            $order = $this->orders->find($supplyOrderId) ?? throw new NotFound('Supplier order not found.');
            $order->fulfill();
            $this->orders->save($order);
        });
    }

    public function requestForOrder(int $orderId): ?SupplyRequestData
    {
        $request = $this->requests->findByOrder($orderId);
        return $request === null ? null : $this->view($request);
    }

    public function request(int $id): SupplyRequestData { return $this->view($this->requireRequest($id)); }

    public function completeRequest(int $requestId): void
    {
        $this->transactions->run(function () use ($requestId): void {
            $request = $this->requireRequest($requestId);
            $order = $this->orders->findByRequest($requestId) ?? throw new NotFound('Supplier order not found.');
            if ($order->status() !== SupplyStatus::Completed) {
                throw new DomainViolation('Supplier order must be completed before the supply request.');
            }
            if ($request->status() === SupplyStatus::Completed) { return; }
            $request->complete();
            $this->requests->save($request);
        });
    }

    private function requireRequest(int $id): SupplyRequest
    {
        return $this->requests->find($id) ?? throw new NotFound('Supply request not found: '.$id);
    }

    private function view(SupplyRequest $request): SupplyRequestData
    {
        return new SupplyRequestData($request->id, $request->orderId, $request->warehouseId,
            $request->status()->value, $request->materials, $this->orders->findByRequest($request->id)?->id);
    }
}
