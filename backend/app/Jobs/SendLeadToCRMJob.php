<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Models\BotConversation;
use App\Services\LogicWareService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job para enviar leads calificados al CRM de LogicWare
 * 
 * Este job se encola para no bloquear el flujo del bot
 * y permite reintentos automáticos en caso de fallo
 */
class SendLeadToCRMJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Número de intentos antes de fallar definitivamente
     */
    public $tries = 3;

    /**
     * Timeout para la ejecución del job (en segundos)
     */
    public $timeout = 30;

    /**
     * Tiempo de espera entre reintentos (en segundos)
     * [1 minuto, 5 minutos, 15 minutos]
     */
    public $backoff = [60, 300, 900];

    protected Contact $contact;
    protected BotConversation $conversation;

    /**
     * Create a new job instance.
     */
    public function __construct(Contact $contact, BotConversation $conversation)
    {
        $this->contact = $contact;
        $this->conversation = $conversation;
    }

    /**
     * Execute the job.
     */
    public function handle(LogicWareService $logicwareService): void
    {
        Log::info('SendLeadToCRMJob: Starting', [
            'contact_id' => $this->contact->id,
            'phone' => $this->contact->phone_number,
            'attempt' => $this->attempts(),
            'max_tries' => $this->tries
        ]);

        try {
            // Enviar lead al CRM
            $result = $logicwareService->createQualifiedLead($this->contact, $this->conversation);

            if (!$result['success']) {
                // Si falla, lanzar excepción para que se reintente
                throw new \Exception($result['error'] ?? 'Failed to send lead to CRM');
            }

            Log::info('SendLeadToCRMJob: Completed successfully', [
                'contact_id' => $this->contact->id,
                'lead_id' => $result['lead_id'] ?? null,
                'assigned_to' => $result['assigned_to'] ?? null
            ]);

        } catch (\Exception $e) {
            Log::error('SendLeadToCRMJob: Error', [
                'contact_id' => $this->contact->id,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage()
            ]);

            // Relanzar la excepción para que Laravel maneje el reintento
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SendLeadToCRMJob: Failed after all retries', [
            'contact_id' => $this->contact->id,
            'phone' => $this->contact->phone_number,
            'conversation_id' => $this->conversation->id,
            'total_attempts' => $this->tries,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        // Marcar en el contexto que falló el envío al CRM
        $context = $this->conversation->context ?? [];
        $context['crm_send_failed'] = true;
        $context['crm_send_error'] = $exception->getMessage();
        $context['crm_send_failed_at'] = now()->toIso8601String();
        $this->conversation->context = $context;
        $this->conversation->save();

        // Opcional: Enviar notificación a administradores
        // Mail::to('admin@casabonita.pe')->send(new LeadSyncFailedMail($this->contact, $exception));
        
        // Opcional: Crear una nota en el sistema
        // Note::create([
        //     'contact_id' => $this->contact->id,
        //     'content' => "❌ Error al enviar lead al CRM: {$exception->getMessage()}",
        //     'created_by' => 'system'
        // ]);
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return [
            'crm-sync',
            "contact:{$this->contact->id}",
            "phone:{$this->contact->phone_number}"
        ];
    }
}
