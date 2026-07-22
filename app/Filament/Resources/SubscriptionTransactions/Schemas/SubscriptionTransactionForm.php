<?php

namespace App\Filament\Resources\SubscriptionTransactions\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class SubscriptionTransactionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('subscription_id')
                    ->relationship('subscription', 'id')
                    ->required(),
                TextInput::make('transaction_id')
                    ->required(),
                TextInput::make('amount')
                    ->required()
                    ->numeric(),
                TextInput::make('currency')
                    ->required()
                    ->default('INR'),
                TextInput::make('status')
                    ->required(),
                TextInput::make('payment_method')
                    ->required(),
                TextInput::make('pg_fees')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('pg_tax')
                    ->required()
                    ->numeric()
                    ->default(0),
                Textarea::make('data')
                    ->default(null)
                    ->columnSpanFull(),
                DateTimePicker::make('transaction_date_time')
                    ->required(),
            ]);
    }
}
