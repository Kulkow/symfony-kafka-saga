<?php
declare(strict_types=1);
namespace App\Modules\Saga\Application;
use App\Modules\Order\Application\OrderService;
use App\Modules\Supply\Application\SupplyService;
use App\Modules\Warehouse\Application\WarehouseService;
use App\Shared\Domain\DomainEvent;

/** Saga 1 orchestrates public Application APIs; it never reads another module's tables. */
final readonly class ProductionSaga
{
    public function __construct(
        private OrderService $orders,
        private WarehouseService $warehouse,
        private SupplyService $supply,
    ) {}

    public function handle(DomainEvent $event): void
    {
        switch ($event->name) {
            case 'order.created':
                $order = $this->orders->get($event->data['orderId']);
                $products = [];
                foreach ($order->positions as $position) {
                    $products[$position['productId']] = $position['quantity'];
                }
                $this->warehouse->reserveForOrder($order->id, $order->warehouseId, $products);
                break;
            case 'warehouse.reserved':
                $orderId = $event->data['orderId'];
                $plan = $this->warehouse->plan($orderId) ?? throw new \LogicException('Reservation plan missing.');
                $this->orders->allocate($orderId, $plan->reservedProducts, $plan->shortages !== []);
                if ($plan->shortages !== []) {
                    $this->supply->createRequest($orderId, $event->data['warehouseId'], $plan->shortages);
                } else {
                    $this->orders->startProduction($orderId);
                }
                break;
            case 'supply.request_created':
                $this->supply->createSupplierOrder($event->data['requestId']);
                break;
            case 'supply.order_completed':
                $request = $this->supply->request($event->data['requestId']);
                if ($request->supplyOrderId !== $event->data['supplyOrderId']) {
                    throw new \LogicException('Supplier order does not belong to the request.');
                }
                $this->warehouse->receiveSupply($request->supplyOrderId, $request->orderId,
                    $request->warehouseId, $request->materials);
                break;
            case 'warehouse.supply_received':
                $request = $this->supply->requestForOrder($event->data['orderId'])
                    ?? throw new \LogicException('Supply request missing.');
                $this->supply->completeRequest($request->id);
                break;
            case 'supply.request_completed':
                $this->orders->startProduction($event->data['orderId'], supplyCompleted: true);
                break;
        }
    }
}
