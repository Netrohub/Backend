<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Listing extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'title',
        'description',
        'price',
        'category',
        'images',
        'status',
        'views',
    ];

    protected function casts(): array
    {
        return [
            'images' => 'array',
            'price' => 'decimal:2',
            'views' => 'integer',
        ];
    }

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    
    /**
     * Get listings only from non-deleted users
     * Use this scope when displaying public listings to exclude those from deleted users
     */
    public function scopeFromActiveUsers($query)
    {
        return $query->whereHas('user', function ($q) {
            $q->withoutTrashed();
        });
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}
