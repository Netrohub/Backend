<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SuggestionVote extends Model
{
    use HasFactory;

    protected $fillable = [
        'suggestion_id',
        'user_id',
        'vote_type',
    ];

    public function suggestion()
    {
        return $this->belongsTo(Suggestion::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
