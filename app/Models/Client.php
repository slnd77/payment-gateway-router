<?php

namespace App\Models;

use App\Support\ClientCacheBuster;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

// Use
class Client extends Model
{
    use SoftDeletes;

    protected $casts = [
        'data' => 'array',
        'status' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saved(function (Client $client): void {
            ClientCacheBuster::forgetClient($client->id);
            ClientCacheBuster::forgetClientPgConnection($client->client_id);

            if ($client->isDirty('client_id')) {
                ClientCacheBuster::forgetClientPgConnection($client->getOriginal('client_id'));
            }
        });

        static::deleted(function (Client $client): void {
            ClientCacheBuster::forgetClient($client->id);
            ClientCacheBuster::forgetClientPgConnection($client->client_id);
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * @return HasManyThrough<SubscriptionTransaction, Subscription, $this>
     */
    public function subscriptionTransactions(): HasManyThrough
    {
        return $this->hasManyThrough(SubscriptionTransaction::class, Subscription::class);
    }

    /**
     * @return HasOne<ClientConnection, $this>
     */
    public function connections(): HasOne
    {
        return $this->hasOne(ClientConnection::class);
    }

    /**
     * @return HasOne<ClientConnection, $this>
     */
    public function oneTimePGConnection(): HasOne
    {
        return $this->hasOne(ClientConnection::class)
            ->where('is_recurring', '=', false);
    }

    /**
     * @return HasMany<ClientCustomer, $this>
     */
    public function customers(): HasMany
    {
        return $this->hasMany(ClientCustomer::class);
    }
}
