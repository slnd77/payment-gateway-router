<?php

namespace App\Filament\Resources\SubscriptionTransactions\Schemas;

use App\Enums\TransactionStatus;
use App\Models\SubscriptionTransaction;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SubscriptionTransactionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Overview')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('id'),
                        TextEntry::make('subscription.site_reference_id')
                            ->label('Subscription Ref')
                            ->copyable(),
                        TextEntry::make('site_reference_id')
                            ->label('Charge Reference ID')
                            ->placeholder('-')
                            ->copyable(),
                        TextEntry::make('payment_id')
                            ->label('Payment ID')
                            ->placeholder('-')
                            ->copyable(),
                        TextEntry::make('transaction_id')
                            ->label('Transaction ID')
                            ->placeholder('-')
                            ->copyable(),
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (TransactionStatus $state): string => match ($state) {
                                TransactionStatus::SUCCESS => 'success',
                                TransactionStatus::FAILED => 'danger',
                                TransactionStatus::PENDING, TransactionStatus::PROCESSING => 'warning',
                                TransactionStatus::CANCELLED => 'gray',
                                TransactionStatus::REFUNDED => 'info',
                            }),
                        TextEntry::make('payment_method')
                            ->badge()
                            ->placeholder('-'),
                        TextEntry::make('transaction_date_time')
                            ->label('Transaction Date')
                            ->dateTime()
                            ->placeholder('-'),
                    ]),

                Section::make('Amounts')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('amount')
                            ->state(fn (SubscriptionTransaction $record): string => ! empty($record->amount['amount']) ? $record->amount['amount']->format(true) : '-'),
                        TextEntry::make('currency')
                            ->placeholder('-'),
                        TextEntry::make('pg_fees')
                            ->label('PG Fees')
                            ->state(fn (SubscriptionTransaction $record): string => ! empty($record->pg_fees['pg_fees']) ? $record->pg_fees['pg_fees']->format(true) : '-'),
                        TextEntry::make('pg_tax')
                            ->label('PG Tax')
                            ->state(fn (SubscriptionTransaction $record): string => ! empty($record->pg_tax['pg_tax']) ? $record->pg_tax['pg_tax']->format(true) : '-'),
                    ]),

                Section::make('Raw Payloads')
                    ->columns(1)
                    ->collapsible()
                    ->schema([
                        TextEntry::make('data')
                            ->label('Response Data')
                            ->placeholder('-')
                            ->state(function (SubscriptionTransaction $record): ?string {
                                $json = $record->data ? json_encode($record->data, JSON_PRETTY_PRINT) : null;

                                return $json === false ? null : $json;
                            })
                            ->extraAttributes(['class' => 'font-mono text-xs whitespace-pre-wrap'])
                            ->columnSpanFull(),
                    ]),

                Section::make('Timestamps')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('created_at')
                            ->dateTime(),
                        TextEntry::make('updated_at')
                            ->dateTime(),
                    ]),
            ]);
    }
}
