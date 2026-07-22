<?php

namespace App\Repositories;

use App\Models\ClientConnection;
use Illuminate\Support\Facades\Cache;

class ClientConnectionRepository
{
    /**
     * @return array<string, mixed>
     */
    public function getClientPGConnection(int|string $clientId, int|bool $isRecurring): array
    {
        return Cache::remember("clientPGConnection.{$clientId}.{$isRecurring}", 3600, function () use ($clientId, $isRecurring) {
            return ClientConnection::join('clients', 'clients.id', '=', 'clients_connections.client_id')
                ->with('pgConnection')->where('clients.client_id', '=', $clientId)
                ->where('clients_connections.is_recurring', '=', $isRecurring)
                ->first()?->toArray() ?? [];
        });
    }
}
