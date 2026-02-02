<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BotConversation extends Model
{
    use HasFactory;

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

    // Estados posibles del bot
    const STATE_INITIAL = 'initial';
    const STATE_WAITING_INTEREST = 'waiting_interest';
    const STATE_WAITING_AGE = 'waiting_age';
    const STATE_WAITING_EMPLOYMENT = 'waiting_employment';
    const STATE_WAITING_INCOME = 'waiting_income';
    const STATE_WAITING_FAMILY_GROUP = 'waiting_family_group';
    const STATE_WAITING_FIRST_HOME = 'waiting_first_home';
    const STATE_QUALIFIED = 'qualified';
    const STATE_NOT_QUALIFIED = 'not_qualified';
    const STATE_NOT_INTERESTED = 'not_interested';
    const STATE_COMPLETED = 'completed';

    /**
     * Relación con Contact
     */
    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Verificar si la conversación está activa (no completada)
     */
    public function isActive(): bool
    {
        return !in_array($this->state, [
            self::STATE_COMPLETED,
            self::STATE_NOT_INTERESTED,
            self::STATE_QUALIFIED,
            self::STATE_NOT_QUALIFIED,
        ]);
    }

    /**
     * Guardar respuesta en el contexto
     */
    public function saveResponse(string $key, $value): void
    {
        $context = $this->context ?? [];
        $context[$key] = $value;
        $this->context = $context;
        $this->last_interaction_at = now();
        $this->save();
    }
}
