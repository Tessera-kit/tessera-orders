<?php

namespace App\Modules\Orders\Services;

use App\Modules\Team\Models\User;
use Illuminate\Validation\Rule;

/**
 * Validation rules for one sale, shared by the till, offline sync and any other way a sale can arrive.
 */
class SaleRules
{
    /**
     * @param  array<string, mixed>  $sale
     * @return array<string, array<int, mixed>>
     */
    public static function for(User $user, array $sale = []): array
    {
        $inTenant = fn (string $table) => Rule::exists($table, 'id')->where('tenant_id', $user->tenant_id);

        return [
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer', $inTenant('products')],
            'items.*.quantity' => ['required', 'numeric', 'min:0.0001', 'max:1000000'],
            'items.*.unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'items.*.unit_price' => ['sometimes', 'numeric', 'min:0'],
            'items.*.original_price' => ['sometimes', 'numeric', 'min:0'],
            'items.*.original_currency' => ['sometimes', 'nullable', 'string', 'max:3'],
            'cash_received' => ['required', 'numeric', 'min:0'],
            'discount_type' => ['nullable', 'string', 'in:percentage,fixed'],
            'discount_value' => ['nullable', 'numeric', 'min:0', 'max:'.(($sale['discount_type'] ?? null) === 'percentage' ? '100' : '1000000000')],
            'payment_type' => ['nullable', 'string', 'in:cash,debt'],
            'customer_id' => ['nullable', 'required_if:payment_type,debt', 'integer', $inTenant('customers')],
            'offline_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'coupon_code' => ['sometimes', 'nullable', 'string', 'max:50'],
        ];
    }
}
