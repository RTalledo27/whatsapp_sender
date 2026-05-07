<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComercioTelefono extends Model
{
    use HasFactory;

    protected $table = 'comercio_telefonos';

    protected $fillable = [
        'comercio_id',
        'telefono',
        'tipo_flujo',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    /**
     * Comercio al que pertenece este teléfono
     */
    public function comercio(): BelongsTo
    {
        return $this->belongsTo(Comercio::class, 'comercio_id');
    }
}
