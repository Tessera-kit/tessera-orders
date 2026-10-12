<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete()->after('cashier_id');
            $table->enum('payment_type', ['cash', 'debt'])->default('cash')->after('customer_id');
            $table->enum('debt_status', ['unpaid', 'paid'])->nullable()->after('payment_type');
            $table->timestamp('debt_paid_at')->nullable()->after('debt_status');

            $table->index('payment_type');
            $table->index(['customer_id', 'debt_status']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropIndex(['payment_type']);
            $table->dropIndex(['customer_id', 'debt_status']);
            $table->dropColumn(['customer_id', 'payment_type', 'debt_status', 'debt_paid_at']);
        });
    }
};
