<?php

namespace App\Modules\Orders\Exports;

use App\Modules\Orders\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Tessera\Engine\Exports\Column;
use Tessera\Engine\Exports\ExportContext;
use Tessera\Engine\Exports\ReportDefinition;

class OrdersReport extends ReportDefinition
{
    public function key(): string
    {
        return 'orders';
    }

    public function permission(): string
    {
        return 'view orders';
    }

    public function filterRules(int $tenantId): array
    {
        return [
            ...$this->branchRules($tenantId),
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['completed', 'voided', 'debt'])],
            'payment_type' => ['nullable', Rule::in(['cash', 'debt'])],
            'debt_status' => ['nullable', Rule::in(['unpaid', 'paid'])],
        ];
    }

    public function columns(ExportContext $context): array
    {
        return [
            new Column('order_number', Column::TEXT, 22),
            new Column('datetime', Column::DATETIME, 18),
            new Column('branch', Column::TEXT, 18),
            new Column('cashier', Column::TEXT, 18),
            new Column('customer', Column::TEXT, 20),
            new Column('payment_type', Column::TEXT, 12),
            new Column('status', Column::TEXT, 12),
            new Column('subtotal', Column::MONEY, 14),
            new Column('discount', Column::MONEY, 14),
            new Column('total', Column::MONEY, 14),
        ];
    }

    public function rows(ExportContext $context): iterable
    {
        $query = $this->query($context)->with(['cashier:id,name', 'branch:id,name', 'customer:id,name']);

        foreach ($query->lazyByIdDesc((int) config('exports.chunk_size')) as $order) {
            yield [
                'order_number' => $order->order_number,
                'datetime' => $order->created_at,
                'branch' => $order->branch?->name,
                'cashier' => $order->cashier?->name,
                'customer' => $order->customer?->name,
                'payment_type' => $context->t('values.'.$order->payment_type),
                'status' => $context->t('values.'.$order->status->value),
                'subtotal' => (float) $order->subtotal,
                'discount' => (float) $order->discount_amount,
                'total' => (float) $order->total,
            ];
        }
    }

    public function count(ExportContext $context): int
    {
        return $this->query($context)->count();
    }

    public function summary(ExportContext $context): array
    {
        $kept = $this->query($context)->where('status', '!=', 'voided');

        return [
            $this->figure($context, 'orders', (clone $kept)->count(), Column::INTEGER),
            $this->figure($context, 'total', (float) (clone $kept)->sum('total')),
            $this->figure($context, 'discounts', (float) (clone $kept)->sum('discount_amount')),
        ];
    }

    public function filterLines(ExportContext $context): array
    {
        $lines = [];

        if ($period = $this->periodLine($context)) {
            $lines[] = $period;
        }

        if ($branch = $this->branchLine($context)) {
            $lines[] = $branch;
        }

        foreach (['status', 'payment_type'] as $key) {
            if ($value = $context->filter($key)) {
                $lines[] = $context->t("filters.{$key}", ['value' => $context->t("values.{$value}")]);
            }
        }

        if ($value = $context->filter('debt_status')) {
            $lines[] = $context->t('filters.debt_status', ['value' => $context->t("values.{$value}")]);
        }

        if ($search = $context->filter('search')) {
            $lines[] = $context->t('filters.search', ['value' => $search]);
        }

        return $lines;
    }

    /**
     * Mirrors the filters of the orders list, including the branch limit for users who cannot see every branch.
     *
     * @return Builder<Order>
     */
    private function query(ExportContext $context): Builder
    {
        $query = Order::query()->where('tenant_id', $context->tenant->id);

        if ($branchId = $this->branchId($context)) {
            $query->where('branch_id', $branchId);
        }

        if ($search = $context->filter('search')) {
            $query->where('order_number', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $search).'%');
        }

        foreach (['status', 'payment_type', 'debt_status'] as $column) {
            if ($value = $context->filter($column)) {
                $query->where($column, $value);
            }
        }

        if ($from = $context->date('date_from')) {
            $query->whereDate('created_at', '>=', $from->toDateString());
        }

        if ($to = $context->date('date_to')) {
            $query->whereDate('created_at', '<=', $to->toDateString());
        }

        return $query;
    }
}
