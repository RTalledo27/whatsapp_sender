<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\BotConversation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class LogicWareService
{
    private string $apiUrl;
    private string $apiKey;
    private string $subdomain;
    private string $version;
    private string $portalCode;
    private string $projectCode;

    public function __construct()
    {
        $this->apiUrl = config('services.logicware.api_url', 'https://gw.logicwareperu.com');
        $this->apiKey = config('services.logicware.api_key');
        $this->subdomain = config('services.logicware.subdomain');
        $this->version = config('services.logicware.version', 'v1.0');
        $this->portalCode = config('services.logicware.portal_code', 'WHATSAPP_BOT');
        $this->projectCode = config('services.logicware.project_code', 'CASABONITA');
    }

    /**
     * Obtener token válido con cache (55 minutos)
     */
    public function getValidToken(): ?string
    {
        $cacheKey = 'logicware_token';
        
        return Cache::remember($cacheKey, 55 * 60, function () {
            try {
                Log::info('LogicWare: Obtaining new token');
                
                $response = Http::withoutVerifying()
                    ->timeout(10)
                    ->withHeaders([
                        'X-API-Key' => $this->apiKey,
                        'X-Subdomain' => $this->subdomain,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json'
                    ])
                    ->post("{$this->apiUrl}/auth/external/token");

                if ($response->successful()) {
                    $data = $response->json();
                    $token = $data['data']['accessToken'] ?? null;
                    
                    if ($token) {
                        Log::info('LogicWare: Token obtained successfully');
                        return $token;
                    }
                }

                Log::error('LogicWare: Failed to obtain token', [
                    'status' => $response->status(),
                    'response' => $response->json()
                ]);
                
                return null;

            } catch (\Exception $e) {
                Log::error('LogicWare: Exception obtaining token', [
                    'error' => $e->getMessage()
                ]);
                return null;
            }
        });
    }

    /**
     * Crear/Reactivar lead calificado en LogicWare CRM
     * 
     * Este método envía leads que YA ESTÁN en el CRM para reactivarlos
     * cuando califican a través del bot de WhatsApp
     */
    public function createQualifiedLead(Contact $contact, BotConversation $conversation): array
    {
        try {
            // Verificar si ya fue enviado
            if ($this->wasAlreadySentToCRM($contact)) {
                Log::info('LogicWare: Lead already sent to CRM, skipping', [
                    'contact_id' => $contact->id
                ]);
                return [
                    'success' => true,
                    'already_sent' => true,
                    'message' => 'Lead was already sent to CRM'
                ];
            }

            $token = $this->getValidToken();
            
            if (!$token) {
                return [
                    'success' => false,
                    'error' => 'Failed to obtain access token'
                ];
            }

            // Construir payload del lead
            $leadData = $this->buildLeadPayload($contact, $conversation);
            
            Log::info('LogicWare: Sending qualified lead', [
                'contact_id' => $contact->id,
                'phone' => $contact->phone_number,
                'portal_code' => $leadData['portalCode'],
                'project_code' => $leadData['projectCode']
            ]);

            // Enviar a LogicWare API
            $response = Http::withoutVerifying()
                ->timeout(30)
                ->withHeaders([
                    'Authorization' => "Bearer {$token}",
                    'X-Subdomain' => $this->subdomain,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'User-Agent' => 'MASIVO-WSP-Bot/1.0'
                ])
                ->post("{$this->apiUrl}/external/leads/create", $leadData);

            if ($response->successful()) {
                $data = $response->json();
                
                if ($data['succeeded'] ?? false) {
                    // Guardar información del CRM en metadata
                    $this->markAsSentToCRM($contact, $data);
                    
                    // Actualizar contexto de conversación
                    $this->updateConversationContext($conversation, $data);
                    
                    Log::info('LogicWare: Lead created/reactivated successfully', [
                        'contact_id' => $contact->id,
                        'lead_id' => $data['data']['leadId'] ?? null,
                        'assigned_to' => $data['data']['assignedTo'] ?? null
                    ]);
                    
                    return [
                        'success' => true,
                        'lead_id' => $data['data']['leadId'] ?? null,
                        'assigned_to' => $data['data']['assignedTo'] ?? null,
                        'data' => $data
                    ];
                }
            }

            // Error en la respuesta
            Log::error('LogicWare: Failed to create lead', [
                'contact_id' => $contact->id,
                'status' => $response->status(),
                'response' => $response->json()
            ]);

            return [
                'success' => false,
                'error' => $response->json()['message'] ?? 'Unknown error from LogicWare API',
                'status_code' => $response->status(),
                'response' => $response->json()
            ];

        } catch (\Exception $e) {
            Log::error('LogicWare: Exception creating lead', [
                'contact_id' => $contact->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Construir payload del lead según especificaciones de LogicWare
     */
    private function buildLeadPayload(Contact $contact, BotConversation $conversation): array
    {
        // Parsear nombre completo en partes
        $nameParts = preg_split('/\s+/', trim($contact->name), 3);
        $firstName = $nameParts[0] ?? $contact->name;
        $paternalLastname = $nameParts[1] ?? '';
        $maternalLastname = $nameParts[2] ?? '';

        // Formatear teléfono (remover caracteres no numéricos)
        $phoneClean = preg_replace('/[^0-9]/', '', $contact->phone_number);
        
        // Asegurar que tenga el prefijo +
        if (!str_starts_with($phoneClean, '51')) {
            $phoneClean = '51' . $phoneClean;
        }
        $phoneNumber = '+' . $phoneClean;

        // Construir payload con campos REQUERIDOS
        $payload = [
            'portalCode' => $this->portalCode,        // REQUERIDO
            'projectCode' => $this->projectCode,      // REQUERIDO
            'documentType' => 1,                       // REQUERIDO (1 = DNI)
            'firstName' => $firstName,                 // REQUERIDO
            'phoneNumber' => $phoneNumber,             // REQUERIDO (al menos email o phone)
        ];

        // Agregar campos OPCIONALES si existen
        if (!empty($paternalLastname)) {
            $payload['paternalLastname'] = $paternalLastname;
        }
        
        if (!empty($maternalLastname)) {
            $payload['maternalLastname'] = $maternalLastname;
        }

        if (!empty($contact->email)) {
            $payload['email'] = $contact->email;
        }

        // Construir comentario con información del bot
        $comment = $this->buildCommentFromBot($conversation);
        if ($comment) {
            $payload['comment'] = $comment;
        }

        return $payload;
    }

    /**
     * Construir comentario con información de la evaluación del bot
     */
    private function buildCommentFromBot(BotConversation $conversation): string
    {
        $context = $conversation->context ?? [];
        $responses = $context['responses'] ?? [];
        
        $lines = [
            '🤖 LEAD REACTIVADO - BOT WHATSAPP',
            '✅ Cliente calificado para Bono Techo Propio',
            '',
            '📝 Respuestas del cliente:',
        ];

        // Mapeo de preguntas (actualizado según el flows.json)
        $questions = [
            'terrain' => '¿Tiene terreno propio inscrito en Registros Públicos?',
            'family' => '¿Tiene carga familiar?',
            'income' => '¿Ingreso familiar menor a S/3,715?',
            'previous_support' => '¿Recibió apoyo previo del Estado?'
        ];

        // Respuestas esperadas para calificar: No, Sí, Sí, No
        foreach ($responses as $key => $value) {
            if (isset($questions[$key])) {
                // Determinar el emoji según la respuesta y si califica
                $isQualifyingAnswer = ($key === 'terrain' && $value === 'No') ||
                                     ($key === 'family' && $value === 'Sí') ||
                                     ($key === 'income' && $value === 'Sí') ||
                                     ($key === 'previous_support' && $value === 'No');
                $emoji = $isQualifyingAnswer ? '✅' : '❌';
                $lines[] = "{$emoji} {$questions[$key]} → {$value}";
            }
        }

        $lines[] = '';
        $lines[] = '📅 Fecha de calificación: ' . now()->format('d/m/Y H:i');
        $lines[] = '📱 Canal: WhatsApp Business API';
        $lines[] = '🔄 Estado: Lead reactivado automáticamente';

        return implode("\n", $lines);
    }

    /**
     * Marcar contacto como enviado al CRM
     */
    private function markAsSentToCRM(Contact $contact, array $crmResponse): void
    {
        $metadata = $contact->metadata ?? [];
        
        $metadata['crm_sent'] = true;
        $metadata['crm_sent_at'] = now()->toIso8601String();
        $metadata['crm_lead_id'] = $crmResponse['data']['leadId'] ?? null;
        $metadata['crm_assigned_to'] = $crmResponse['data']['assignedTo'] ?? null;
        $metadata['crm_status'] = 'reactivated';
        
        $contact->metadata = $metadata;
        $contact->save();
    }

    /**
     * Actualizar contexto de la conversación con info del CRM
     */
    private function updateConversationContext(BotConversation $conversation, array $crmResponse): void
    {
        $context = $conversation->context ?? [];
        
        $context['crm_sent'] = true;
        $context['crm_sent_at'] = now()->toIso8601String();
        $context['crm_lead_id'] = $crmResponse['data']['leadId'] ?? null;
        $context['crm_assigned_to'] = $crmResponse['data']['assignedTo'] ?? null;
        
        $conversation->context = $context;
        $conversation->save();
    }

    /**
     * Verificar si un contacto ya fue enviado al CRM
     */
    public function wasAlreadySentToCRM(Contact $contact): bool
    {
        $metadata = $contact->metadata ?? [];
        return isset($metadata['crm_sent']) && $metadata['crm_sent'] === true;
    }

    /**
     * Limpiar cache del token (útil para testing o cuando expira)
     */
    public function clearTokenCache(): void
    {
        Cache::forget('logicware_token');
        Log::info('LogicWare: Token cache cleared');
    }
}
