<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
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
        
        if (!$phoneNumber || !$messageId) {
            Log::warning('Missing required fields in incoming message');
            return;
        }
        
        // Buscar o crear contacto
        $contact = Contact::firstOrCreate(
            ['phone_number' => $phoneNumber],
            ['name' => $phoneNumber] // Nombre por defecto, puede actualizarse después
        );
        
        // Extraer contenido del mensaje
        $messageContent = $this->extractMessageContent($message);
        
        // Guardar mensaje
        Message::create([
            'contact_id' => $contact->id,
            'campaign_id' => null, // Mensajes entrantes no tienen campaña
            'phone' => $phoneNumber,
            'message' => null,
            'status' => 'received',
            'direction' => 'inbound',
            'whatsapp_message_id' => $messageId,
            'message_timestamp' => $timestamp ? date('Y-m-d H:i:s', $timestamp) : now(),
            'message_content' => $messageContent,
            'delivered_at' => now(),
        ]);
        
        Log::info('Incoming message saved', [
            'contact_id' => $contact->id,
            'phone' => $phoneNumber,
            'message_id' => $messageId
        ]);
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
                }
                break;
        }
        
        $message->save();
        
        Log::info('Message status updated', [
            'message_id' => $messageId,
            'status' => $statusType
        ]);
    }
}
