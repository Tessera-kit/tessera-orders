<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The till records what discount rules took off a sale, whether or not the Discounts module is installed. These
 * columns used to be added by the Discounts migration, so databases that already have them are left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('order_items', 'discount_amount')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->decimal('discount_amount', 12, 2)->default(0);
            });
        }

        if (! Schema::hasColumn('orders', 'rules_discount_amount')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->decimal('rules_discount_amount', 12, 2)->default(0);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'rules_discount_amount')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('rules_discount_amount');
            });
        }

        if (Schema::hasColumn('order_items', 'discount_amount')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->dropColumn('discount_amount');
            });
        }
    }
};
