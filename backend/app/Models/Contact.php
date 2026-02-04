<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    use HasFactory;

    protected $fillable = [
        'phone_number',
        'name',
        'email',
        'contact_type',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    /**
     * Scope para filtrar solo leads
     */
    public function scopeLeads($query)
    {
        return $query->where('contact_type', 'lead');
    }

    /**
     * Scope para filtrar solo clientes
     */
    public function scopeClients($query)
    {
        return $query->where('contact_type', 'client');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function botConversations(): HasMany
    {
        return $this->hasMany(BotConversation::class);
    }
}
