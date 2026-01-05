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
        Schema::table('messages', function (Blueprint $table) {
            $table->string('message_type')->nullable()->after('message_content')->comment('text, image, video, audio, document, reaction, etc');
            $table->text('media_url')->nullable()->after('message_type')->comment('URL del archivo multimedia');
            $table->string('media_id')->nullable()->after('media_url')->comment('ID del media en WhatsApp');
            $table->json('metadata')->nullable()->after('media_id')->comment('Metadata adicional (reaction emoji, caption, etc)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['message_type', 'media_url', 'media_id', 'metadata']);
        });
    }
};
