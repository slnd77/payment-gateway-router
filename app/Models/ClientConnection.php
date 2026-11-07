<?php

namespace App\Models;

use App\Enums\ConnectionType;
use App\Enums\TransactionType;
use App\Support\ClientCacheBuster;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientConnection extends Model
{
    use SoftDeletes;

    protected $table = 'clients_connections';

    protected $casts = [
        'pg_fees_recovery' => 'boolean',
        'is_recurring' => 'boolean',
        'status' => 'boolean',
        'type' => ConnectionType::class,
        'transaction_type' => TransactionType::class,
    ];

    protected static function booted(): void
    {
        static::saved(function (ClientConnection $connection): void {
            ClientCacheBuster::forgetClientPgConnection($connection->client?->client_id);

            if ($connection->isDirty('client_id')) {
                ClientCacheBuster::forgetClientPgConnection(
                    Client::whereKey($connection->getOriginal('client_id'))->value('client_id')
                );
            }
        });

        static::deleted(function (ClientConnection $connection): void {
            ClientCacheBuster::forgetClientPgConnection($connection->client?->client_id);
        });
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<PGConnection, $this>
     */
    public function pgConnection(): BelongsTo
    {
        return $this->belongsTo(PGConnection::class);
    }
}
