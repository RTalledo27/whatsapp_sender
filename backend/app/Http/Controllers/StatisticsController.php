<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class StatisticsController extends Controller
{
    /**
     * Obtener estadísticas generales del sistema
     */
    public function index(): JsonResponse
    {
        $stats = [
            'overview' => [
                'total_contacts' => Contact::count(),
                'total_campaigns' => Campaign::count(),
                'total_messages' => Message::count(),
                'messages_sent' => Message::where('status', 'sent')->count(),
                'messages_failed' => Message::where('status', 'failed')->count(),
                'messages_pending' => Message::where('status', 'pending')->count(),
            ],
            'campaigns_status' => Campaign::select('status', DB::raw('count(*) as count'))
                ->groupBy('status')
                ->get(),
            'recent_campaigns' => Campaign::orderBy('created_at', 'desc')
                ->limit(5)
                ->get(),
            'success_rate' => $this->calculateSuccessRate(),
            'messages_by_day' => $this->getMessagesByDay(),
            'top_contacts' => $this->getTopContacts(),
        ];

        return response()->json($stats);
    }

    /**
     * Calcular tasa de éxito general
     */
    private function calculateSuccessRate(): float
    {
        $total = Message::count();
        
        if ($total === 0) {
            return 0;
        }

        $sent = Message::where('status', 'sent')->count();
        
        return round(($sent / $total) * 100, 2);
    }

    /**
     * Obtener mensajes por día (últimos 7 días)
     */
    private function getMessagesByDay(): array
    {
        $messages = Message::where('created_at', '>=', now()->subDays(7))
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('count(*) as total'),
                DB::raw('sum(case when status = "sent" then 1 else 0 end) as sent'),
                DB::raw('sum(case when status = "failed" then 1 else 0 end) as failed')
            )
            ->groupBy('date')
            ->orderBy('date', 'desc')
            ->get()
            ->toArray();

        return $messages;
    }

    /**
     * Obtener contactos con más mensajes enviados
     */
    private function getTopContacts(): array
    {
        $contacts = Contact::withCount([
            'messages',
            'messages as sent_messages' => function ($query) {
                $query->where('status', 'sent');
            }
        ])
        ->having('messages_count', '>', 0)
        ->orderBy('messages_count', 'desc')
        ->limit(10)
        ->get()
        ->toArray();

        return $contacts;
    }

    /**
     * Exportar estadísticas
     */
    public function export(): JsonResponse
    {
        $campaigns = Campaign::with(['messages' => function ($query) {
            $query->with('contact');
        }])->get();

        $export = [];

        foreach ($campaigns as $campaign) {
            $export[] = [
                'campaign' => [
                    'id' => $campaign->id,
                    'name' => $campaign->name,
                    'message' => $campaign->message,
                    'status' => $campaign->status,
                    'total_contacts' => $campaign->total_contacts,
                    'sent_count' => $campaign->sent_count,
                    'failed_count' => $campaign->failed_count,
                    'pending_count' => $campaign->pending_count,
                    'created_at' => $campaign->created_at,
                ],
                'messages' => $campaign->messages->map(function ($message) {
                    return [
                        'phone_number' => $message->phone_number,
                        'contact_name' => $message->contact->name ?? 'N/A',
                        'status' => $message->status,
                        'error_message' => $message->error_message,
                        'sent_at' => $message->sent_at,
                    ];
                }),
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $export,
            'exported_at' => now(),
        ]);
    }
}
