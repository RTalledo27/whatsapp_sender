<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // Dirección del mensaje: 'outbound' (enviado) o 'inbound' (recibido)
            $table->enum('direction', ['outbound', 'inbound'])->default('outbound')->after('status');
            
            // ID del mensaje de WhatsApp para tracking
            $table->string('whatsapp_message_id')->nullable()->after('direction');
            
            // Timestamp del mensaje (puede ser diferente a created_at)
            $table->timestamp('message_timestamp')->nullable()->after('whatsapp_message_id');
            
            // Estado de lectura
            $table->timestamp('read_at')->nullable()->after('message_timestamp');
            $table->timestamp('delivered_at')->nullable()->after('read_at');
            
            // Contenido del mensaje (para mensajes entrantes que no tienen campaign_id)
            $table->text('message_content')->nullable()->after('delivered_at');
            
            // Hacer campaign_id nullable para mensajes entrantes
            $table->unsignedBigInteger('campaign_id')->nullable()->change();
            
            // Índice para búsquedas rápidas por contacto
            $table->index(['contact_id', 'message_timestamp']);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn([
                'direction',
                'whatsapp_message_id',
                'message_timestamp',
                'read_at',
                'delivered_at',
                'message_content'
            ]);
            
            $table->dropIndex(['contact_id', 'message_timestamp']);
        });
    }
};
