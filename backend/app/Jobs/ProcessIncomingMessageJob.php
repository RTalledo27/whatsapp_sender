<?php

namespace App\Jobs;

use App\Helpers\PhoneHelper;
use App\Models\Contact;
use App\Models\Message;
use App\Services\BotService;
use App\Services\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job para procesar mensajes entrantes del webhook de WhatsApp de forma asíncrona.
 *
 * Beneficios:
 *  - El webhook de Meta recibe respuesta inmediata (< 200ms), evitando reintentos automáticos.
 *  - La descarga de media y el procesamiento del bot no bloquean el hilo del servidor.
 *  - Con backoff exponencial, los fallos transitorios (media API down) se recuperan solos.
 */
class ProcessIncomingMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Número de intentos antes de fallar definitivamente.
     */
    public int $tries = 3;

    /**
     * Timeout del job en segundos.
     * Cubre: descarga de media (hasta 20s) + procesamiento bot + CRM check.
     */
    public int $timeout = 90;

    /**
     * Backoff entre reintentos en segundos.
     */
    public array $backoff = [10, 30, 60];

    /**
     * Datos del mensaje entrante del webhook de Meta.
     */
    protected array $messageData;

    /**
     * Datos del value del change (contiene metadata como phone_number_id).
     */
    protected array $valueData;

    public function __construct(array $messageData, array $valueData)
    {
        $this->messageData = $messageData;
        $this->valueData   = $valueData;
    }

    /**
     * Procesar el mensaje entrante de forma asíncrona.
     */
    public function handle(WhatsAppService $whatsappService, BotService $botService): void
    {
        $message         = $this->messageData;
        $value           = $this->valueData;
        $phoneNumber     = $message['from'] ?? null;
        $messageId       = $message['id'] ?? null;
        $timestamp       = $message['timestamp'] ?? null;
        $messageType     = $message['type'] ?? 'text';
        $phoneNumberId   = $value['metadata']['phone_number_id'] ?? null;

        if (!$phoneNumber || !$messageId) {
            Log::warning('[ProcessIncomingMessageJob] Missing required fields', [
                'phone'      => $phoneNumber,
                'message_id' => $messageId,
            ]);
            return;
        }

        // Verificar duplicado: si ya existe un mensaje con este whatsapp_message_id, ignorar
        if (Message::where('whatsapp_message_id', $messageId)->exists()) {
            Log::info('[ProcessIncomingMessageJob] Duplicate message_id, skipping', ['wamid' => $messageId]);
            return;
        }

        $phoneNumber = PhoneHelper::normalize($phoneNumber);

        Log::info('[ProcessIncomingMessageJob] Processing incoming message', [
            'phone'          => $phoneNumber,
            'message_id'     => $messageId,
            'message_type'   => $messageType,
            'phone_number_id' => $phoneNumberId,
        ]);

        // Buscar o crear contacto
        $leadsPhoneNumberId = config('services.whatsapp.leads_bot_id');
        $contactType        = ($phoneNumberId === $leadsPhoneNumberId) ? 'lead' : 'client';

        $contact = Contact::firstOrCreate(
            ['phone_number' => $phoneNumber],
            [
                'name'         => $phoneNumber,
                'contact_type' => $contactType,
            ]
        );

        // Manejar reacciones
        if ($messageType === 'reaction') {
            $this->handleReaction($message, $contact);
            return;
        }

        // Extraer datos del mensaje
        $messageData = $this->extractMessageData($message);

        // Descargar media si la hay
        $mediaUrl      = null;
        $localMediaUrl = null;

        if (!empty($messageData['media_id'])) {
            try {
                $mediaUrl = $whatsappService->getMediaUrl($messageData['media_id']);

                if ($mediaUrl) {
                    $extension = match ($messageData['type']) {
                        'image'    => 'jpg',
                        'video'    => 'mp4',
                        'audio'    => 'ogg',
                        'sticker'  => 'webp',
                        'document' => 'pdf',
                        default    => 'file',
                    };

                    $filename      = $messageData['media_id'] . '.' . $extension;
                    $localMediaUrl = $whatsappService->downloadMedia($mediaUrl, $filename);

                    if ($localMediaUrl) {
                        Log::info('[ProcessIncomingMessageJob] Media downloaded', [
                            'type'      => $messageData['type'],
                            'local_url' => $localMediaUrl,
                        ]);
                    }
                }
            } catch (\Exception $e) {
                Log::warning('[ProcessIncomingMessageJob] Failed to download media, continuing without it', [
                    'media_id' => $messageData['media_id'],
                    'error'    => $e->getMessage(),
                ]);
                // No lanzar: guardar el mensaje igual, sin media local
            }
        }

        // Guardar mensaje en DB
        $savedMessage = Message::create([
            'contact_id'          => $contact->id,
            'phone_number_id'     => $phoneNumberId,
            'phone_number'        => $phoneNumber,
            'campaign_id'         => null,
            'message'             => $messageData['content'],
            'status'              => 'delivered',
            'direction'           => 'inbound',
            'whatsapp_message_id' => $messageId,
            'message_timestamp'   => $timestamp ? date('Y-m-d H:i:s', $timestamp) : now(),
            'message_content'     => $messageData['content'],
            'message_type'        => $messageData['type'],
            'media_url'           => $localMediaUrl ?? $mediaUrl,
            'media_id'            => $messageData['media_id'] ?? null,
            'metadata'            => $messageData['metadata'] ?? null,
            'delivered_at'        => now(),
        ]);

        Log::info('[ProcessIncomingMessageJob] Message saved, passing to BotService', [
            'contact_id'     => $contact->id,
            'message_id'     => $savedMessage->id,
            'message_type'   => $savedMessage->message_type,
        ]);

        // Invocar bot
        $botService->handleIncomingMessage($contact, $savedMessage);
    }

    /**
     * Actualizar las reacciones del mensaje objetivo.
     */
    private function handleReaction(array $reactionData, Contact $contact): void
    {
        $emoji           = $reactionData['reaction']['emoji'] ?? null;
        $targetMessageId = $reactionData['reaction']['message_id'] ?? null;

        Log::info('[ProcessIncomingMessageJob] Processing reaction', [
            'emoji'             => $emoji,
            'target_message_id' => $targetMessageId,
            'contact_id'        => $contact->id,
        ]);

        if (!$targetMessageId || !$emoji) {
            return;
        }

        $targetMessage = Message::where('whatsapp_message_id', $targetMessageId)->first();

        if (!$targetMessage) {
            return;
        }

        $reactions = $targetMessage->reactions ?? [];
        $reactions[] = [
            'emoji'      => $emoji,
            'contact_id' => $contact->id,
            'at'         => now()->toIso8601String(),
        ];

        $targetMessage->reactions = $reactions;
        $targetMessage->save();
    }

    /**
     * Extraer contenido y metadata del mensaje según su tipo.
     * (Misma lógica que WebhookController::extractMessageData para mantener consistencia)
     */
    private function extractMessageData(array $message): array
    {
        $type     = $message['type'] ?? 'text';
        $metadata = [];
        $content  = '';
        $mediaId  = null;

        switch ($type) {
            case 'text':
                $content = $message['text']['body'] ?? '';
                break;

            case 'image':
                $caption = $message['image']['caption'] ?? '';
                $content = $caption ?: '📷 Imagen';
                $mediaId = $message['image']['id'] ?? null;
                $metadata = ['caption' => $caption, 'mime_type' => $message['image']['mime_type'] ?? null];
                break;

            case 'video':
                $caption = $message['video']['caption'] ?? '';
                $content = $caption ?: '🎥 Video';
                $mediaId = $message['video']['id'] ?? null;
                $metadata = ['caption' => $caption];
                break;

            case 'audio':
                $content = '🎵 Audio';
                $mediaId = $message['audio']['id'] ?? null;
                break;

            case 'document':
                $filename = $message['document']['filename'] ?? 'archivo';
                $content  = '📄 ' . $filename;
                $mediaId  = $message['document']['id'] ?? null;
                $metadata = ['filename' => $filename];
                break;

            case 'location':
                $content  = '📍 Ubicación';
                $metadata = [
                    'latitude'  => $message['location']['latitude'] ?? null,
                    'longitude' => $message['location']['longitude'] ?? null,
                ];
                break;

            case 'sticker':
                $content = '🎨 Sticker';
                $mediaId = $message['sticker']['id'] ?? null;
                break;

            case 'interactive':
                $interactiveType = $message['interactive']['type'] ?? null;

                if ($interactiveType === 'button_reply') {
                    $buttonId    = $message['interactive']['button_reply']['id'] ?? '';
                    $buttonTitle = $message['interactive']['button_reply']['title'] ?? '';
                    $content     = $buttonId;
                    $metadata    = [
                        'interactive_type' => 'button_reply',
                        'button_id'        => $buttonId,
                        'button_title'     => $buttonTitle,
                    ];
                } elseif ($interactiveType === 'list_reply') {
                    $listId    = $message['interactive']['list_reply']['id'] ?? '';
                    $listTitle = $message['interactive']['list_reply']['title'] ?? '';
                    $content   = $listId;
                    $metadata  = [
                        'interactive_type' => 'list_reply',
                        'list_id'          => $listId,
                        'list_title'       => $listTitle,
                    ];
                }
                break;

            case 'button':
                $buttonText    = $message['button']['text'] ?? '';
                $buttonPayload = $message['button']['payload'] ?? $buttonText;
                $content       = $buttonText;
                $metadata      = [
                    'interactive_type' => 'template_button_reply',
                    'button_title'     => $buttonText,
                    'button_payload'   => $buttonPayload,
                ];
                break;

            default:
                $content = '[Mensaje de tipo: ' . $type . ']';
        }

        return [
            'type'     => $type,
            'content'  => $content,
            'media_url' => null,
            'media_id' => $mediaId,
            'metadata' => !empty($metadata) ? $metadata : null,
        ];
    }

    /**
     * Manejar fallo definitivo del job.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('[ProcessIncomingMessageJob] Failed after all retries', [
            'phone'      => $this->messageData['from'] ?? 'unknown',
            'message_id' => $this->messageData['id'] ?? 'unknown',
            'error'      => $exception->getMessage(),
        ]);
    }
}
