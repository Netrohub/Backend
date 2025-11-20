<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'tap_charge_id',
        'tap_reference',
        'paylink_transaction_no',
        'status',
        'amount',
        'currency',
        'tap_response',
        'paylink_response',
        'webhook_payload',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'tap_response' => 'array',
            'paylink_response' => 'array',
            'webhook_payload' => 'array',
            'captured_at' => 'datetime',
        ];
    }

    // Relationships
    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
