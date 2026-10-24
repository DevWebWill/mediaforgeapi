<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('api_client_id')->constrained()->restrictOnDelete();
            $table->foreignId('input_asset_id')->constrained('media_assets')->restrictOnDelete();

            $table->string('operation', 40);
            $table->string('profile', 60)->nullable();
            $table->unsignedSmallInteger('profile_version')->nullable();
            // Parámetros ya validados y resueltos (perfil + valores del cliente)
            $table->json('parameters');
            $table->string('status', 20);
            $table->string('priority', 10);
            $table->string('queue', 40);
            $table->string('reference', 191)->nullable();
            $table->string('request_id', 64)->nullable();

            $table->string('idempotency_key', 255)->nullable();
            $table->char('request_fingerprint', 64)->nullable();
            $table->timestamp('idempotency_expires_at')->nullable();

            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('processing_deadline')->nullable();
            $table->unsignedInteger('deadline_missed_by_seconds')->nullable();

            $table->unsignedTinyInteger('progress')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts');
            $table->string('error_code', 60)->nullable();
            $table->string('error_message', 1000)->nullable();
            $table->json('result')->nullable();

            // Token de exclusión: solo el worker que reclamó el trabajo puede finalizarlo
            $table->string('worker_token', 64)->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();

            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedBigInteger('processing_ms')->nullable();
            $table->timestamps();

            $table->unique(['api_client_id', 'idempotency_key']);
            // Listados por cliente (orden por id) y filtrados por estado
            $table->index(['api_client_id', 'id']);
            $table->index(['api_client_id', 'status', 'id']);
            $table->index(['status', 'heartbeat_at']);
        });

        Schema::table('media_assets', function (Blueprint $table) {
            $table->foreign('media_job_id')->references('id')->on('media_jobs')->nullOnDelete();
        });

        Schema::create('media_job_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_job_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->string('message', 500)->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_job_events');
        Schema::table('media_assets', function (Blueprint $table) {
            $table->dropForeign(['media_job_id']);
        });
        Schema::dropIfExists('media_jobs');
    }
};
