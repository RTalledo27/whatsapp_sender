<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConversationController extends Controller
{
    /**
     * Listar todas las conversaciones (contactos con mensajes)
     */
    public function index(Request $request)
    {
        $search = $request->query('search', '');
        $perPage = $request->query('per_page', 20);
        
        $conversations = Contact::select('contacts.*')
            ->join('messages', 'contacts.id', '=', 'messages.contact_id')
            ->selectRaw('COUNT(messages.id) as total_messages')
            ->selectRaw('COALESCE(MAX(messages.message_timestamp), MAX(messages.created_at)) as last_message_at')
            ->selectRaw('SUM(CASE WHEN messages.direction = "inbound" AND messages.read_at IS NULL THEN 1 ELSE 0 END) as unread_count')
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
        
        $messages = Message::where('contact_id', $contactId)
            ->orderByRaw('COALESCE(message_timestamp, created_at) DESC')
            ->paginate($perPage);
        
        // Marcar mensajes como leídos automáticamente
        Message::where('contact_id', $contactId)
            ->where('direction', 'inbound')
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
        
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
        $contact = Contact::findOrFail($contactId);
        
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
    public function stats()
    {
        $stats = [
            'total_conversations' => Contact::has('messages')->count(),
            'unread_messages' => Message::where('direction', 'inbound')
                ->whereNull('read_at')
                ->count(),
            'messages_today' => Message::whereDate('message_timestamp', today())->count(),
            'incoming_today' => Message::where('direction', 'inbound')
                ->whereDate('message_timestamp', today())
                ->count(),
            'outgoing_today' => Message::where('direction', 'outbound')
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
}
