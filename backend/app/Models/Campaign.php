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
        'image_link',
        'image_media_id',
        'status',
        'total_contacts',
        'sent_count',
        'failed_count',
        'pending_count',
        'read_count',
        'replied_count',
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
        $this->read_count = $this->messages()->where('status', 'read')->count();
        $this->save();
    }

    /**
     * Calcular porcentaje de éxito (mensajes leídos)
     */
    public function getSuccessRateAttribute(): float
    {
        if ($this->sent_count === 0) {
            return 0;
        }
        return round(($this->read_count / $this->sent_count) * 100, 2);
    }

    /**
     * Calcular porcentaje de respuesta
     */
    public function getReplyRateAttribute(): float
    {
        if ($this->sent_count === 0) {
            return 0;
        }
        return round(($this->replied_count / $this->sent_count) * 100, 2);
    }
}
