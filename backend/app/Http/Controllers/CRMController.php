<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\BotConversation;
use App\Services\LogicWareService;
use App\Jobs\SendLeadToCRMJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Controlador para gestionar la integración con LogicWare CRM
 */
class CRMController extends Controller
{
    protected LogicWareService $logicwareService;

    public function __construct(LogicWareService $logicwareService)
    {
        $this->logicwareService = $logicwareService;
    }

    /**
     * Obtener estadísticas de envíos al CRM
     */
    public function stats(Request $request)
    {
        $botPhoneNumberId = config('services.whatsapp.leads_bot_id');

        // Total de leads calificados (independiente de si se enviaron)
        $qualifiedLeads = Contact::whereHas('botConversations', function($q) use ($botPhoneNumberId) {
            $q->where('phone_number_id', $botPhoneNumberId)
              ->where('state', 'finished')
              ->whereJsonContains('context->qualified', true);
        })->count();

        // Total de leads NO calificados
        $nonQualifiedLeads = Contact::whereHas('botConversations', function($q) use ($botPhoneNumberId) {
            $q->where('phone_number_id', $botPhoneNumberId)
              ->where('state', 'finished')
              ->where(function($query) {
                  $query->whereJsonContains('context->qualified', false)
                        ->orWhereNull('context->qualified');
              });
        })->count();

        // Contactos enviados al CRM
        $sentToCRM = Contact::whereNotNull('metadata->crm_sent')
            ->where('metadata->crm_sent', true)
            ->count();

        // Contactos calificados pero no enviados aún (pendientes)
        $pendingToSend = Contact::whereHas('botConversations', function($q) use ($botPhoneNumberId) {
            $q->where('phone_number_id', $botPhoneNumberId)
              ->where('state', 'finished')
              ->whereJsonContains('context->qualified', true);
        })
        ->where(function($q) {
            $q->whereNull('metadata->crm_sent')
              ->orWhere('metadata->crm_sent', false);
        })
        ->whereDoesntHave('botConversations', function($q) {
            $q->whereJsonContains('context->crm_send_failed', true);
        })
        ->count();

        // Contactos con errores al enviar
        $failedToSend = Contact::whereHas('botConversations', function($q) use ($botPhoneNumberId) {
            $q->where('phone_number_id', $botPhoneNumberId)
              ->whereJsonContains('context->crm_send_failed', true);
        })
        ->count();

        // Chats abandonados (iniciaron pero no terminaron)
        $abandonedChats = Contact::whereHas('botConversations', function($q) use ($botPhoneNumberId) {
            $q->where('phone_number_id', $botPhoneNumberId)
              ->whereNotIn('state', ['initial', 'finished']);
        })->count();

        // Últimos leads enviados con metadata completo
        $recentlySent = Contact::whereNotNull('metadata->crm_sent_at')
            ->orderByDesc('metadata->crm_sent_at')
            ->limit(20)
            ->get(['id', 'name', 'phone_number', 'email', 'metadata', 'created_at']);

        // Tasa de éxito
        $totalAttempts = $sentToCRM + $failedToSend;
        $successRate = $totalAttempts > 0 
            ? round(($sentToCRM / $totalAttempts) * 100, 2) 
            : 0;

        return response()->json([
            'success' => true,
            'qualified_leads' => $qualifiedLeads,
            'non_qualified_leads' => $nonQualifiedLeads,
            'sent_to_crm' => $sentToCRM,
            'pending_to_send' => $pendingToSend,
            'failed_to_send' => $failedToSend,
            'abandoned_chats' => $abandonedChats,
            'success_rate' => $successRate,
            'recently_sent' => $recentlySent
        ]);
    }

    /**
     * Reenviar un contacto calificado al CRM manualmente
     */
    public function resend(Request $request, $contactId)
    {
        try {
            $contact = Contact::findOrFail($contactId);

            // Buscar conversación calificada
            $conversation = $contact->botConversations()
                ->where('state', 'finished')
                ->whereJsonContains('context->qualified', true)
                ->first();

            if (!$conversation) {
                return response()->json([
                    'success' => false,
                    'message' => 'El contacto no tiene una conversación calificada'
                ], 400);
            }

            // Limpiar flag de ya enviado para forzar reenvío
            $metadata = $contact->metadata ?? [];
            unset($metadata['crm_sent']);
            $contact->metadata = $metadata;
            $contact->save();

            // Limpiar contexto de fallo
            $context = $conversation->context ?? [];
            unset($context['crm_send_failed']);
            unset($context['crm_send_error']);
            $conversation->context = $context;
            $conversation->save();

            Log::info('CRM: Manual resend requested', [
                'contact_id' => $contact->id,
                'requested_by' => $request->user()->id ?? 'system'
            ]);

            // Opción 1: Enviar directamente (bloqueante)
            // $result = $this->logicwareService->createQualifiedLead($contact, $conversation);

            // Opción 2: Enviar a través de la cola (recomendado)
            dispatch(new SendLeadToCRMJob($contact, $conversation));

            return response()->json([
                'success' => true,
                'message' => 'Lead encolado para reenvío al CRM',
                'contact' => [
                    'id' => $contact->id,
                    'name' => $contact->name,
                    'phone' => $contact->phone_number
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('CRM: Error in manual resend', [
                'contact_id' => $contactId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al reenviar lead: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Limpiar cache del token de LogicWare (para testing)
     */
    public function clearTokenCache()
    {
        $this->logicwareService->clearTokenCache();

        return response()->json([
            'success' => true,
            'message' => 'Token cache cleared successfully'
        ]);
    }

    /**
     * Listar contactos con errores de envío al CRM
     */
    public function failedLeads(Request $request)
    {
        $botPhoneNumberId = config('services.whatsapp.leads_bot_id');
        $perPage = $request->query('per_page', 20);

        $failedContacts = Contact::whereHas('botConversations', function($q) use ($botPhoneNumberId) {
            $q->where('phone_number_id', $botPhoneNumberId)
              ->whereJsonContains('context->crm_send_failed', true);
        })
        ->with(['botConversations' => function($q) use ($botPhoneNumberId) {
            $q->where('phone_number_id', $botPhoneNumberId)
              ->where('state', 'finished');
        }])
        ->paginate($perPage);

        return response()->json([
            'success' => true,
            'failed_leads' => $failedContacts
        ]);
    }
}
