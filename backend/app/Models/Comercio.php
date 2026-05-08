<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Comercio extends Model
{
    use HasFactory;

    protected $table = 'comercios';

    protected $fillable = [
        'nombre',
        'estado',
        'flow_id',
    ];

    /**
     * Teléfonos asociados al comercio
     */
    public function telefonos(): HasMany
    {
        return $this->hasMany(ComercioTelefono::class, 'comercio_id');
    }

    /**
     * Consultas/eventos asociados al comercio
     */
    public function consultas(): HasMany
    {
        return $this->hasMany(Consulta::class, 'comercio_id');
    }

    /**
     * Scope: solo comercios activos
     */
    public function scopeActivos($query)
    {
        return $query->where('estado', 'activo');
    }
}
