<?php

namespace Database\Factories;

use App\Enums\ClientStatus;
use App\Models\ApiClient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiClient>
 */
class ApiClientFactory extends Factory
{
    protected $model = ApiClient::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'status' => ClientStatus::Active,
        ];
    }

    public function suspended(): static
    {
        return $this->state(['status' => ClientStatus::Suspended]);
    }
}
