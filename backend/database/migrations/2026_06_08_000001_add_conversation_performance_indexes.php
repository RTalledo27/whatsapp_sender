<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración de índices de rendimiento para el módulo de conversaciones.
 *
 * Índices añadidos (verificados con CodeGraph — cubren las consultas críticas):
 *
 * messages:
 *   - (contact_id, phone_number_id, message_timestamp) → bandeja de entrada (index query)
 *   - (phone_number_id, direction, read_at)            → conteo de no leídos en stats
 *   - (whatsapp_message_id)                            → lookup en processMessageStatus y deduplicación del Job
 *   - (campaign_id)                                    → resolución de flujo por campaña (BotService línea 337)
 *
 * bot_conversations:
 *   - (phone_number_id, state)                         → filtros de bot_status en ConversationController::index
 *   - (last_interaction_at)                            → filtro de inactivos
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // Cubre la subconsulta de último mensaje y el ORDER BY de la bandeja
            if (!$this->indexExists('messages', 'messages_contact_phone_timestamp_idx')) {
                $table->index(
                    ['contact_id', 'phone_number_id', 'message_timestamp'],
                    'messages_contact_phone_timestamp_idx'
                );
            }

            // Cubre el SUM(CASE WHEN direction='inbound' AND read_at IS NULL) de stats
            if (!$this->indexExists('messages', 'messages_phone_direction_read_idx')) {
                $table->index(
                    ['phone_number_id', 'direction', 'read_at'],
                    'messages_phone_direction_read_idx'
                );
            }

            // Cubre el lookup de processMessageStatus y la deduplicación del ProcessIncomingMessageJob
            if (!$this->indexExists('messages', 'messages_wamid_idx')) {
                $table->index('whatsapp_message_id', 'messages_wamid_idx');
            }

            // Cubre la resolución de flujo por campaign_id en BotService::handleIncomingMessage
            if (!$this->indexExists('messages', 'messages_campaign_id_idx')) {
                $table->index('campaign_id', 'messages_campaign_id_idx');
            }
        });

        Schema::table('bot_conversations', function (Blueprint $table) {
            // Cubre los filtros de bot_status en ConversationController::index
            if (!$this->indexExists('bot_conversations', 'bot_conv_phone_state_idx')) {
                $table->index(
                    ['phone_number_id', 'state'],
                    'bot_conv_phone_state_idx'
                );
            }

            // Cubre el filtro de inactivos (last_interaction_at < now - 24h)
            if (!$this->indexExists('bot_conversations', 'bot_conv_last_interaction_idx')) {
                $table->index('last_interaction_at', 'bot_conv_last_interaction_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndexIfExists('messages_contact_phone_timestamp_idx');
            $table->dropIndexIfExists('messages_phone_direction_read_idx');
            $table->dropIndexIfExists('messages_wamid_idx');
            $table->dropIndexIfExists('messages_campaign_id_idx');
        });

        Schema::table('bot_conversations', function (Blueprint $table) {
            $table->dropIndexIfExists('bot_conv_phone_state_idx');
            $table->dropIndexIfExists('bot_conv_last_interaction_idx');
        });
    }

    /**
     * Verifica si un índice ya existe para evitar errores si la migración se corre dos veces.
     */
    private function indexExists(string $table, string $indexName): bool
    {
        $indexes = \Illuminate\Support\Facades\DB::select("SHOW INDEX FROM {$table} WHERE Key_name = ?", [$indexName]);
        return count($indexes) > 0;
    }
};
