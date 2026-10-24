<?php

namespace Tests\Feature\Api;

use App\Models\ApiClient;
use App\Services\ApiKeys\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_key_is_accepted(): void
    {
        [, $headers] = $this->apiClient();

        $this->getJson('/api/v1/operations', $headers)->assertOk()->assertHeader('X-Request-Id');
    }

    public function test_missing_key_returns_401_with_error_contract(): void
    {
        $this->getJson('/api/v1/jobs')
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer')
            ->assertJsonPath('error.code', 'unauthenticated')
            ->assertJsonStructure(['error' => ['code', 'message', 'request_id']]);
    }

    public function test_invalid_key_returns_401(): void
    {
        $this->getJson('/api/v1/jobs', ['Authorization' => 'Bearer mfk_0000000000000000_'.str_repeat('a', 48)])
            ->assertUnauthorized();
    }

    public function test_key_in_query_string_is_not_accepted(): void
    {
        $client = ApiClient::factory()->create();
        $token = app(ApiKeyService::class)->issue($client, 'k')->plainTextToken;

        $this->getJson('/api/v1/jobs?api_key='.$token)->assertUnauthorized();
        $this->getJson('/api/v1/jobs?token='.$token)->assertUnauthorized();
    }

    public function test_revoked_and_expired_keys_return_401(): void
    {
        $client = ApiClient::factory()->create();
        $keys = app(ApiKeyService::class);

        $revoked = $keys->issue($client, 'r');
        $keys->revoke($revoked->key);
        $expired = $keys->issue($client, 'e', now()->subSecond());

        $this->getJson('/api/v1/jobs', ['Authorization' => 'Bearer '.$revoked->plainTextToken])->assertUnauthorized();
        $this->getJson('/api/v1/jobs', ['Authorization' => 'Bearer '.$expired->plainTextToken])->assertUnauthorized();
    }

    public function test_suspended_client_returns_403(): void
    {
        [, $headers] = $this->apiClient(['status' => 'suspended']);

        $this->getJson('/api/v1/jobs', $headers)->assertForbidden()->assertJsonPath('error.code', 'client_suspended');
    }

    public function test_rate_limit_is_per_client(): void
    {
        [, $limited] = $this->apiClient(['rate_limit_per_minute' => 2]);
        [, $other] = $this->apiClient();

        $this->getJson('/api/v1/operations', $limited)->assertOk();
        $this->getJson('/api/v1/operations', $limited)->assertOk();
        $this->getJson('/api/v1/operations', $limited)->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
        $this->getJson('/api/v1/operations', $other)->assertOk();
    }

    public function test_unknown_api_route_uses_error_contract(): void
    {
        $this->getJson('/api/v1/nope')->assertNotFound()->assertJsonPath('error.code', 'not_found');
    }
}
