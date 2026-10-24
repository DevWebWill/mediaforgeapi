<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('api_client_id')->constrained()->restrictOnDelete();
            // Trabajo que generó el archivo (solo resultados y miniaturas); FK añadida en la migración de trabajos
            $table->unsignedBigInteger('media_job_id')->nullable()->index();
            $table->string('kind', 20);
            $table->string('disk', 40);
            $table->string('path', 500);
            // Nombre original saneado, solo informativo: nunca se usa como ruta
            $table->string('original_name', 255)->nullable();
            $table->string('extension', 10);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->char('checksum_sha256', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['api_client_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
