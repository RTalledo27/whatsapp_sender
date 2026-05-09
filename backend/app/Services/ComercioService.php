<?php

namespace App\Services;

use App\Models\Comercio;
use App\Models\ComercioTelefono;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ComercioService
{
    /**
     * Detectar si un phone_number_id pertenece a un comercio activo.
     * Resultado cacheado por 5 minutos para evitar consultas repetidas.
     *
     * @param string $phoneNumberId  El phone_number_id del webhook de WhatsApp
     * @return Comercio|null
     */
    public function detectarPorTelefono(string $phoneNumber): ?Comercio
    {
        $phoneNumber = \App\Helpers\PhoneHelper::normalize($phoneNumber);
        $cacheKey = "comercio_telefono_{$phoneNumber}";

        return Cache::remember($cacheKey, 300, function () use ($phoneNumber) {
            $telefono = ComercioTelefono::where('telefono', $phoneNumber)
                ->where('activo', true)
                ->first();

            if (!$telefono) {
                return null;
            }

            $comercio = $telefono->comercio;

            if (!$comercio || $comercio->estado !== 'activo') {
                return null;
            }

            Log::info('ComercioService: Comercio detectado', [
                'comercio_id'     => $comercio->id,
                'comercio_nombre' => $comercio->nombre,
                'phone_number'    => $phoneNumber,
            ]);

            return $comercio;
        });
    }

    /**
     * Obtener el tipo de flujo configurado para un phone_number_id.
     *
     * @param string $phoneNumber
     * @return string  'normal' por defecto
     */
    public function getTipoFlujo(string $phoneNumber): string
    {
        $phoneNumber = \App\Helpers\PhoneHelper::normalize($phoneNumber);
        $telefono = ComercioTelefono::where('telefono', $phoneNumber)
            ->where('activo', true)
            ->first();

        return $telefono->tipo_flujo ?? 'normal';
    }

    /**
     * Limpiar caché de detección para un phone_number_id específico.
     * Llamar cuando se modifica un comercio o sus teléfonos.
     *
     * @param string $phoneNumber
     */
    public function limpiarCache(string $phoneNumber): void
    {
        $phoneNumber = \App\Helpers\PhoneHelper::normalize($phoneNumber);
        Cache::forget("comercio_telefono_{$phoneNumber}");
    }

    /**
     * Limpiar toda la caché de comercios (cuando se hace CRUD masivo).
     * Limpia los teléfonos asociados a un comercio específico.
     *
     * @param Comercio $comercio
     */
    public function limpiarCacheComercio(Comercio $comercio): void
    {
        foreach ($comercio->telefonos as $telefono) {
            $this->limpiarCache($telefono->telefono);
        }
    }
}
