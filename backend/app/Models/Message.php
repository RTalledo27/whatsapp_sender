<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'contact_id',
        'phone',
        'phone_number',
        'message',
        'status',
        'whatsapp_message_id',
        'error_message',
        'sent_at',
        'direction',
        'message_timestamp',
        'read_at',
        'delivered_at',
        'message_content',
        'error',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'message_timestamp' => 'datetime',
        'read_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
