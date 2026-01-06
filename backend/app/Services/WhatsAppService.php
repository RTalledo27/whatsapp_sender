<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class WhatsAppService
{
    private string $apiUrl;
    private string $accessToken;
    private string $phoneNumberId;
    private string $version;

    public function __construct()
    {
        $this->apiUrl = config('services.whatsapp.api_url');
        $this->accessToken = config('services.whatsapp.access_token');
        $this->phoneNumberId = config('services.whatsapp.phone_number_id');
        $this->version = config('services.whatsapp.version');
    }

    /**
     * Enviar mensaje de texto o template a un número
     */
    public function sendMessage(string $phoneNumber, ?string $message = null, ?array $templateData = null): array
    {
        try {
            $phoneNumber = $this->formatPhoneNumber($phoneNumber);
            $url = "{$this->apiUrl}/{$this->version}/{$this->phoneNumberId}/messages";

            $payload = [
                'messaging_product' => 'whatsapp',
                'to' => $phoneNumber,
            ];

            // Si se proporciona template, usarlo
            if ($templateData && isset($templateData['name'])) {
                $payload['type'] = 'template';
                $payload['template'] = [
                    'name' => $templateData['name'],
                    'language' => [
                        'code' => $templateData['language'] ?? 'es'
                    ]
                ];

                // Agregar parámetros si existen y no están vacíos
                if (isset($templateData['parameters']) && is_array($templateData['parameters']) && !empty($templateData['parameters'])) {
                    // Filtrar parámetros vacíos
                    $validParams = array_filter($templateData['parameters'], function($param) {
                        return !empty($param);
                    });

                    if (!empty($validParams)) {
                        $payload['template']['components'] = [
                            [
                                'type' => 'body',
                                'parameters' => array_values(array_map(function($param) {
                                    return ['type' => 'text', 'text' => (string)$param];
                                }, $validParams))
                            ]
                        ];
                    }
                }

                Log::info('Sending WhatsApp Template', [
                    'template_name' => $templateData['name'],
                    'parameters' => $templateData['parameters'] ?? [],
                    'payload' => $payload
                ]);
            } else {
                // Mensaje de texto simple
                $payload['type'] = 'text';
                $payload['text'] = [
                    'preview_url' => false,
                    'body' => $message,
                ];

                Log::info('Sending WhatsApp Text Message', [
                    'message' => $message
                ]);
            }

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->accessToken}",
                'Content-Type' => 'application/json',
            ])->post($url, $payload);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'success' => true,
                    'message_id' => $data['messages'][0]['id'] ?? null,
                    'data' => $data,
                ];
            }

            Log::error('WhatsApp API Error', [
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            return [
                'success' => false,
                'error' => $response->json()['error']['message'] ?? 'Error desconocido',
                'status_code' => $response->status(),
            ];

        } catch (\Exception $e) {
            Log::error('WhatsApp Service Exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Obtener templates aprobados de Meta
     */
    public function getTemplates(): array
    {
        try {
            $businessAccountId = config('services.whatsapp.business_account_id');
            $url = "{$this->apiUrl}/{$this->version}/{$businessAccountId}/message_templates";
            
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->accessToken}",
            ])->get($url, [
                'limit' => 100
            ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'templates' => $response->json()['data'] ?? []
                ];
            }

            Log::error('WhatsApp Templates API Error', [
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            return [
                'success' => false,
                'error' => $response->json()['error']['message'] ?? 'Error desconocido',
                'templates' => []
            ];
        } catch (\Exception $e) {
            Log::error('WhatsApp Templates Exception', [
                'message' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'templates' => []
            ];
        }
    }

    /**
     * Formatear número de teléfono
     */
    private function formatPhoneNumber(string $phoneNumber): string
    {
        // Remover todos los caracteres que no sean dígitos o '+'
        $phoneNumber = preg_replace('/[^0-9+]/', '', $phoneNumber);

        // Si no empieza con +, agregar + (asumiendo formato internacional)
        if (!str_starts_with($phoneNumber, '+')) {
            $phoneNumber = '+' . $phoneNumber;
        }

        return $phoneNumber;
    }

    /**
     * Obtener URL de un archivo multimedia
     */
    public function getMediaUrl(string $mediaId): ?string
    {
        try {
            $url = "{$this->apiUrl}/{$this->version}/{$mediaId}";

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->accessToken}",
            ])->get($url);

            if ($response->successful()) {
                $data = $response->json();
                return $data['url'] ?? null;
            }

            Log::error('Failed to get media URL', [
                'media_id' => $mediaId,
                'response' => $response->json()
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('Exception getting media URL', [
                'media_id' => $mediaId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Descargar archivo multimedia y guardarlo
     */
    public function downloadMedia(string $mediaUrl, string $filename): ?string
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->accessToken}",
            ])->get($mediaUrl);

            if ($response->successful()) {
                $path = "media/{$filename}";
                Storage::disk('public')->put($path, $response->body());
                return Storage::disk('public')->url($path);
            }

            return null;

        } catch (\Exception $e) {
            Log::error('Exception downloading media', [
                'url' => $mediaUrl,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Verificar el estado de un mensaje
     */
    public function getMessageStatus(string $messageId): array
    {
        try {
            $url = "{$this->apiUrl}/{$this->version}/{$messageId}";

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->accessToken}",
            ])->get($url);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                ];
            }

            return [
                'success' => false,
                'error' => $response->json()['error']['message'] ?? 'Error desconocido',
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
