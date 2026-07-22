<?php

namespace App\Filament\Resources\PGConnections\Concerns;

use App\Filament\Resources\PGConnections\Schemas\PGConnectionAttributeSchema;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;

trait ValidatesGatewayAttributes
{
    /**
     * Guard the record from being persisted with attributes that don't
     * satisfy the required schema for the selected payment gateway.
     *
     * @param  array<string, mixed>  $data
     */
    protected function validateGatewayAttributes(array $data): void
    {
        $errors = PGConnectionAttributeSchema::validate(
            $data['pg_class'] ?? null,
            $data['attributes'] ?? [],
        );

        if ($errors === []) {
            return;
        }

        Notification::make()
            ->title('Invalid gateway attributes')
            ->body(implode(' ', $errors))
            ->danger()
            ->send();

        throw new Halt;
    }
}
