<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Índices para messages
        try {
            DB::statement('CREATE INDEX messages_contact_id_created_at_index ON messages(contact_id, created_at)');
        } catch (\Exception $e) {
            // Índice ya existe
        }
        
        try {
            DB::statement('CREATE INDEX messages_direction_read_at_index ON messages(direction, read_at)');
        } catch (\Exception $e) {
            // Índice ya existe
        }
        
        try {
            DB::statement('CREATE INDEX msg_phone_contact_ts ON messages(phone_number_id, contact_id, message_timestamp)');
        } catch (\Exception $e) {
            // Índice ya existe
        }

        // Índices para contacts
        try {
            DB::statement('CREATE INDEX contacts_name_index ON contacts(name)');
        } catch (\Exception $e) {
            // Índice ya existe
        }
        
        try {
            DB::statement('CREATE INDEX contacts_contact_type_index ON contacts(contact_type)');
        } catch (\Exception $e) {
            // Índice ya existe
        }
        
        try {
            DB::statement('CREATE INDEX contacts_contact_type_created_at_index ON contacts(contact_type, created_at)');
        } catch (\Exception $e) {
            // Índice ya existe
        }

        // Índices para campaigns
        try {
            DB::statement('CREATE INDEX campaigns_status_index ON campaigns(status)');
        } catch (\Exception $e) {
            // Índice ya existe
        }
        
        try {
            DB::statement('CREATE INDEX campaigns_phone_number_id_status_index ON campaigns(phone_number_id, status)');
        } catch (\Exception $e) {
            // Índice ya existe
        }

        // Índices para bot_conversations
        try {
            DB::statement('CREATE INDEX bot_conversations_state_last_interaction_at_index ON bot_conversations(state, last_interaction_at)');
        } catch (\Exception $e) {
            // Índice ya existe
        }
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('messages_contact_id_created_at_index');
            $table->dropIndex('messages_direction_read_at_index');
            $table->dropIndex('msg_phone_contact_ts');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex('contacts_name_index');
            $table->dropIndex('contacts_contact_type_index');
            $table->dropIndex('contacts_contact_type_created_at_index');
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropIndex('campaigns_status_index');
            $table->dropIndex('campaigns_phone_number_id_status_index');
        });

        Schema::table('bot_conversations', function (Blueprint $table) {
            $table->dropIndex('bot_conversations_state_last_interaction_at_index');
        });
    }
};
