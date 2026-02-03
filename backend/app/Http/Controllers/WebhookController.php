<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Message;
use App\Services\WhatsAppService;
use App\Services\BotService;
use App\Helpers\PhoneHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __construct(
        private WhatsAppService $whatsappService,
        private BotService $botService
    ) {}
    
    /**
     * Verificación del webhook (requerido por Meta)
     */
    public function verify(Request $request)
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');
        
        $verifyToken = env('WHATSAPP_WEBHOOK_VERIFY_TOKEN', 'whatsapp_webhook_verify_token');
        
        if ($mode === 'subscribe' && $token === $verifyToken) {
            Log::info('Webhook verified successfully');
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }
        
        Log::warning('Webhook verification failed');
        return response('Forbidden', 403);
    }
    
    /**
     * Recibir mensajes entrantes de WhatsApp
     */
    public function receive(Request $request)
    {
        try {
            $data = $request->all();
            Log::info('Webhook received', ['data' => $data]);
            
            // Meta envía la estructura: entry -> changes -> value -> messages
            if (!isset($data['entry'])) {
                return response()->json(['status' => 'ok']);
            }
            
            foreach ($data['entry'] as $entry) {
                if (!isset($entry['changes'])) {
                    continue;
                }
                
                foreach ($entry['changes'] as $change) {
                    if (!isset($change['value'])) {
                        continue;
                    }
                    
                    $value = $change['value'];
                    
                    // Procesar mensajes entrantes
                    if (isset($value['messages'])) {
                        foreach ($value['messages'] as $message) {
                            $this->processIncomingMessage($message, $value);
                        }
                    }
                    
                    // Procesar estados de mensajes (enviado, entregado, leído)
                    if (isset($value['statuses'])) {
                        foreach ($value['statuses'] as $status) {
                            $this->processMessageStatus($status);
                        }
                    }
                }
            }
            
            return response()->json(['status' => 'ok']);
            
        } catch (\Exception $e) {
            Log::error('Error processing webhook', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json(['status' => 'error'], 500);
        }
    }
    
    /**
     * Procesar mensaje entrante
     */
    private function processIncomingMessage(array $message, array $value)
    {
        $phoneNumber = $message['from'] ?? null;
        $messageId = $message['id'] ?? null;
        $timestamp = $message['timestamp'] ?? null;
        $messageType = $message['type'] ?? 'text';
        
        // Capturar phone_number_id del metadata
        $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;
        
        if (!$phoneNumber || !$messageId) {
            Log::warning('Missing required fields in incoming message');
            return;
        }
        
        // Normalizar número de teléfono (agregar + si no lo tiene)
        $phoneNumber = PhoneHelper::normalize($phoneNumber);
        
        Log::info('Processing incoming message', [
            'original_phone' => $message['from'],
            'normalized_phone' => $phoneNumber
        ]);
        
        // Buscar o crear contacto
        $contact = Contact::firstOrCreate(
            ['phone_number' => $phoneNumber],
            ['name' => $phoneNumber] // Nombre por defecto, puede actualizarse después
        );
        
        // Manejar reacciones de forma especial
        if ($messageType === 'reaction') {
            $this->handleReaction($message, $contact);
            return;
        }
        
        // Extraer contenido y metadata del mensaje
        $messageData = $this->extractMessageData($message);
        
        // Si hay media_id, obtener y descargar el archivo multimedia
        $mediaUrl = null;
        $localMediaUrl = null;
        if (!empty($messageData['media_id'])) {
            $mediaUrl = $this->whatsappService->getMediaUrl($messageData['media_id']);
            if ($mediaUrl) {
                Log::info('Media URL obtained', [
                    'media_id' => $messageData['media_id'],
                    'type' => $messageData['type'],
                    'url' => $mediaUrl
                ]);
                
                // Determinar extensión según el tipo
                $extension = match($messageData['type']) {
                    'image' => 'jpg',
                    'video' => 'mp4',
                    'audio' => 'ogg',
                    'sticker' => 'webp',
                    'document' => 'pdf',
                    default => 'file'
                };
                
                $filename = $messageData['media_id'] . '.' . $extension;
                $localMediaUrl = $this->whatsappService->downloadMedia($mediaUrl, $filename);
                
                if ($localMediaUrl) {
                    Log::info('Media downloaded successfully', [
                        'type' => $messageData['type'],
                        'local_url' => $localMediaUrl
                    ]);
                }
            }
        }
        
        // Guardar mensaje con URL local
        $savedMessage = Message::create([
            'contact_id' => $contact->id,
            'phone_number_id' => $phoneNumberId,
            'campaign_id' => null,
            'message' => null,
            'status' => null,
            'direction' => 'inbound',
            'whatsapp_message_id' => $messageId,
            'message_timestamp' => $timestamp ? date('Y-m-d H:i:s', $timestamp) : now(),
            'message_content' => $messageData['content'],
            'message_type' => $messageData['type'],
            'media_url' => $localMediaUrl ?? $mediaUrl,
            'media_id' => $messageData['media_id'] ?? null,
            'metadata' => $messageData['metadata'] ?? null,
            'delivered_at' => now(),
        ]);
        
        Log::info('Incoming message saved', [
            'contact_id' => $contact->id,
            'phone' => $phoneNumber,
            'message_id' => $messageId
        ]);

        // Invocar al bot si corresponde
        $this->botService->handleIncomingMessage($contact, $savedMessage);
    }
    
    /**
     * Extraer contenido del mensaje según su tipo
     */
    private function extractMessageContent(array $message): string
    {
        $type = $message['type'] ?? 'text';
        
        switch ($type) {
            case 'text':
                return $message['text']['body'] ?? '';
                
            case 'image':
                return '[Imagen] ' . ($message['image']['caption'] ?? 'Sin descripción');
                
            case 'video':
                return '[Video] ' . ($message['video']['caption'] ?? 'Sin descripción');
                
            case 'audio':
                return '[Audio]';
                
            case 'document':
                return '[Documento] ' . ($message['document']['filename'] ?? 'Sin nombre');
                
            case 'location':
                return '[Ubicación]';
                
            case 'contacts':
                return '[Contacto compartido]';
                
            default:
                return '[Mensaje de tipo: ' . $type . ']';
        }
    }
    
    /**
     * Extraer datos completos del mensaje (contenido, tipo, media)
     */
    private function extractMessageData(array $message): array
    {
        $type = $message['type'] ?? 'text';
        $metadata = [];
        $content = '';
        $mediaUrl = null;
        $mediaId = null;
        
        switch ($type) {
            case 'text':
                $content = $message['text']['body'] ?? '';
                break;
                
            case 'reaction':
                $emoji = $message['reaction']['emoji'] ?? '👍';
                $content = $emoji;
                $metadata = [
                    'emoji' => $emoji,
                    'message_id' => $message['reaction']['message_id'] ?? null,
                ];
                break;
                
            case 'image':
                $caption = $message['image']['caption'] ?? '';
                $content = $caption ?: '📷 Imagen';
                $mediaId = $message['image']['id'] ?? null;
                $metadata = [
                    'caption' => $caption,
                    'mime_type' => $message['image']['mime_type'] ?? null,
                ];
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
                $content = '📄 ' . $filename;
                $mediaId = $message['document']['id'] ?? null;
                $metadata = ['filename' => $filename];
                break;
                
            case 'location':
                $content = '📍 Ubicación';
                $metadata = [
                    'latitude' => $message['location']['latitude'] ?? null,
                    'longitude' => $message['location']['longitude'] ?? null,
                ];
                break;
                
            case 'sticker':
                $content = '🎨 Sticker';
                $mediaId = $message['sticker']['id'] ?? null;
                break;
                
            default:
                $content = '[Mensaje de tipo: ' . $type . ']';
        }
        
        return [
            'type' => $type,
            'content' => $content,
            'media_url' => $mediaUrl,
            'media_id' => $mediaId,
            'metadata' => !empty($metadata) ? $metadata : null,
        ];
    }
    
    /**
     * Procesar estado de mensaje (enviado, entregado, leído)
     */
    private function processMessageStatus(array $status)
    {
        $messageId = $status['id'] ?? null;
        $statusType = $status['status'] ?? null;
        $timestamp = $status['timestamp'] ?? null;
        
        if (!$messageId || !$statusType) {
            return;
        }
        
        $message = Message::where('whatsapp_message_id', $messageId)->first();
        
        if (!$message) {
            Log::warning('Message not found for status update', ['message_id' => $messageId]);
            return;
        }
        
        // Actualizar estado según el tipo
        switch ($statusType) {
            case 'sent':
                $message->status = 'sent';
                break;
                
            case 'delivered':
                $message->status = 'delivered';
                $message->delivered_at = $timestamp ? date('Y-m-d H:i:s', $timestamp) : now();
                break;
                
            case 'read':
                $message->status = 'read';
                $message->read_at = $timestamp ? date('Y-m-d H:i:s', $timestamp) : now();
                break;
                
            case 'failed':
                $message->status = 'failed';
                if (isset($status['errors'])) {
                    $message->error = json_encode($status['errors']);
                    Log::error('WhatsApp message failed', [
                        'message_id' => $messageId,
                        'errors' => $status['errors']
                    ]);
                }
                break;
        }
        
        $message->save();
        
        Log::info('Message status updated', [
            'message_id' => $messageId,
            'status' => $statusType
        ]);
    }
    
    /**
     * Manejar reacción a un mensaje
     */
    private function handleReaction(array $reactionData, Contact $contact)
    {
        $emoji = $reactionData['reaction']['emoji'] ?? null;
        $targetMessageId = $reactionData['reaction']['message_id'] ?? null;
        
        Log::info('Processing reaction', [
            'emoji' => $emoji,
            'target_message_id' => $targetMessageId,
            'contact_id' => $contact->id,
            'full_reaction_data' => $reactionData['reaction']
        ]);
        
        if (!$targetMessageId) {
            Log::warning('Reaction without target message_id');
            return;
        }
        
        // Extraer la parte final del message_id (después de AgAS)
        // WhatsApp cambia el prefijo según el remitente pero mantiene la parte final
        preg_match('/AgAS(.+)$/', $targetMessageId, $matches);
        $messageIdSuffix = $matches[1] ?? null;
        
        // Buscar el mensaje original por la parte final del whatsapp_message_id
        $message = null;
        if ($messageIdSuffix) {
            $message = Message::where('whatsapp_message_id', 'LIKE', "%AgAS{$messageIdSuffix}")
                ->where('contact_id', $contact->id)
                ->orderBy('created_at', 'desc')
                ->first();
        }
        
        // Si no se encuentra con el sufijo, intentar búsqueda exacta
        if (!$message) {
            $message = Message::where('whatsapp_message_id', $targetMessageId)
                ->where('contact_id', $contact->id)
                ->first();
        }
        
        if (!$message) {
            Log::warning('Target message not found for reaction', [
                'target_message_id' => $targetMessageId,
                'message_id_suffix' => $messageIdSuffix,
                'searched_column' => 'whatsapp_message_id'
            ]);
            return;
        }
        
        // Obtener reacciones actuales
        $reactions = $message->reactions ?? [];
        
        // Si el emoji está vacío, significa que se quitó la reacción
        if (empty($emoji)) {
            // Eliminar reacción del contacto
            $reactions = array_filter($reactions, function($reaction) use ($contact) {
                return $reaction['contact_id'] !== $contact->id;
            });
            $reactions = array_values($reactions); // Reindexar array
        } else {
            // Agregar o actualizar reacción
            $found = false;
            foreach ($reactions as &$reaction) {
                if ($reaction['contact_id'] === $contact->id) {
                    $reaction['emoji'] = $emoji;
                    $found = true;
                    break;
                }
            }
            
            if (!$found) {
                $reactions[] = [
                    'contact_id' => $contact->id,
                    'emoji' => $emoji,
                    'created_at' => now()->toDateTimeString()
                ];
            }
        }
        
        $message->reactions = $reactions;
        $message->save();
        
        Log::info('Reaction handled', [
            'message_id' => $message->id,
            'contact_id' => $contact->id,
            'emoji' => $emoji,
            'removed' => empty($emoji)
        ]);
    }
}
