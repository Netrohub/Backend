<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Review extends Model
{
    protected $fillable = [
        'order_id',
        'seller_id',
        'reviewer_id',
        'rating',
        'comment',
    ];

    protected $casts = [
        'rating' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $with = ['reviewer'];

    /**
     * Get the order associated with this review
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the seller being reviewed
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    /**
     * Get the reviewer
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /**
     * Users who found this review helpful
     */
    public function helpfulVoters(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'review_helpful', 'review_id', 'user_id')
            ->withTimestamps();
    }

    /**
     * Check if a user found this review helpful
     */
    public function foundHelpfulByUser($userId): bool
    {
        return $this->helpfulVoters()->where('user_id', $userId)->exists();
    }

    /**
     * Get helpful count
     */
    public function getHelpfulCountAttribute(): int
    {
        return $this->helpfulVoters()->count();
    }
}
