<?php

use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Orders\Actions\MarkOrderPaidAction;
use App\Modules\Orders\Actions\VoidOrderAction;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Pos\Actions\CheckoutAction;
use App\Modules\Pos\Actions\HoldOrderAction;
use App\Modules\Pos\Actions\SyncOfflineSalesAction;
use App\Modules\Pos\Models\HeldOrder;
use App\Modules\Team\Models\ActivityLog;
use App\Modules\Team\Models\User;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Location;
use App\Modules\Tenant\Models\Tenant;

function actionSale(User $cashier, Branch $branch, Product $product, string $paymentType = 'cash', ?int $customerId = null): Order
{
    return app(CheckoutAction::class)->execute(
        branch: $branch,
        cartItems: [['product_id' => $product->id, 'quantity' => 2]],
        cashReceived: 100,
        cashier: $cashier,
        customerId: $customerId,
        paymentType: $paymentType,
        requireShift: false,
    );
}

function actionStock(Product $product): float
{
    return (float) StockMovement::where('product_id', $product->id)->sum('quantity');
}

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->location = Location::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'type' => 'branch']);
    $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id]);
    $this->product = Product::factory()->create(['tenant_id' => $this->tenant->id, 'base_price' => 25, 'cost_price' => 10, 'is_active' => true]);
    StockMovement::factory()->create(['tenant_id' => $this->tenant->id, 'product_id' => $this->product->id, 'location_id' => $this->location->id, 'quantity' => 100]);
});

describe('voiding an order', function () {
    it('puts the stock back and logs it', function () {
        $order = actionSale($this->cashier, $this->branch, $this->product);
        expect(actionStock($this->product))->toBe(98.0);

        app(VoidOrderAction::class)->execute($order, $this->cashier);

        expect($order->fresh()->status)->toBe(OrderStatus::Voided)
            ->and(actionStock($this->product))->toBe(100.0)
            ->and(ActivityLog::where('event', 'order.voided')->where('user_id', $this->cashier->id)->count())->toBe(1);
    });

    it('cannot void an order twice', function () {
        $order = actionSale($this->cashier, $this->branch, $this->product);
        app(VoidOrderAction::class)->execute($order, $this->cashier);

        app(VoidOrderAction::class)->execute($order->fresh(), $this->cashier);
    })->throws(InvalidArgumentException::class, 'Order is already voided.');
});

describe('marking an order as paid', function () {
    beforeEach(function () {
        $this->customer = Customer::create(['tenant_id' => $this->tenant->id, 'name' => 'Mona']);
    });

    it('settles a debt order', function () {
        $order = actionSale($this->cashier, $this->branch, $this->product, 'debt', $this->customer->id);
        expect($order->debt_status)->toBe('unpaid');

        app(MarkOrderPaidAction::class)->execute($order);

        expect($order->fresh()->debt_status)->toBe('paid')->and($order->fresh()->debt_paid_at)->not->toBeNull();
    });

    it('rejects cash orders, voided orders and orders already paid', function (Closure $prepare, string $message) {
        $order = $prepare($this);

        expect(fn () => app(MarkOrderPaidAction::class)->execute($order))->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'cash order' => [fn ($test) => actionSale($test->cashier, $test->branch, $test->product), 'Order is not a debt order.'],
        'voided order' => [function ($test) {
            $order = actionSale($test->cashier, $test->branch, $test->product, 'debt', $test->customer->id);
            $order->update(['status' => OrderStatus::Voided]);

            return $order;
        }, 'A voided order cannot be marked as paid.'],
        'already paid' => [function ($test) {
            $order = actionSale($test->cashier, $test->branch, $test->product, 'debt', $test->customer->id);
            app(MarkOrderPaidAction::class)->execute($order);

            return $order->fresh();
        }, 'Order debt is already marked as paid.'],
    ]);
});

describe('holding an order', function () {
    it('parks the cart under the given name', function () {
        $held = app(HoldOrderAction::class)->execute($this->cashier, $this->branch, ['name' => 'Table 4', 'items' => [['product_id' => $this->product->id, 'quantity' => 1]], 'total' => 25]);

        expect($held)->toBeInstanceOf(HeldOrder::class)
            ->and($held->name)->toBe('Table 4')
            ->and($held->cashier_id)->toBe($this->cashier->id)
            ->and($held->branch_id)->toBe($this->branch->id)
            ->and((float) $held->total)->toBe(25.0);
    });

    it('names an unnamed cart after the time', function () {
        $held = app(HoldOrderAction::class)->execute($this->cashier, $this->branch, ['name' => null, 'items' => [], 'total' => 0]);

        expect($held->name)->toStartWith('Order #');
    });
});

describe('syncing offline sales', function () {
    it('rings up each sale on its own and recognises sales synced before', function () {
        $sale = fn (string $id) => ['offline_id' => $id, 'items' => [['product_id' => $this->product->id, 'quantity' => 1]], 'cash_received' => 50];

        $first = app(SyncOfflineSalesAction::class)->execute($this->cashier, $this->branch, [$sale('a-1'), ['offline_id' => 'broken', 'items' => []]]);
        $again = app(SyncOfflineSalesAction::class)->execute($this->cashier, $this->branch, [$sale('a-1')]);

        expect($first['synced'])->toBe(1)->and($first['failed'])->toBe(1)
            ->and($first['results'][0])->toMatchArray(['success' => true, 'duplicate' => false, 'offline_id' => 'a-1'])
            ->and($first['results'][1])->toMatchArray(['success' => false, 'offline_id' => 'broken'])
            ->and($again['results'][0])->toMatchArray(['success' => true, 'duplicate' => true])
            ->and(Order::count())->toBe(1);
    });
});
