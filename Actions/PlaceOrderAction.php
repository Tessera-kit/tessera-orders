<?php

namespace App\Modules\Orders\Actions;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductUnit;
use App\Modules\Catalog\Services\UnitConversionService;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Team\Enums\ActivityEvent;
use App\Modules\Team\Models\User;
use App\Modules\Team\Services\ActivityLogger;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Location;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tessera\Core\Contracts\CartPricing;
use Tessera\Core\Pricing\CartLine;
use Tessera\Core\Pricing\DiscountResult;
use Tessera\Core\Pricing\PricingContext;

class PlaceOrderAction
{
    public function __construct(
        private StockService $stockService,
        private UnitConversionService $unitConversionService,
        private ActivityLogger $activity,
        private CartPricing $pricing,
    ) {}

    /**
     * Places an order for a cart: prices it on the server, applies the discounts, records the order with its lines and
     * payment, and takes the stock out. Wrapped in a transaction, and idempotent for a repeated offline id. Whatever
     * brought the sale (the till, a storefront) can add attributes of its own to the order through $attributes.
     *
     * @param  array<array{product_id: int, quantity: float, unit_id?: int, unit_price?: float, original_price?: float, original_currency?: string}>  $cartItems
     * @param  array<string, mixed>  $attributes  Extra columns for the order, such as the shift the till belongs to.
     */
    public function execute(
        Branch $branch,
        array $cartItems,
        float $cashReceived,
        User $cashier,
        ?string $discountType = null,
        float $discountValue = 0,
        ?int $customerId = null,
        string $paymentType = 'cash',
        ?string $offlineId = null,
        ?string $couponCode = null,
        array $attributes = [],
    ): Order {
        if (empty($cartItems)) {
            throw new InvalidArgumentException('Cart cannot be empty.');
        }

        return DB::transaction(function () use ($branch, $cartItems, $cashReceived, $cashier, $discountType, $discountValue, $customerId, $paymentType, $offlineId, $couponCode, $attributes) {
            // Locking the branch location serialises checkouts, so concurrent sales cannot oversell
            // stock or generate the same order number.
            $location = Location::query()
                ->where('branch_id', $branch->id)
                ->where('type', 'branch')
                ->lockForUpdate()
                ->firstOrFail();

            if ($offlineId !== null) {
                $existing = Order::query()
                    ->where('tenant_id', $branch->tenant_id)
                    ->where('offline_id', $offlineId)
                    ->first();

                if ($existing) {
                    return $existing->load(['items.product', 'payment']);
                }
            }

            $customer = $customerId !== null ? Customer::where('tenant_id', $branch->tenant_id)->whereKey($customerId)->first() : null;

            if ($customerId !== null && $customer === null) {
                throw new InvalidArgumentException('Customer not found.');
            }

            if ($paymentType === 'debt' && $customerId === null) {
                throw new InvalidArgumentException('A customer is required for credit sales.');
            }

            $tenantCurrency = $branch->tenant->currency ?? 'USD';

            $subtotal = 0;
            $orderItems = [];

            foreach ($cartItems as $item) {
                $product = Product::query()
                    ->where('tenant_id', $branch->tenant_id)
                    ->whereKey($item['product_id'])
                    ->first();

                if (! $product) {
                    throw new InvalidArgumentException('One or more products do not exist.');
                }

                $userQuantity = abs((float) $item['quantity']);
                $unitId = $item['unit_id'] ?? null;

                if ($unitId && ! ProductUnit::where('product_id', $product->id)->where('unit_id', $unitId)->whereIn('allowed_for', ['sell', 'both'])->exists()) {
                    throw new InvalidArgumentException("Unit is not available for {$product->name}.");
                }

                // Convert to base units if a unit is specified
                $baseQuantity = $userQuantity;
                $unitFactor = null;
                if ($unitId) {
                    $conversion = $this->unitConversionService->convertToBase($product->id, $unitId, $userQuantity);
                    $baseQuantity = $conversion['base_quantity'];
                    $unitFactor = $conversion['factor'];
                }

                if (! $this->stockService->hasSufficientStock($product, $location, $baseQuantity) && ! $product->unlimited_quantity) {
                    throw new InvalidArgumentException(
                        "Insufficient stock for {$product->name}. Available: {$this->stockService->getStockAtLocation($product, $location)}"
                    );
                }

                // The server owns pricing. A client price (already converted to the tenant currency, and
                // possibly queued offline) is only honoured when it matches the server price within a small tolerance.
                $serverPrice = round($product->getSellingPriceIn($tenantCurrency) ?? $product->getSellingPrice(), 2);
                $unitPrice = $serverPrice;

                if (isset($item['unit_price'])) {
                    $clientPrice = (float) $item['unit_price'];

                    if (abs($clientPrice - $serverPrice) > max(0.02, $serverPrice * 0.01)) {
                        throw new InvalidArgumentException("Price changed for {$product->name}. Refresh the product list and try again.");
                    }

                    $unitPrice = $clientPrice;
                }
                $lineTotal = $unitPrice * $userQuantity;
                $subtotal += $lineTotal;

                $orderItems[] = [
                    'product' => $product,
                    'user_quantity' => $userQuantity,
                    'base_quantity' => $baseQuantity,
                    'unit_id' => $unitId,
                    'unit_quantity' => $unitId ? $userQuantity : null,
                    'unit_to_base_factor' => $unitFactor,
                    'unit_price' => $unitPrice,
                    'cost_price' => (float) $product->cost_price,
                    'total' => $lineTotal,
                    'original_price' => $item['original_price'] ?? $unitPrice,
                    'original_currency' => $item['original_currency'] ?? null,
                ];
            }

            $cartLines = [];

            foreach ($orderItems as $index => $item) {
                $cartLines[] = new CartLine($index, $item['product']->id, $item['product']->category_id, $item['user_quantity'], $item['base_quantity'], $item['unit_price']);
            }

            $discounts = $this->pricing->evaluate(new PricingContext(
                tenantId: $branch->tenant_id,
                branchId: $branch->id,
                customerId: $customerId,
                customerGroupId: $customer?->customer_group_id,
                paymentType: $paymentType,
                now: CarbonImmutable::now($branch->tenant->timezone ?: config('app.timezone')),
                lines: $cartLines,
                couponCode: $couponCode,
            ));

            if ($couponCode !== null && $couponCode !== '' && $discounts->couponStatus !== DiscountResult::COUPON_APPLIED) {
                throw new InvalidArgumentException(match ($discounts->couponStatus) {
                    DiscountResult::COUPON_EXHAUSTED => 'This coupon has reached its usage limit.',
                    DiscountResult::COUPON_NOT_APPLICABLE => 'This coupon does not apply to the items in the cart.',
                    default => 'This coupon code is not valid.',
                });
            }

            $rulesDiscount = $discounts->total();
            $payable = $subtotal - $rulesDiscount;

            $manualDiscount = 0;
            if ($discountType && $discountValue > 0) {
                $manualDiscount = $discountType === 'percentage'
                    ? round($payable * ($discountValue / 100), 2)
                    : min($discountValue, $payable);
            }

            $discountAmount = round($rulesDiscount + $manualDiscount, 2);
            $total = $subtotal - $discountAmount;

            if ($paymentType === 'cash' && $cashReceived < $total) {
                throw new InvalidArgumentException(
                    "Insufficient cash. Total: {$total}, Received: {$cashReceived}"
                );
            }

            // Generate order number
            $orderNumber = $this->generateOrderNumber($branch);

            // Create order
            $isDebt = $paymentType === 'debt';
            $order = Order::create([
                ...$attributes,
                'tenant_id' => $branch->tenant_id,
                'branch_id' => $branch->id,
                'order_number' => $orderNumber,
                'offline_id' => $offlineId,
                'subtotal' => $subtotal,
                'total' => $total,
                'discount_type' => $discountType,
                'discount_value' => $discountValue,
                'discount_amount' => $discountAmount,
                'rules_discount_amount' => $rulesDiscount,
                'status' => $isDebt ? OrderStatus::Debt : OrderStatus::Completed,
                'cashier_id' => $cashier->id,
                'customer_id' => $customerId,
                'payment_type' => $paymentType,
                'debt_status' => $isDebt ? 'unpaid' : null,
                'debt_paid_at' => null,
            ]);

            // Create order items and deduct stock
            $createdItems = [];

            foreach ($orderItems as $index => $item) {
                $createdItems[$index] = $order->items()->create([
                    'product_id' => $item['product']->id,
                    'quantity' => $item['base_quantity'],
                    'unit_id' => $item['unit_id'],
                    'unit_quantity' => $item['unit_quantity'],
                    'unit_to_base_factor' => $item['unit_to_base_factor'],
                    'unit_price' => $item['unit_price'],
                    'cost_price' => $item['cost_price'],
                    'total' => $item['total'],
                    'discount_amount' => $cartLines[$index]->discount(),
                    'original_price' => $item['original_price'],
                    'original_currency' => $item['original_currency'],
                ]);

                // Deduct stock (always in base units)
                if (! $item['product']->unlimited_quantity) {
                    StockMovement::create([
                        'tenant_id' => $branch->tenant_id,
                        'product_id' => $item['product']->id,
                        'location_id' => $location->id,
                        'type' => StockMovementType::Sale,
                        'quantity' => -$item['base_quantity'],
                        'unit_id' => $item['unit_id'],
                        'unit_quantity' => $item['unit_quantity'],
                        'unit_to_base_factor' => $item['unit_to_base_factor'],
                        'reference_type' => Order::class,
                        'reference_id' => $order->id,
                        'created_by' => $cashier->id,
                    ]);
                }
            }

            $this->pricing->record($order->id, $discounts, array_map(fn ($item): int => $item->id, $createdItems));

            // Create payment
            $order->payment()->create([
                'amount' => $total,
                'cash_received' => $isDebt ? 0 : $cashReceived,
                'change_amount' => $isDebt ? 0 : ($cashReceived - $total),
                'payment_method' => $isDebt ? 'debt' : 'cash',
            ]);

            $this->activity->record(
                ActivityEvent::OrderCreated,
                "Sale {$order->order_number}",
                $order,
                ['total' => (float) $total, 'payment_type' => $paymentType],
                $cashier,
            );

            return $order->load(['items.product', 'payment']);
        });
    }

    private function generateOrderNumber(Branch $branch): string
    {
        $today = now()->format('Ymd');
        $prefix = 'ORD-'.$branch->id.'-'.$today.'-';

        $lastOrder = Order::query()
            ->where('branch_id', $branch->id)
            ->where('order_number', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->first();

        if ($lastOrder) {
            $lastNumber = (int) str_replace($prefix, '', $lastOrder->order_number);
            $nextNumber = $lastNumber + 1;
        } else {
            $nextNumber = 1;
        }

        return $prefix.str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
    }
}
