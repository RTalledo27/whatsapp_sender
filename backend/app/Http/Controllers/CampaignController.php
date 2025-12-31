<?php

namespace App\Http\Controllers;

use App\Jobs\SendWhatsAppMessageJob;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CampaignController extends Controller
{
    /**
     * Listar todas las campañas
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->input('per_page', 20);

        $campaigns = Campaign::withCount('messages')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json($campaigns);
    }

    /**
     * Obtener una campaña específica
     */
    public function show(Campaign $campaign): JsonResponse
    {
        // Para polling, solo necesitamos datos básicos sin cargar todos los mensajes
        $campaign->makeHidden(['messages']);
        
        return response()->json([
            'id' => $campaign->id,
            'name' => $campaign->name,
            'status' => $campaign->status,
            'total_contacts' => $campaign->total_contacts,
            'sent_count' => $campaign->sent_count,
            'failed_count' => $campaign->failed_count,
            'pending_count' => $campaign->pending_count,
        ]);
    }

    /**
     * Obtener detalles completos de una campaña con mensajes
     */
    public function details(Campaign $campaign): JsonResponse
    {
        $campaign->load(['messages' => function ($query) {
            $query->orderBy('created_at', 'desc');
        }]);

        return response()->json($campaign);
    }

    /**
     * Crear nueva campaña y enviar mensajes
     */
    public function store(Request $request): JsonResponse
    {
        $rules = [
            'name' => 'required|string|max:255',
            'contact_ids' => 'required|array|min:1',
            'contact_ids.*' => 'exists:contacts,id',
        ];

        // Si no usa template, el mensaje es requerido
        if (!$request->has('template_name')) {
            $rules['message'] = 'required|string';
        } else {
            $rules['template_name'] = 'required|string';
            $rules['template_parameters'] = 'nullable|array';
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            // Crear campaña
            $campaign = Campaign::create([
                'name' => $request->name,
                'message' => $request->message,
                'template_name' => $request->template_name,
                'template_parameters' => $request->template_parameters,
                'status' => 'pending',
                'total_contacts' => count($request->contact_ids),
                'pending_count' => count($request->contact_ids),
            ]);

            // Obtener contactos
            $contacts = Contact::whereIn('id', $request->contact_ids)->get();

            // Crear mensajes
            foreach ($contacts as $contact) {
                // Preparar el mensaje de texto
                $messageText = $request->message;
                if (!$messageText && $request->template_name) {
                    $messageText = "Template: {$request->template_name}";
                }

                $message = Message::create([
                    'campaign_id' => $campaign->id,
                    'contact_id' => $contact->id,
                    'phone_number' => $contact->phone_number,
                    'message' => $messageText,
                    'status' => 'pending',
                ]);

                // Despachar job para enviar el mensaje
                SendWhatsAppMessageJob::dispatch($message);
            }

            // Actualizar estado de la campaña
            $campaign->update([
                'status' => 'processing',
                'started_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Campaña creada exitosamente',
                'data' => $campaign->load('messages'),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Obtener estadísticas de una campaña
     */
    public function statistics(Campaign $campaign): JsonResponse
    {
        $stats = [
            'campaign' => $campaign,
            'total_messages' => $campaign->messages()->count(),
            'sent' => $campaign->messages()->where('status', 'sent')->count(),
            'failed' => $campaign->messages()->where('status', 'failed')->count(),
            'pending' => $campaign->messages()->where('status', 'pending')->count(),
            'success_rate' => 0,
            'messages_by_status' => $campaign->messages()
                ->select('status', DB::raw('count(*) as count'))
                ->groupBy('status')
                ->get(),
            'recent_messages' => $campaign->messages()
                ->with('contact')
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get(),
        ];

        $total = $stats['total_messages'];
        if ($total > 0) {
            $stats['success_rate'] = round(($stats['sent'] / $total) * 100, 2);
        }

        return response()->json($stats);
    }

    /**
     * Eliminar campaña
     */
    public function destroy(Campaign $campaign): JsonResponse
    {
        $campaign->delete();

        return response()->json([
            'success' => true,
            'message' => 'Campaña eliminada exitosamente',
        ]);
    }

    /**
     * Reintentar mensajes fallidos
     */
    public function retryFailed(Campaign $campaign): JsonResponse
    {
        $failedMessages = $campaign->messages()->where('status', 'failed')->get();

        if ($failedMessages->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No hay mensajes fallidos para reintentar',
            ], 400);
        }

        foreach ($failedMessages as $message) {
            $message->update([
                'status' => 'pending',
                'error_message' => null,
            ]);

            SendWhatsAppMessageJob::dispatch($message);
        }

        return response()->json([
            'success' => true,
            'message' => 'Reintentando ' . $failedMessages->count() . ' mensajes fallidos',
            'count' => $failedMessages->count(),
        ]);
    }
}
