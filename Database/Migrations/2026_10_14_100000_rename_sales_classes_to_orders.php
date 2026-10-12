<?php

use Illuminate\Database\Migrations\Migration;
use Tessera\Core\Database\StoredClassNames;

/**
 * Order, OrderItem and Payment moved from the Sales module to the Orders module.
 */
return new class extends Migration
{
    public function up(): void
    {
        StoredClassNames::rewrite('App\\Modules\\Sales\\Models\\Order', 'App\\Modules\\Orders\\Models\\Order');
        StoredClassNames::rewrite('App\\Modules\\Sales\\Models\\Payment', 'App\\Modules\\Orders\\Models\\Payment');
    }

    public function down(): void {}
};
