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
    public function handle(): void
    {
        try {
            $campaign = $this->message->campaign->fresh();
            
            Log::info('SendWhatsAppMessageJob: Starting', [
                'message_id' => $this->message->id,
                'campaign_id' => $campaign->id,
                'has_template' => !empty($campaign->template_name),
            ]);

            // Determinar si se usa template o mensaje de texto
            $templateData = null;
            $message = null;

            if (!empty($campaign->template_name)) {
                $templateData = [
                    'name' => $campaign->template_name,
                    'language' => 'es',
                    'parameters' => $campaign->template_parameters ?? []
                ];

                // Agregar video_link si existe
                if ($campaign->video_link && !empty($campaign->video_link)) {
                    $templateData['video_link'] = $campaign->video_link;
                }
                
                Log::info('Using WhatsApp Template', ['template_name' => $templateData['name']]);
            } else {
                $message = $this->message->message;
                Log::info('Using Text Message');
            }

            // Obtener phone_number_id de la campaña o usar el por defecto
            $phoneNumberId = $campaign->phone_number_id ?? config('services.whatsapp.phone_number_id');
            
            // Crear instancia del servicio WhatsApp
            $whatsAppService = new WhatsAppService($phoneNumberId);

            $result = $whatsAppService->sendMessage(
                $this->message->phone_number,
                $message,
                $templateData
            );

            if ($result['success']) {
                $this->message->update([
                    'phone_number_id' => $phoneNumberId,
                    'status' => 'sent',
                    'whatsapp_message_id' => $result['message_id'],
                    'sent_at' => now(),
                ]);

                Log::info('WhatsApp message sent successfully', [
                    'message_id' => $this->message->id,
                    'whatsapp_id' => $result['message_id'],
                ]);

                // Delay para evitar ban por spam
                $delaySeconds = config('services.whatsapp.delay_between_messages', 2);
                if ($delaySeconds > 0) {
                    Log::info('Waiting before next message', ['seconds' => $delaySeconds]);
                    sleep($delaySeconds);
                }
            } else {
                Log::warning('Updating failed message with phone_number_id', [
                   'message_id' => $this->message->id,
                   'phone_number_id' => $phoneNumberId,
                   'error' => $result['error'] ?? 'Unknown'
                ]);

                $this->message->update([
                    'phone_number_id' => $phoneNumberId,
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

            $phoneNumberId = $this->message->campaign->phone_number_id ?? config('services.whatsapp.phone_number_id');
            
            Log::warning('Updating exception failed message with phone_number_id', [
                'message_id' => $this->message->id,
                'phone_number_id' => $phoneNumberId
             ]);

            $this->message->update([
                'phone_number_id' => $phoneNumberId,
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            $this->message->campaign->updateCounts();

            // Delay más largo después de error
            $delaySeconds = config('services.whatsapp.delay_on_error', 5);
            if ($delaySeconds > 0) {
                Log::info('Waiting after error before next message', ['seconds' => $delaySeconds]);
                sleep($delaySeconds);
            }

            throw $e;
        }
    }
}
