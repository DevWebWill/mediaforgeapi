<?php

namespace App\Console\Commands;

use App\Enums\ClientStatus;
use App\Models\ApiClient;
use Illuminate\Console\Command;

class SetApiClientStatus extends Command
{
    protected $signature = 'mediaforge:client:status {client : Id público del cliente (cli_...)} {status : active|suspended}';

    protected $description = 'Activa o suspende un cliente API';

    public function handle(): int
    {
        $status = ClientStatus::tryFrom((string) $this->argument('status'));
        $client = ApiClient::query()->wherePublicId((string) $this->argument('client'))->first();

        if ($status === null || $client === null) {
            $this->error('Cliente o estado no válido.');

            return self::INVALID;
        }

        $client->update(['status' => $status]);
        $this->info("Cliente {$client->public_id}: {$status->value}");

        return self::SUCCESS;
    }
}
