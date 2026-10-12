<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('offline_id', 64)->nullable()->after('order_number');
            $table->unique(['tenant_id', 'offline_id']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'offline_id']);
            $table->dropColumn('offline_id');
        });
    }
};
