<?php

namespace App\Modules\Orders\Actions;

use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Team\Enums\ActivityEvent;
use App\Modules\Team\Models\User;
use App\Modules\Team\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class VoidOrderAction
{
    public function __construct(private readonly ActivityLogger $activity) {}

    /**
     * Voids an order and puts its stock back. Two simultaneous requests cannot both return the stock.
     *
     * @throws InvalidArgumentException when the order is already voided.
     */
    public function execute(Order $order, User $user): Order
    {
        return DB::transaction(function () use ($order, $user): Order {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();

            if ($locked->status === OrderStatus::Voided) {
                throw new InvalidArgumentException('Order is already voided.');
            }

            $locked->update(['status' => OrderStatus::Voided]);

            StockMovement::query()
                ->where('reference_type', Order::class)
                ->where('reference_id', $locked->id)
                ->where('type', StockMovementType::Sale)
                ->get()
                ->each(fn (StockMovement $sale) => StockMovement::create([
                    'tenant_id' => $sale->tenant_id,
                    'product_id' => $sale->product_id,
                    'location_id' => $sale->location_id,
                    'type' => StockMovementType::Adjustment,
                    'quantity' => abs((float) $sale->quantity),
                    'unit_id' => $sale->unit_id,
                    'unit_quantity' => $sale->unit_quantity,
                    'unit_to_base_factor' => $sale->unit_to_base_factor,
                    'reference_type' => Order::class,
                    'reference_id' => $locked->id,
                    'notes' => 'Voided order '.$locked->order_number,
                    'created_by' => $user->id,
                ]));

            $this->activity->record(ActivityEvent::OrderVoided, "Voided order {$locked->order_number}", $locked, [], $user);

            return $locked;
        });
    }
}
