<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bot_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->onDelete('cascade');
            $table->string('phone_number_id'); // WhatsApp phone number ID del bot
            $table->string('state')->default('initial'); // Estado actual del flujo
            $table->json('context')->nullable(); // Respuestas recopiladas
            $table->timestamp('last_interaction_at')->nullable();
            $table->timestamps();

            // Índices para búsquedas rápidas
            $table->index(['contact_id', 'phone_number_id']);
            $table->index('state');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bot_conversations');
    }
};
