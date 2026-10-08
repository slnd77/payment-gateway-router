<?php

namespace App\Filament\Resources\ClientConnections\Concerns;

use App\Enums\TransactionType;
use App\Models\ClientConnection;
use App\Models\PGConnection;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;

trait ValidatesClientConnection
{
    /**
     * Guard the record from being persisted with an environment that
     * doesn't match its gateway connection, an invalid transaction type,
     * or duplicate active connections for the same recurring type.
     *
     * @param  array<string, mixed>  $data
     */
    protected function validateClientConnection(array $data, ?int $ignoreRecordId = null): void
    {
        $pgConnectionId = $data['pg_connection_id'] ?? null;
        $pgConnection = (is_int($pgConnectionId) || is_string($pgConnectionId))
            ? PGConnection::find($pgConnectionId)
            : null;

        if ($pgConnection && $pgConnection->type->value !== $data['type']) {
            $this->failClientConnectionValidation(
                "The connection's environment ({$data['type']}) must match the payment gateway's environment ({$pgConnection->type->value})."
            );
        }

        if (TransactionType::tryFrom($data['transaction_type'] ?? '') === null) {
            $this->failClientConnectionValidation(
                'The selected transaction type is invalid.'
            );
        }

        if (! ($data['status'] ?? false)) {
            return;
        }

        $isRecurring = (bool) ($data['is_recurring'] ?? false);
        $type = $isRecurring ? 'recurring' : 'one-time';

        $activeConnectionsCount = ClientConnection::query()
            ->where('client_id', $data['client_id'] ?? null)
            ->where('status', true)
            ->where('is_recurring', $isRecurring)
            ->when($ignoreRecordId, fn ($query) => $query->whereKeyNot($ignoreRecordId))
            ->count();

        $maxActiveConnections = 1;

        if (($activeConnectionsCount + 1) > $maxActiveConnections) {
            $this->failClientConnectionValidation(
                "This client already has an active {$type} connection. Active connections must be unique by recurring (only one active {$type} connection is allowed per client)."
            );
        }
    }

    protected function failClientConnectionValidation(string $message): void
    {
        Notification::make()
            ->title('Invalid client connection')
            ->body($message)
            ->danger()
            ->send();

        throw new Halt;
    }
}
