<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'phone_number_id',
        'phone_number_name',
        'message',
        'template_name',
        'template_parameters',
        'video_link',
        'status',
        'total_contacts',
        'sent_count',
        'failed_count',
        'pending_count',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'template_parameters' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function scopeByPhoneNumberId($query, $phoneNumberId)
    {
        if ($phoneNumberId) {
            return $query->where('phone_number_id', $phoneNumberId);
        }
        return $query;
    }

    public function updateCounts(): void
    {
        $sentStatuses = ['sent', 'delivered', 'read'];
        $this->sent_count = $this->messages()->whereIn('status', $sentStatuses)->count();
        $this->failed_count = $this->messages()->where('status', 'failed')->count();
        $this->pending_count = $this->messages()->where('status', 'pending')->count();
        $this->save();
    }
}
