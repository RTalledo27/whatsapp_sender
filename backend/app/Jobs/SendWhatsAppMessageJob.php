<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\Message;
use App\Services\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendWhatsAppMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 120;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Message $message
    ) {}

    /**
     * Execute the job.
     */
    public function handle(WhatsAppService $whatsAppService): void
    {
        try {
            Log::info('Sending WhatsApp message', [
                'message_id' => $this->message->id,
                'phone' => $this->message->phone_number,
            ]);

            $campaign = $this->message->campaign;
            
            // Determinar si se usa template o mensaje de texto
            $templateData = null;
            $message = null;

            if ($campaign->template_name) {
                $templateData = [
                    'name' => $campaign->template_name,
                    'language' => 'es',
                    'parameters' => $campaign->template_parameters ?? []
                ];
            } else {
                $message = $this->message->message;
            }

            $result = $whatsAppService->sendMessage(
                $this->message->phone_number,
                $message,
                $templateData
            );

            if ($result['success']) {
                $this->message->update([
                    'status' => 'sent',
                    'whatsapp_message_id' => $result['message_id'],
                    'sent_at' => now(),
                ]);

                Log::info('WhatsApp message sent successfully', [
                    'message_id' => $this->message->id,
                    'whatsapp_id' => $result['message_id'],
                ]);
            } else {
                $this->message->update([
                    'status' => 'failed',
                    'error_message' => $result['error'] ?? 'Error desconocido',
                ]);

                Log::error('WhatsApp message failed', [
                    'message_id' => $this->message->id,
                    'error' => $result['error'] ?? 'Unknown error',
                ]);
            }

            // Actualizar contadores de la campaña
            $this->message->campaign->updateCounts();

        } catch (\Exception $e) {
            Log::error('Exception in SendWhatsAppMessageJob', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->message->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            $this->message->campaign->updateCounts();

            throw $e;
        }
    }
}
