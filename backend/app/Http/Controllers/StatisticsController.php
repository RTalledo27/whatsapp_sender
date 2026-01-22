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
        $phoneNumberId = request('phone_number_id');
        
        $sentStatuses = ['sent', 'delivered', 'read'];
        $stats = [
            'overview' => [
                'total_contacts' => Contact::count(),
                'total_campaigns' => Campaign::byPhoneNumberId($phoneNumberId)->count(),
                'total_messages' => Message::byPhoneNumberId($phoneNumberId)->count(),
                'messages_sent' => Message::byPhoneNumberId($phoneNumberId)->whereIn('status', $sentStatuses)->count(),
                'messages_failed' => Message::byPhoneNumberId($phoneNumberId)->where('status', 'failed')->count(),
                'messages_pending' => Message::byPhoneNumberId($phoneNumberId)->where('status', 'pending')->count(),
                'customer_service_number' => env('WHATSAPP_PHONE_NUMBER', '51 922 902 212'),
                'customer_service_id' => env('WHATSAPP_PHONE_NUMBER_ID', '1000703976450053'),
                'community_number' => env('WHATSAPP_ALT_PHONE_NUMBER', '51 922 902 154'),
                'community_id' => env('WHATSAPP_ALT_PHONE_NUMBER_ID', '1003622752825127'),
            ],
            'campaigns_status' => Campaign::byPhoneNumberId($phoneNumberId)
                ->select('status', DB::raw('count(*) as count'))
                ->groupBy('status')
                ->get(),
            'recent_campaigns' => Campaign::byPhoneNumberId($phoneNumberId)
                ->orderBy('created_at', 'desc')
                ->limit(3)
                ->get(),
            'success_rate' => $this->calculateSuccessRate($phoneNumberId, $sentStatuses),
            'messages_by_day' => $this->getMessagesByDay($phoneNumberId, $sentStatuses),
            'top_contacts' => $this->getTopContacts($phoneNumberId),
        ];

        return response()->json($stats);
    }

    /**
     * Calcular tasa de éxito general
     */
    private function calculateSuccessRate($phoneNumberId = null): float
    {
        $args = func_get_args();
        $sentStatuses = isset($args[1]) ? $args[1] : ['sent', 'delivered', 'read'];
        $total = Message::byPhoneNumberId($phoneNumberId)->count();
        if ($total === 0) {
            return 0;
        }
        $sent = Message::byPhoneNumberId($phoneNumberId)->whereIn('status', $sentStatuses)->count();
        return round(($sent / $total) * 100, 2);
    }

    /**
     * Obtener mensajes por día (últimos 7 días)
     */
    private function getMessagesByDay($phoneNumberId = null): array
    {
        $args = func_get_args();
        $sentStatuses = isset($args[1]) ? $args[1] : ['sent', 'delivered', 'read'];
        $statusesSql = implode('", "', $sentStatuses);
        $messages = Message::byPhoneNumberId($phoneNumberId)
            ->where('created_at', '>=', now()->subDays(7))
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('count(*) as total'),
                DB::raw("sum(case when status in (\"$statusesSql\") then 1 else 0 end) as sent"),
                DB::raw('sum(case when status = "failed" then 1 else 0 end) as failed')
            )
            ->groupBy('date')
            ->orderBy('date', 'desc')
            ->get()
            ->map(function($item) {
                return [
                    'date' => $item['date'],
                    'total' => (int) $item['total'],
                    'sent' => (int) $item['sent'],
                    'failed' => (int) $item['failed'],
                ];
            })
            ->toArray();

        return $messages;
    }

    /**
     * Obtener contactos con más mensajes enviados
     */
    private function getTopContacts($phoneNumberId = null): array
    {
        $query = Contact::withCount([
            'messages' => function ($q) use ($phoneNumberId) {
                if ($phoneNumberId) {
                    $q->where('phone_number_id', $phoneNumberId);
                }
            },
            'messages as sent_messages' => function ($query) use ($phoneNumberId) {
                $query->where('status', 'sent');
                if ($phoneNumberId) {
                    $query->where('phone_number_id', $phoneNumberId);
                }
            }
        ])
        ->having('messages_count', '>', 0)
        ->orderBy('messages_count', 'desc')
        ->limit(10);

        return $query->get()->toArray();
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
