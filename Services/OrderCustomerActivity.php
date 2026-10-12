<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Models\Order;
use Illuminate\Pagination\LengthAwarePaginator;
use Tessera\Core\Contracts\CustomerActivity;

class OrderCustomerActivity implements CustomerActivity
{
    public function available(): bool
    {
        return true;
    }

    public function summary(int $customerId): array
    {
        $own = fn () => Order::query()->where('customer_id', $customerId);
        $debt = fn (string $status) => (float) $own()->where('payment_type', 'debt')->where('debt_status', $status)->where('status', '!=', 'voided')->sum('total');

        return [
            'orders' => $own()->count(),
            'spent' => (float) $own()->where('status', '!=', 'voided')->sum('total'),
            'debt' => $debt('unpaid'),
            'paid_debt' => $debt('paid'),
        ];
    }

    public function totals(array $customerIds): array
    {
        return Order::query()
            ->whereIn('customer_id', $customerIds)
            ->selectRaw("customer_id, count(*) as orders, coalesce(sum(case when payment_type = 'debt' and debt_status = 'unpaid' then total else 0 end), 0) as debt")
            ->groupBy('customer_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [(int) $row->customer_id => ['orders' => (int) $row->orders, 'debt' => (float) $row->debt]])
            ->all();
    }

    public function orders(int $customerId, int $perPage = 15): LengthAwarePaginator
    {
        return Order::query()
            ->where('customer_id', $customerId)
            ->with(['cashier:id,name', 'branch:id,name'])
            ->orderByRaw("debt_status = 'unpaid' DESC")
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();
    }
}
