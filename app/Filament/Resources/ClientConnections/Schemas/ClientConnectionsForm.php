<?php

namespace App\Filament\Resources\ClientConnections\Schemas;

use App\Enums\ConnectionType;
use App\Enums\TransactionType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class ClientConnectionsForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Connection')
                    ->description('Link a client to a payment gateway connection.')
                    ->icon(Heroicon::OutlinedLink)
                    ->columns(2)
                    ->components([
                        Select::make('client_id')
                            ->label('Client')
                            ->relationship('client', 'name')
                            ->prefixIcon(Heroicon::OutlinedBuildingStorefront)
                            ->searchable()
                            ->preload()
                            ->required(),

                        Select::make('transaction_type')
                            ->label('Transaction Type')
                            ->options(TransactionType::labels())
                            ->default(TransactionType::SALE->value)
                            ->prefixIcon(Heroicon::OutlinedShare)
                            ->searchable()
                            ->preload()
                            ->required(),

                        Select::make('pg_connection_id')
                            ->label('Payment Gateway Connection')
                            ->relationship('pgConnection', 'name')
                            ->prefixIcon(Heroicon::OutlinedCreditCard)
                            ->searchable()
                            ->preload()
                            ->required(),
                    ]),

                Section::make('Settings')
                    ->description('How this connection behaves.')
                    ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                    ->columns(4)
                    ->components([
                        Select::make('type')
                            ->label('Environment')
                            ->prefixIcon(Heroicon::OutlinedAdjustmentsHorizontal)
                            ->options(ConnectionType::labels())
                            ->default(ConnectionType::TEST->value)
                            ->helperText('Must match the selected gateway connection\'s environment.')
                            ->columnSpan(4)
                            ->required(),

                        Toggle::make('status')
                            ->label('Active')
                            ->onIcon(Heroicon::OutlinedCheckCircle)
                            ->offIcon(Heroicon::OutlinedXCircle)
                            ->onColor('success')
                            ->offColor('danger')
                            ->helperText('Active connections must be unique by recurring (max 1 one-time and 1 recurring).')
                            ->columnSpan(1)
                            ->default(true),

                        Toggle::make('is_recurring')
                            ->label('Is Recurring')
                            ->helperText('Enable for recurring/subscription payments, or disable for one-time payments.')
                            ->columnSpan(1)
                            ->default(false),

                        Toggle::make('pg_fees_recovery')
                            ->label('Recover Gateway Fees from Client')
                            ->helperText('Passes gateway processing fees on to the client.')
                            ->columnSpan(2)
                            ->default(false),

                        Toggle::make('self_redirect')
                            ->label('Self Redirect')
                            ->helperText('If enabled, the app redirects the client to the bank url. If disabled, the app returns the bank url in a JSON response.')
                            ->columnSpan(2)
                            ->default(true),
                    ]),
            ]);
    }
}
