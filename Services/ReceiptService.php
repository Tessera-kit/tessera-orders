<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Models\Order;

class ReceiptService
{
    /**
     * Generate structured receipt data from an order.
     *
     * @return array{
     *     tenant_name: string,
     *     branch_name: string,
     *     branch_address: ?string,
     *     order_number: string,
     *     date: string,
     *     time: string,
     *     cashier: string,
     *     items: array<array{name: string, quantity: int, unit_price: float, total: float}>,
     *     subtotal: float,
     *     total: float,
     *     cash_received: float,
     *     change: float
     * }
     */
    public function generateReceiptData(Order $order): array
    {
        $order->load(['branch.tenant', 'items.product', 'payment', 'cashier']);

        return [
            'tenant_name' => $order->branch->tenant->name,
            'tenant_logo' => $order->branch->tenant->logo_url ?? '',
            'branch_name' => $order->branch->name,
            'branch_address' => $order->branch->address,
            'receipt_header' => $order->branch->tenant->receipt_header,
            'receipt_footer' => $order->branch->tenant->receipt_footer,
            'order_number' => $order->order_number,
            'date' => $order->created_at->format('M d, Y'),
            'time' => $order->created_at->format('h:i A'),
            'cashier' => $order->cashier->name,
            'items' => $order->items->map(fn ($item) => [
                'name' => $item->product->name,
                'quantity' => $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'total' => (float) $item->total,
            ])->toArray(),
            'subtotal' => (float) $order->subtotal,
            'discount_amount' => (float) ($order->discount_amount ?? 0),
            'total' => (float) $order->total,
            'cash_received' => (float) $order->payment->cash_received,
            'change' => (float) $order->payment->change_amount,
        ];
    }

    /**
     * Render the receipt HTML for printing.
     */
    public function renderHtml(Order $order): string
    {
        $data = $this->generateReceiptData($order);

        return view('receipts.thermal', $data)->render();
    }
}
