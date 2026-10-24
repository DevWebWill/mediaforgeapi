<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('api_client_id')->constrained()->restrictOnDelete();
            $table->string('url', 2048);
            $table->string('description', 255)->nullable();
            // Cifrados con APP_KEY: se necesitan en claro para firmar
            $table->text('secret');
            $table->text('previous_secret')->nullable();
            $table->timestamp('previous_secret_expires_at')->nullable();
            $table->json('events');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['api_client_id', 'is_active']);
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('api_client_id')->constrained()->restrictOnDelete();
            $table->foreignId('media_job_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 60);
            // Evita emitir dos veces el mismo evento para la misma transición
            $table->string('dedupe_key', 191)->unique();
            $table->json('payload');
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('webhook_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('webhook_endpoint_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('last_status_code')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->unsignedInteger('last_duration_ms')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->unique(['webhook_event_id', 'webhook_endpoint_id']);
            $table->index(['webhook_endpoint_id', 'id']);
            $table->index(['status', 'next_attempt_at']);
        });

        Schema::table('media_jobs', function (Blueprint $table) {
            // Webhook concreto elegido por el cliente para este trabajo (debe estar registrado)
            $table->foreignId('webhook_endpoint_id')->nullable()->after('reference')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('media_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('webhook_endpoint_id');
        });

        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('webhook_endpoints');
    }
};
