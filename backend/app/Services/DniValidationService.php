<?php

namespace App\Services;

use App\Models\Consulta;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DniValidationService
{
    private string $apiUrl;

    public function __construct()
    {
        $this->apiUrl = config('services.dni_validation.url', 'https://backend.casabonita.pe/api/status-customer');
    }

    /**
     * Validar un DNI contra el endpoint externo.
     *
     * Respuestas esperadas del API:
     *   - HTTP 200 + true   → 'apto'
     *   - HTTP 200 + false  → 'no_apto'
     *   - HTTP 422          → 'no_encontrado'
     *
     * @param string $dni
     * @return array ['resultado' => string, 'status_http' => int, 'tiempo_ms' => int]
     * @throws \Exception Si hay error de conexión
     */
    public function validar(string $dni): array
    {
        $startTime = microtime(true);

        try {
            $response = Http::timeout(5)->get($this->apiUrl, [
                'dni' => $dni,
            ]);

            $tiempoMs = (int) round((microtime(true) - $startTime) * 1000);
            $statusHttp = $response->status();

            Log::info('DniValidationService: API response', [
                'dni'         => $dni,
                'status_http' => $statusHttp,
                'body'        => $response->body(),
                'tiempo_ms'   => $tiempoMs,
            ]);

            // HTTP 422, 404, 400 → no encontrado
            if (in_array($statusHttp, [422, 404, 400])) {
                return [
                    'resultado'   => 'no_encontrado',
                    'status_http' => $statusHttp,
                    'tiempo_ms'   => $tiempoMs,
                ];
            }

            // HTTP 200 → interpretar body
            if ($response->successful()) {
                $body = $response->json();

                // Caso especial: la API responde HTTP 200 pero el JSON indica que no fue encontrado o es inválido
                if (isset($body['errors']) || (isset($body['message']) && str_contains(strtolower($body['message']), 'invalido'))) {
                    return [
                        'resultado'   => 'no_encontrado',
                        'status_http' => $statusHttp,
                        'tiempo_ms'   => $tiempoMs,
                    ];
                }

                // El endpoint puede devolver el valor directamente o envuelto en un campo
                $apiValue = is_array($body) ? ($body['is_value'] ?? $body['status'] ?? $body['result'] ?? $body['data'] ?? $body) : $body;

                $resultado = Consulta::transformarResultado($apiValue);

                return [
                    'resultado'   => $resultado,
                    'status_http' => $statusHttp,
                    'tiempo_ms'   => $tiempoMs,
                ];
            }

            // Cualquier otro HTTP code
            Log::warning('DniValidationService: Unexpected HTTP status', [
                'dni'         => $dni,
                'status_http' => $statusHttp,
            ]);

            return [
                'resultado'   => 'no_encontrado',
                'status_http' => $statusHttp,
                'tiempo_ms'   => $tiempoMs,
            ];

        } catch (\Exception $e) {
            $tiempoMs = (int) round((microtime(true) - $startTime) * 1000);

            Log::error('DniValidationService: API connection error', [
                'dni'       => $dni,
                'error'     => $e->getMessage(),
                'tiempo_ms' => $tiempoMs,
            ]);

            throw $e;
        }
    }

    /**
     * Registrar un evento en la tabla consultas.
     * Siempre INSERT, nunca UPDATE.
     *
     * @param array $data
     * @return Consulta
     */
    public function registrarEvento(array $data): Consulta
    {
        $consulta = Consulta::create([
            'dni'                 => $data['dni'],
            'tipo_evento'         => $data['tipo_evento'] ?? 'consulta',
            'resultado'           => $data['resultado'] ?? null,
            'telefono_origen'     => $data['telefono_origen'] ?? null,
            'comercio_id'         => $data['comercio_id'] ?? null,
            'flujo_tipo'          => $data['flujo_tipo'] ?? 'normal',
            'canal'               => $data['canal'] ?? 'whatsapp',
            'status_http'         => $data['status_http'] ?? null,
            'tiempo_respuesta_ms' => $data['tiempo_respuesta_ms'] ?? null,
            'fecha_consulta'      => now(),
        ]);

        Log::info('DniValidationService: Evento registrado', [
            'consulta_id' => $consulta->id,
            'dni'         => $data['dni'],
            'resultado'   => $data['resultado'] ?? null,
            'comercio_id' => $data['comercio_id'] ?? null,
        ]);

        return $consulta;
    }
}
