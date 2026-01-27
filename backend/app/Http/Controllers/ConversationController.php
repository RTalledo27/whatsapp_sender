<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Message;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class ConversationController extends Controller
{
    protected $whatsappService;

    public function __construct(WhatsAppService $whatsappService)
    {
        $this->whatsappService = $whatsappService;
    }
    /**
     * Listar todas las conversaciones (contactos con mensajes)
     */
    public function index(Request $request)
    {
        $search = $request->query('search', '');
        $perPage = $request->query('per_page', 20);
        $phoneNumberId = $request->query('phone_number_id');
        
        $conversations = Contact::select('contacts.*')
            ->join('messages', 'contacts.id', '=', 'messages.contact_id')
            ->when($phoneNumberId, function($query, $phoneNumberId) {
                return $query->where('messages.phone_number_id', $phoneNumberId);
            })
            ->selectRaw('COUNT(messages.id) as total_messages')
            ->selectRaw('COALESCE(MAX(messages.message_timestamp), MAX(messages.created_at)) as last_message_at')
            ->selectRaw('SUM(CASE WHEN messages.direction = "inbound" AND messages.read_at IS NULL THEN 1 ELSE 0 END) as unread_count')
            ->selectRaw('(
                SELECT COALESCE(message_content, message) 
                FROM messages m2 
                WHERE m2.contact_id = contacts.id' . ($phoneNumberId ? ' AND m2.phone_number_id = "' . $phoneNumberId . '"' : '') . ' 
                ORDER BY COALESCE(m2.message_timestamp, m2.created_at) DESC 
                LIMIT 1
            ) as last_message')
            ->selectRaw('(
                SELECT direction 
                FROM messages m3 
                WHERE m3.contact_id = contacts.id' . ($phoneNumberId ? ' AND m3.phone_number_id = "' . $phoneNumberId . '"' : '') . ' 
                ORDER BY COALESCE(m3.message_timestamp, m3.created_at) DESC 
                LIMIT 1
            ) as last_message_direction')
            ->when($search, function ($query, $search) {
                return $query->where(function ($q) use ($search) {
                    $q->where('contacts.name', 'like', "%{$search}%")
                      ->orWhere('contacts.phone_number', 'like', "%{$search}%");
                });
            })
            ->groupBy('contacts.id', 'contacts.name', 'contacts.phone_number', 'contacts.email', 
                     'contacts.metadata', 'contacts.created_at', 'contacts.updated_at')
            ->orderByRaw('COALESCE(MAX(messages.message_timestamp), MAX(messages.created_at)) DESC')
            ->paginate($perPage);
        
        return response()->json($conversations);
    }
    
    /**
     * Obtener mensajes de una conversación específica
     */
    public function show(Request $request, $contactId)
    {
        $contact = Contact::findOrFail($contactId);
        $perPage = $request->query('per_page', 50);
        $phoneNumberId = $request->query('phone_number_id');
        
        $messagesQuery = Message::where('contact_id', $contactId);
        
        if ($phoneNumberId) {
            $messagesQuery->where('phone_number_id', $phoneNumberId);
        }
        
        $messages = $messagesQuery
            ->orderByRaw('COALESCE(message_timestamp, created_at) DESC')
            ->paginate($perPage);
        
        // Marcar mensajes como leídos automáticamente
        $updateQuery = Message::where('contact_id', $contactId)
            ->where('direction', 'inbound')
            ->whereNull('read_at');
        
        if ($phoneNumberId) {
            $updateQuery->where('phone_number_id', $phoneNumberId);
        }
        
        $updateQuery->update(['read_at' => now()]);
        
        return response()->json([
            'contact' => $contact,
            'messages' => $messages
        ]);
    }
    
    /**
     * Marcar mensajes como leídos
     */
    public function markAsRead($contactId)
    {
        Contact::findOrFail($contactId);
        
        $updated = Message::where('contact_id', $contactId)
            ->where('direction', 'inbound')
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
        
        return response()->json([
            'success' => true,
            'messages_updated' => $updated
        ]);
    }
    
    /**
     * Obtener estadísticas de conversaciones
     */
    public function stats(Request $request)
    {
        $phoneNumberId = $request->query('phone_number_id');

        $messageQuery = Message::query();
        $contactQuery = Contact::query();

        if ($phoneNumberId) {
            $messageQuery->where('phone_number_id', $phoneNumberId);
            $contactQuery->whereHas('messages', function ($query) use ($phoneNumberId) {
                $query->where('phone_number_id', $phoneNumberId);
            });
        }

        $stats = [
            'total_conversations' => $contactQuery->has('messages')->count(),
            'unread_messages' => (clone $messageQuery)->where('direction', 'inbound')
                ->whereNull('read_at')
                ->count(),
            'messages_today' => (clone $messageQuery)->whereDate('message_timestamp', today())->count(),
            'incoming_today' => (clone $messageQuery)->where('direction', 'inbound')
                ->whereDate('message_timestamp', today())
                ->count(),
            'outgoing_today' => (clone $messageQuery)->where('direction', 'outbound')
                ->whereDate('message_timestamp', today())
                ->count(),
        ];
        
        return response()->json($stats);
    }
    
    /**
     * Buscar en mensajes
     */
    public function search(Request $request)
    {
        $query = $request->query('q', '');
        $perPage = $request->query('per_page', 20);
        
        if (empty($query)) {
            return response()->json([
                'data' => [],
                'meta' => []
            ]);
        }
        
        $messages = Message::with('contact')
            ->where(function ($q) use ($query) {
                $q->where('message', 'like', "%{$query}%")
                  ->orWhere('message_content', 'like', "%{$query}%")
                  ->orWhereHas('contact', function ($contactQuery) use ($query) {
                      $contactQuery->where('name', 'like', "%{$query}%")
                                  ->orWhere('phone', 'like', "%{$query}%");
                  });
            })
            ->orderBy('message_timestamp', 'desc')
            ->paginate($perPage);
        
        return response()->json($messages);
    }

    /**
     * Enviar mensaje a un contacto
     */
    public function sendMessage(Request $request, $contactId)
    {
        $request->validate([
            'message' => 'nullable|string|max:4096',
            'file' => 'nullable|file|max:102400|mimes:jpg,jpeg,png,gif,webp,mp4,mp3,ogg,opus,webm,m4a,aac,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv',
            'phone_number_id' => 'nullable|string'
        ]);

        $contact = Contact::findOrFail($contactId);
        $messageText = trim((string) $request->input('message', ''));
        $hasFile = $request->hasFile('file');
        
        // Obtener phone_number_id del request o usar el por defecto
        $phoneNumberId = $request->input('phone_number_id', config('services.whatsapp.phone_number_id'));
        
        // Crear instancia de WhatsAppService con el número seleccionado
        $whatsappService = new WhatsAppService($phoneNumberId);

        if ($messageText === '' && !$hasFile) {
            return response()->json([
                'success' => false,
                'message' => 'Debes escribir un mensaje o adjuntar un archivo.'
            ], 422);
        }
        
        // Verificar ventana de 24 horas
        $lastInboundMessage = Message::where('contact_id', $contact->id)
            ->where('direction', 'inbound')
            ->orderBy('message_timestamp', 'desc')
            ->first();
        
        $canSendFreeform = false;
        if ($lastInboundMessage) {
            $hoursSinceLastMessage = now()->diffInHours($lastInboundMessage->message_timestamp);
            $canSendFreeform = $hoursSinceLastMessage < 24;
        }
        
        // Advertencia si está fuera de ventana
        if (!$canSendFreeform) {
            Log::warning('Attempting to send outside 24h window', [
                'contact_id' => $contact->id,
                'last_inbound_at' => $lastInboundMessage?->message_timestamp
            ]);
        }
        
        $file = $hasFile ? $request->file('file') : null;
        $temporaryConvertedPath = null;
        $messageType = $file ? $this->inferMessageTypeFromFile($file->getMimeType(), $file->getClientOriginalExtension()) : 'text';

        if ($file && $messageType === 'audio') {
            $conversion = $this->maybeConvertVoiceNote($file);
            if (!$conversion['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $conversion['message'] ?? 'No se pudo procesar la nota de voz.',
                ], 422);
            }

            if (!empty($conversion['file']) && $conversion['file'] instanceof UploadedFile) {
                $file = $conversion['file'];
            }
            $temporaryConvertedPath = $conversion['temporary_path'] ?? null;
        }

        $metadata = [];
        $mediaUrl = null;
        $storedPath = null;
        if ($file) {
            $storedPath = $file->store('outgoing-media', 'public');
            $mediaUrl = asset('storage/' . $storedPath);

            $metadata = [
                'filename' => $file->getClientOriginalName(),
                'filesize' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
            ];

            if ($messageText !== '' && in_array($messageType, ['image', 'video', 'document'], true)) {
                $metadata['caption'] = $messageText;
            }
        }

        $message = Message::create([
            'contact_id' => $contact->id,
            'campaign_id' => null,
            'phone_number' => $contact->phone_number,
            'phone_number_id' => $phoneNumberId,
            'message' => $messageText !== '' ? $messageText : ($metadata['filename'] ?? ''),
            'message_content' => $messageText !== '' ? $messageText : null,
            'status' => 'pending',
            'direction' => 'outbound',
            'message_timestamp' => now(),
            'message_type' => $messageType,
            'media_url' => $mediaUrl,
            'metadata' => !empty($metadata) ? $metadata : null,
        ]);

        try {
            if ($file) {
                $upload = $whatsappService->uploadMedia($file);
                if (!$upload['success'] || empty($upload['media_id'])) {
                    $message->update([
                        'status' => 'failed',
                        'error_message' => $upload['error'] ?? 'Error al subir el archivo a WhatsApp'
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Error al subir el archivo: ' . ($upload['error'] ?? 'Error desconocido')
                    ], 500);
                }

                $result = $whatsappService->sendMediaMessage(
                    $contact->phone_number,
                    $messageType,
                    $upload['media_id'],
                    $messageText !== '' ? $messageText : null,
                    $file->getClientOriginalName()
                );

                if (!empty($upload['media_id'])) {
                    $message->update([
                        'media_id' => $upload['media_id'],
                    ]);
                }
            } else {
                $result = $whatsappService->sendMessage(
                    $contact->phone_number,
                    $messageText
                );
            }

            if ($result['success']) {
                // Actualizar mensaje con ID de WhatsApp y estado
                $message->update([
                    'status' => 'sent',
                    'sent_at' => now(),
                    'whatsapp_message_id' => $result['message_id'] ?? null
                ]);

                Log::info('Message sent successfully', [
                    'contact_id' => $contact->id,
                    'message_id' => $message->id,
                    'whatsapp_message_id' => $result['message_id']
                ]);
            } else {
                // Error al enviar
                $message->update([
                    'status' => 'failed',
                    'error_message' => $result['message'] ?? 'Error al enviar mensaje'
                ]);

                Log::error('Failed to send WhatsApp message', [
                    'contact_id' => $contact->id,
                    'error' => $result['message']
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Error al enviar mensaje: ' . ($result['message'] ?? 'Error desconocido')
                ], 500);
            }
        } catch (\Exception $e) {
            // Error en el envío
            $message->update([
                'status' => 'failed',
                'error_message' => $e->getMessage()
            ]);

            Log::error('Exception sending WhatsApp message', [
                'contact_id' => $contact->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al enviar mensaje: ' . $e->getMessage()
            ], 500);
        } finally {
            if (!empty($temporaryConvertedPath) && is_string($temporaryConvertedPath) && file_exists($temporaryConvertedPath)) {
                @unlink($temporaryConvertedPath);
            }
        }

        return response()->json([
            'success' => true,
            'message' => $message->fresh()
        ]);
    }

    private function inferMessageTypeFromFile(?string $mimeType, ?string $extension): string
    {
        $mimeType = strtolower((string) $mimeType);
        $extension = strtolower((string) $extension);

        if ($extension === 'webm' || $mimeType === 'video/webm' || str_starts_with($mimeType, 'audio/webm')) return 'audio';
        if (str_starts_with($mimeType, 'image/')) return 'image';
        if (str_starts_with($mimeType, 'video/')) return 'video';
        if (str_starts_with($mimeType, 'audio/')) return 'audio';

        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) return 'image';
        if (in_array($extension, ['mp4'], true)) return 'video';
        if (in_array($extension, ['mp3', 'ogg', 'opus', 'webm', 'm4a', 'aac'], true)) return 'audio';

        return 'document';
    }

    private function maybeConvertVoiceNote(UploadedFile $file): array
    {
        $mimeType = strtolower((string) $file->getMimeType());
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if (!($extension === 'webm' || str_starts_with($mimeType, 'audio/webm') || $mimeType === 'video/webm')) {
            return ['success' => true, 'file' => $file];
        }

        $tmpDir = storage_path('app/tmp');
        if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
            return ['success' => false, 'message' => 'No se pudo preparar directorio temporal para convertir audio.'];
        }

        $outputPath = $tmpDir . DIRECTORY_SEPARATOR . Str::uuid()->toString() . '.ogg';

        $process = new Process([
            'ffmpeg',
            '-y',
            '-i',
            $file->getRealPath(),
            '-vn',
            '-c:a',
            'libopus',
            '-application',
            'voip',
            $outputPath,
        ]);

        $process->setTimeout(60);

        try {
            $process->run();
        } catch (\Throwable) {
            return ['success' => false, 'message' => 'No se pudo convertir la nota de voz (ffmpeg no disponible).'];
        }

        if (!$process->isSuccessful() || !file_exists($outputPath)) {
            return ['success' => false, 'message' => 'No se pudo convertir la nota de voz. Verifica ffmpeg/libopus en el servidor.'];
        }

        $base = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'nota-de-voz';
        $converted = new UploadedFile($outputPath, $base . '.ogg', 'audio/ogg', null, true);

        return [
            'success' => true,
            'file' => $converted,
            'temporary_path' => $outputPath,
        ];
    }
}
