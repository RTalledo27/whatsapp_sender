<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultas', function (Blueprint $table) {
            $table->id();
            $table->string('dni', 20);
            $table->enum('tipo_evento', ['consulta', 'consumo']);
            $table->enum('resultado', ['apto', 'no_apto', 'no_encontrado'])->nullable();
            $table->string('telefono_origen')->nullable();
            $table->foreignId('comercio_id')->nullable()->constrained('comercios')->onDelete('set null');
            $table->string('flujo_tipo')->default('normal');
            $table->string('canal')->default('whatsapp');
            $table->smallInteger('status_http')->nullable();
            $table->unsignedInteger('tiempo_respuesta_ms')->nullable();
            $table->timestamp('fecha_consulta')->useCurrent();
            $table->timestamps();

            // Índices para métricas
            $table->index('dni');
            $table->index('tipo_evento');
            $table->index('resultado');
            $table->index('fecha_consulta');
            $table->index(['dni', 'tipo_evento']);
            $table->index(['comercio_id', 'fecha_consulta']);
            $table->index(['resultado', 'fecha_consulta']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultas');
    }
};
