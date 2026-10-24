<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * URL registrada por un cliente para recibir eventos firmados.
 *
 * @property int $id
 * @property string $public_id
 * @property int $api_client_id
 * @property string $url
 * @property string|null $description
 * @property string $secret
 * @property string|null $previous_secret
 * @property Carbon|null $previous_secret_expires_at
 * @property list<string> $events
 * @property bool $is_active
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read ApiClient $apiClient
 */
class WebhookEndpoint extends Model
{
    use HasPublicId, SoftDeletes;

    protected $fillable = ['url', 'description', 'secret', 'events', 'is_active'];

    protected $hidden = ['secret', 'previous_secret'];

    public static function publicIdPrefix(): string
    {
        return 'whk';
    }

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'previous_secret' => 'encrypted',
            'previous_secret_expires_at' => 'datetime',
            'events' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<ApiClient, $this> */
    public function apiClient(): BelongsTo
    {
        return $this->belongsTo(ApiClient::class);
    }

    /** @return HasMany<WebhookDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function subscribesTo(string $eventType): bool
    {
        return in_array($eventType, $this->events, true);
    }

    /**
     * Secretos con los que se firma: el actual y, durante el periodo de gracia
     * de una rotación, también el anterior.
     *
     * @return list<string>
     */
    public function signingSecrets(): array
    {
        $secrets = [$this->secret];

        if ($this->previous_secret !== null && $this->previous_secret_expires_at?->isFuture()) {
            $secrets[] = $this->previous_secret;
        }

        return $secrets;
    }

    public function scopeOwnedBy(Builder $query, ApiClient $client): Builder
    {
        return $query->where($this->qualifyColumn('api_client_id'), $client->id);
    }
}
