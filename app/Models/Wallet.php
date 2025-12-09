<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Wallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'available_balance',
        'on_hold_balance',
        'withdrawn_total',
    ];

    protected function casts(): array
    {
        return [
            'available_balance' => 'decimal:2',
            'on_hold_balance' => 'decimal:2',
            'withdrawn_total' => 'decimal:2',
        ];
    }

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
