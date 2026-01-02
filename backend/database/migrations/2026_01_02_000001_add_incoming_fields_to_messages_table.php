<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // Dirección del mensaje: 'outbound' (enviado) o 'inbound' (recibido)
            if (!Schema::hasColumn('messages', 'direction')) {
                $table->enum('direction', ['outbound', 'inbound'])->default('outbound')->after('status');
            }
            
            // Timestamp del mensaje (puede ser diferente a created_at)
            if (!Schema::hasColumn('messages', 'message_timestamp')) {
                $table->timestamp('message_timestamp')->nullable()->after('status');
            }
            
            // Estado de lectura
            if (!Schema::hasColumn('messages', 'read_at')) {
                $table->timestamp('read_at')->nullable()->after('message_timestamp');
            }
            
            if (!Schema::hasColumn('messages', 'delivered_at')) {
                $table->timestamp('delivered_at')->nullable()->after('read_at');
            }
            
            // Contenido del mensaje (para mensajes entrantes que no tienen campaign_id)
            if (!Schema::hasColumn('messages', 'message_content')) {
                $table->text('message_content')->nullable()->after('delivered_at');
            }
            
            // Campo phone adicional (algunos mensajes usan phone_number, otros phone)
            if (!Schema::hasColumn('messages', 'phone')) {
                $table->string('phone')->nullable()->after('phone_number');
            }
            
            // Campo error adicional (complementa error_message)
            if (!Schema::hasColumn('messages', 'error')) {
                $table->text('error')->nullable()->after('error_message');
            }
        });
        
        // Hacer campaign_id nullable para mensajes entrantes (en transacción separada)
        DB::statement('ALTER TABLE messages MODIFY campaign_id BIGINT UNSIGNED NULL');
        
        // Agregar índice para búsquedas rápidas por contacto
        Schema::table('messages', function (Blueprint $table) {
            $indexName = 'messages_contact_id_message_timestamp_index';
            $indexExists = collect(DB::select("SHOW INDEX FROM messages WHERE Key_name = '{$indexName}'"))->isNotEmpty();
            
            if (!$indexExists) {
                $table->index(['contact_id', 'message_timestamp']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            if (Schema::hasColumn('messages', 'direction')) {
                $table->dropColumn('direction');
            }
            if (Schema::hasColumn('messages', 'message_timestamp')) {
                $table->dropColumn('message_timestamp');
            }
            if (Schema::hasColumn('messages', 'read_at')) {
                $table->dropColumn('read_at');
            }
            if (Schema::hasColumn('messages', 'delivered_at')) {
                $table->dropColumn('delivered_at');
            }
            if (Schema::hasColumn('messages', 'message_content')) {
                $table->dropColumn('message_content');
            }
            if (Schema::hasColumn('messages', 'phone')) {
                $table->dropColumn('phone');
            }
            if (Schema::hasColumn('messages', 'error')) {
                $table->dropColumn('error');
            }
            
            $indexName = 'messages_contact_id_message_timestamp_index';
            $indexExists = collect(DB::select("SHOW INDEX FROM messages WHERE Key_name = '{$indexName}'"))->isNotEmpty();
            if ($indexExists) {
                $table->dropIndex(['contact_id', 'message_timestamp']);
            }
        });
    }
};
