<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comercio_telefonos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comercio_id')->constrained('comercios')->onDelete('cascade');
            $table->string('telefono');              // phone_number_id de WhatsApp Business
            $table->string('tipo_flujo')->default('normal');
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index(['comercio_id', 'activo']);
            $table->index('telefono');
            $table->index('tipo_flujo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comercio_telefonos');
    }
};
