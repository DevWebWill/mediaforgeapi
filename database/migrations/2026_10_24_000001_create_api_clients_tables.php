<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_clients', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->string('name', 120);
            $table->string('status', 20)->default('active');
            // Límites propios; null = valor por defecto de config/mediaforge.php
            $table->unsignedInteger('rate_limit_per_minute')->nullable();
            $table->unsignedInteger('max_active_jobs')->nullable();
            $table->unsignedBigInteger('storage_quota_bytes')->nullable();
            $table->unsignedBigInteger('max_upload_bytes')->nullable();
            $table->string('max_priority', 10)->nullable();
            $table->timestamps();
        });

        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_client_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            // Identificador público de la clave (no secreto), parte del token
            $table->string('key_id', 32)->unique();
            $table->char('secret_hash', 64);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
        Schema::dropIfExists('api_clients');
    }
};
