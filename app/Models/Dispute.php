<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Dispute extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'order_id',
        'initiated_by',
        'party',
        'reason',
        'description',
        'status',
        'resolved_by',
        'resolution_notes',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    // Relationships
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function initiator()
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
    
    /**
     * Get disputes only from active users and orders
     * Use this scope when displaying disputes to exclude those involving deleted users/orders
     */
    public function scopeWithActiveRelations($query)
    {
        return $query->whereHas('order', function ($q) {
            $q->withoutTrashed();
        })->whereHas('initiator', function ($q) {
            $q->withoutTrashed();
        });
    }
}
