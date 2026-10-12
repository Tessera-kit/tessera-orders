<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use Carbon\CarbonImmutable;
use Tessera\Core\Contracts\RevenueSource;

class OrderRevenueSource implements RevenueSource
{
    public function byMonth(int $tenantId, CarbonImmutable $from, CarbonImmutable $to, ?int $branchId = null): array
    {
        $months = [];

        $sales = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.tenant_id', $tenantId)
            ->where('orders.status', 'completed')
            ->when($branchId, fn ($query) => $query->where('orders.branch_id', $branchId))
            ->whereBetween('orders.created_at', [$from->startOfDay(), $to->endOfDay()])
            ->selectRaw('DATE(orders.created_at) as day, SUM(order_items.total) as revenue, SUM(order_items.cost_price * order_items.quantity) as cogs')
            ->groupByRaw('DATE(orders.created_at)')
            ->toBase()
            ->get();

        $discounts = Order::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'completed')
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->selectRaw('DATE(created_at) as day, SUM(discount_amount) as discounts')
            ->groupByRaw('DATE(created_at)')
            ->toBase()
            ->get();

        foreach ($sales as $row) {
            $month = substr((string) $row->day, 0, 7);
            $months[$month] ??= ['revenue' => 0.0, 'discounts' => 0.0, 'cogs' => 0.0];
            $months[$month]['revenue'] += (float) $row->revenue;
            $months[$month]['cogs'] += (float) $row->cogs;
        }

        foreach ($discounts as $row) {
            $month = substr((string) $row->day, 0, 7);
            $months[$month] ??= ['revenue' => 0.0, 'discounts' => 0.0, 'cogs' => 0.0];
            $months[$month]['discounts'] += (float) $row->discounts;
        }

        return $months;
    }
}
