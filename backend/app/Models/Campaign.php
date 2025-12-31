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
        'message',
        'template_name',
        'template_parameters',
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

    public function updateCounts(): void
    {
        $this->sent_count = $this->messages()->where('status', 'sent')->count();
        $this->failed_count = $this->messages()->where('status', 'failed')->count();
        $this->pending_count = $this->messages()->where('status', 'pending')->count();
        $this->save();
    }
}
