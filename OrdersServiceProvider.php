<?php

namespace App\Modules\Orders;

use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Exports\OrdersReport;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderCustomerActivity;
use App\Modules\Orders\Services\OrderRevenueSource;
use Tessera\Core\Contracts\CustomerActivity;
use Tessera\Core\Contracts\RevenueSource;
use Tessera\Engine\MenuItem;
use Tessera\Engine\ModuleManifest;
use Tessera\Engine\ModuleServiceProvider;
use Tessera\Engine\PlanFeature;

/**
 * Orders: the record of what was sold, with its lines and payment, the list and the detail of an order, voiding an
 * order and marking a credit sale paid. The till (Pos module) and any other way a sale can arrive place orders through
 * the PlaceOrderAction. Discounts come through a contract.
 */
class OrdersServiceProvider extends ModuleServiceProvider
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'orders',
            name: 'Orders',
            requires: ['catalog', 'tenant', 'customers', 'inventory', 'team'],
        );
    }

    public function features(): array
    {
        return [
            new PlanFeature('orders', 'Orders', ['void orders', 'view orders', 'view all orders', 'export orders'], order: 12),
        ];
    }

    public function permissionGroups(): array
    {
        return [
            'sales' => ['view orders', 'view all orders', 'void orders', 'export orders'],
        ];
    }

    public function defaultRolePermissions(): array
    {
        return [
            'Owner' => ['void orders', 'view orders', 'view all orders', 'export orders'],
            'Manager' => ['void orders', 'view orders', 'view all orders', 'export orders'],
        ];
    }

    public function menu(): array
    {
        return [
            new MenuItem('sales', 'nav.orders', 'orders.index', 'receipt', 'view orders', ['orders.*'], 10),
        ];
    }

    protected function bindings(): void
    {
        $this->app->bind(CustomerActivity::class, OrderCustomerActivity::class);
        $this->app->bind(RevenueSource::class, OrderRevenueSource::class);
    }

    public function boot(): void
    {
        parent::boot();

        Customer::resolveRelationUsing('orders', fn (Customer $customer) => $customer->hasMany(Order::class));
    }

    public function exports(): array
    {
        return [
            OrdersReport::class,
        ];
    }
}
