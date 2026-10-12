<?php

namespace App\Modules\Orders\Enums;

enum OrderStatus: string
{
    case Completed = 'completed';
    case Voided = 'voided';
    case Debt = 'debt';
}
