<?php

namespace App\Models;

use App\Enums\ConnectionType;
use App\Support\ClientCacheBuster;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PGConnection extends Model
{
    protected $table = 'pg_connections';

    protected $casts = [
        'attributes' => 'array',
        'status' => 'boolean',
        'type' => ConnectionType::class,
    ];

    protected static function booted(): void
    {
        static::saved(fn (PGConnection $pgConnection) => static::bustLinkedClientCaches($pgConnection));
        static::deleted(fn (PGConnection $pgConnection) => static::bustLinkedClientCaches($pgConnection));
    }

    protected static function bustLinkedClientCaches(PGConnection $pgConnection): void
    {
        $pgConnection->clientConnections()
            ->with('client')
            ->get()
            ->each(fn (ClientConnection $connection) => ClientCacheBuster::forgetClientPgConnection($connection->client?->client_id));
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * @return HasMany<ClientConnection, $this>
     */
    public function clientConnections(): HasMany
    {
        return $this->hasMany(ClientConnection::class, 'pg_connection_id');
    }
}
