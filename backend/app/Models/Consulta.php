<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Consulta extends Model
{
    use HasFactory;

    protected $table = 'consultas';

    /**
     * Esta tabla es INSERT-only. No se actualiza.
     */
    const UPDATED_AT = null;

    protected $fillable = [
        'dni',
        'tipo_evento',
        'resultado',
        'telefono_origen',
        'comercio_id',
        'flujo_tipo',
        'canal',
        'status_http',
        'tiempo_respuesta_ms',
        'fecha_consulta',
    ];

    protected $casts = [
        'fecha_consulta' => 'datetime',
        'tiempo_respuesta_ms' => 'integer',
        'status_http' => 'integer',
    ];

    /**
     * Comercio asociado a esta consulta
     */
    public function comercio(): BelongsTo
    {
        return $this->belongsTo(Comercio::class, 'comercio_id');
    }

    /**
     * Transformar la respuesta cruda del API externo al valor enum esperado.
     *
     * @param mixed $apiResponse  true = apto, false = no_apto, null = no_encontrado
     * @return string
     */
    public static function transformarResultado($apiResponse): string
    {
        if ($apiResponse === true || $apiResponse === 1 || $apiResponse === '1') {
            return 'apto';
        }

        if ($apiResponse === false || $apiResponse === 0 || $apiResponse === '0') {
            return 'no_apto';
        }

        return 'no_encontrado';
    }
}
