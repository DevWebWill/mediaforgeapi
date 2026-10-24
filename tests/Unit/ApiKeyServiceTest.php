<?php

namespace Tests\Unit;

use App\Models\ApiClient;
use App\Services\ApiKeys\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiKeyServiceTest extends TestCase
{
    use RefreshDatabase;

    private ApiKeyService $keys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->keys = app(ApiKeyService::class);
    }

    public function test_issued_token_authenticates_and_secret_is_hashed(): void
    {
        $issued = $this->keys->issue(ApiClient::factory()->create(), 'k');

        $this->assertMatchesRegularExpression('/^mfk_[a-f0-9]{16}_[A-Za-z0-9]{48}$/', $issued->plainTextToken);
        $this->assertStringNotContainsString($issued->key->secret_hash, $issued->plainTextToken);
        $this->assertSame($issued->key->id, $this->keys->authenticate($issued->plainTextToken)?->id);
    }

    public function test_wrong_secret_malformed_revoked_and_expired_tokens_fail(): void
    {
        $issued = $this->keys->issue(ApiClient::factory()->create(), 'k');
        $tampered = substr($issued->plainTextToken, 0, -1).(str_ends_with($issued->plainTextToken, 'a') ? 'b' : 'a');

        $this->assertNull($this->keys->authenticate($tampered));
        $this->assertNull($this->keys->authenticate('nope'));

        $this->keys->revoke($issued->key);
        $this->assertNull($this->keys->authenticate($issued->plainTextToken));

        $expired = $this->keys->issue(ApiClient::factory()->create(), 'k', now()->subMinute());
        $this->assertNull($this->keys->authenticate($expired->plainTextToken));
    }

    public function test_rotation_without_grace_revokes_old_key(): void
    {
        $old = $this->keys->issue(ApiClient::factory()->create(), 'k');
        $new = $this->keys->rotate($old->key);

        $this->assertNull($this->keys->authenticate($old->plainTextToken));
        $this->assertNotNull($this->keys->authenticate($new->plainTextToken));
    }

    public function test_rotation_with_grace_keeps_old_key_temporarily(): void
    {
        $old = $this->keys->issue(ApiClient::factory()->create(), 'k');
        $this->keys->rotate($old->key, graceMinutes: 10);

        $this->assertNotNull($this->keys->authenticate($old->plainTextToken));

        $this->travel(11)->minutes();
        $this->assertNull($this->keys->authenticate($old->plainTextToken));
    }
}
