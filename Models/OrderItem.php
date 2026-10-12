<?php

namespace App\Modules\Orders\Models;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'product_id',
        'quantity',
        'unit_id',
        'unit_quantity',
        'unit_to_base_factor',
        'unit_price',
        'cost_price',
        'total',
        'discount_amount',
        'original_price',
        'original_currency',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_quantity' => 'decimal:4',
            'unit_to_base_factor' => 'decimal:4',
            'unit_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'total' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'original_price' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
