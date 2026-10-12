<?php

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use InvalidArgumentException;

class MarkOrderPaidAction
{
    /**
     * Settles the debt of a credit order.
     *
     * @throws InvalidArgumentException when the order is not an unpaid debt order.
     */
    public function execute(Order $order): Order
    {
        if ($order->payment_type !== 'debt') {
            throw new InvalidArgumentException('Order is not a debt order.');
        }

        if ($order->status === OrderStatus::Voided) {
            throw new InvalidArgumentException('A voided order cannot be marked as paid.');
        }

        if ($order->debt_status === 'paid') {
            throw new InvalidArgumentException('Order debt is already marked as paid.');
        }

        $order->update([
            'debt_status' => 'paid',
            'debt_paid_at' => now(),
            'status' => OrderStatus::Completed,
        ]);

        return $order;
    }
}
