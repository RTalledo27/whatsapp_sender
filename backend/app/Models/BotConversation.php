<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotConversation extends Model
{
    protected $fillable = [
        'contact_id',
        'phone_number_id',
        'state',
        'context',
        'last_interaction_at',
    ];

    protected $casts = [
        'context' => 'array',
        'last_interaction_at' => 'datetime',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
