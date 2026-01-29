<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class WhatsAppService
{
    private string $apiUrl;
    private string $accessToken;
    private string $phoneNumberId;
    private string $version;

    public function __construct(?string $phoneNumberId = null, ?string $accessToken = null)
    {
        $this->apiUrl = config('services.whatsapp.api_url');
        $this->version = config('services.whatsapp.version');
        
        if ($phoneNumberId) {
            $numberConfig = $this->getNumberConfig($phoneNumberId);
            $this->phoneNumberId = $phoneNumberId;
            $this->accessToken = $accessToken ?? $numberConfig['access_token'] ?? config('services.whatsapp.access_token');
        } else {
            $this->accessToken = config('services.whatsapp.access_token');
            $this->phoneNumberId = config('services.whatsapp.phone_number_id');
        }
    }
    
    private function getNumberConfig(string $phoneNumberId): array
    {
        $availableNumbers = config('services.whatsapp.available_numbers', []);
        foreach ($availableNumbers as $number) {
            if ($number['id'] === $phoneNumberId) {
                return $number;
            }
        }
        return [];
    }
    
    public static function getAvailableNumbers(): array
    {
        return array_filter(config('services.whatsapp.available_numbers', []), function($number) {
            return !empty($number['id']);
        });
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

    public function uploadMedia(UploadedFile $file): array
    {
        try {
            $url = "{$this->apiUrl}/{$this->version}/{$this->phoneNumberId}/media";
            $mimeType = $file->getMimeType() ?: 'application/octet-stream';
            $extension = strtolower((string) $file->getClientOriginalExtension());

            if ($extension === 'ogg' && ($mimeType === 'application/ogg' || $mimeType === 'application/octet-stream')) {
                $mimeType = 'audio/ogg';
            }
            if ($extension === 'opus' && ($mimeType === 'application/octet-stream' || str_starts_with($mimeType, 'application/'))) {
                $mimeType = 'audio/opus';
            }

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->accessToken}",
            ])->attach(
                'file',
                fopen($file->getRealPath(), 'r'),
                $file->getClientOriginalName()
            )->post($url, [
                'messaging_product' => 'whatsapp',
                'type' => $mimeType,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'success' => true,
                    'media_id' => $data['id'] ?? null,
                    'data' => $data,
                ];
            }

            Log::error('WhatsApp Media Upload Error', [
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            return [
                'success' => false,
                'error' => $response->json()['error']['message'] ?? 'Error desconocido',
                'status_code' => $response->status(),
            ];
        } catch (\Exception $e) {
            Log::error('WhatsApp Media Upload Exception', [
                'message' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function sendMediaMessage(string $phoneNumber, string $type, string $mediaId, ?string $caption = null, ?string $filename = null): array
    {
        try {
            $phoneNumber = $this->formatPhoneNumber($phoneNumber);
            $url = "{$this->apiUrl}/{$this->version}/{$this->phoneNumberId}/messages";

            $payload = [
                'messaging_product' => 'whatsapp',
                'to' => $phoneNumber,
                'type' => $type,
            ];

            $media = [
                'id' => $mediaId,
            ];

            if (!empty($caption) && in_array($type, ['image', 'video', 'document'], true)) {
                $media['caption'] = $caption;
            }

            if (!empty($filename) && $type === 'document') {
                $media['filename'] = $filename;
            }

            $payload[$type] = $media;

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

            Log::error('WhatsApp Media Message Error', [
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            return [
                'success' => false,
                'error' => $response->json()['error']['message'] ?? 'Error desconocido',
                'status_code' => $response->status(),
            ];
        } catch (\Exception $e) {
            Log::error('WhatsApp Media Message Exception', [
                'message' => $e->getMessage(),
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
    public function getTemplates($businessAccountId = null): array
    {
        try {
            $businessAccountId = $businessAccountId ?: config('services.whatsapp.business_account_id');
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
                'error' => $e->getMessage(),
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
                return asset('storage/' . $path);
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
