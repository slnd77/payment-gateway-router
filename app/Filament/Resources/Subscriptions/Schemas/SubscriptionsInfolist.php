<?php

namespace App\Filament\Resources\Subscriptions\Schemas;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SubscriptionsInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Overview')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('id'),
                        TextEntry::make('site_reference_id')
                            ->label('Site Ref ID')
                            ->copyable(),
                        TextEntry::make('subscription_id')
                            ->label('Subscription ID')
                            ->placeholder('-')
                            ->copyable(),
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (SubscriptionStatus $state): string => match ($state) {
                                SubscriptionStatus::ACTIVE => 'success',
                                SubscriptionStatus::PAUSED, SubscriptionStatus::BANK_APPROVAL_PENDING => 'warning',
                                SubscriptionStatus::CANCELLED, SubscriptionStatus::FAILED, SubscriptionStatus::EXPIRED => 'danger',
                                SubscriptionStatus::CREATED, SubscriptionStatus::INITIALIZED, SubscriptionStatus::AUTHENTICATED => 'info',
                                SubscriptionStatus::COMPLETED => 'gray',
                            }),
                        TextEntry::make('transactions_count')
                            ->label('Total Transactions')
                            ->state(fn (Subscription $record): int => $record->transactions()->count())
                            ->badge()
                            ->color(fn (int $state): string => $state > 0 ? 'success' : 'gray'),
                        TextEntry::make('subscription_type')
                            ->label('Subscription Type')
                            ->badge(),
                        TextEntry::make('payment_method')
                            ->label('Payment Method')
                            ->badge()
                            ->placeholder('-'),
                        TextEntry::make('pg_reference_id')
                            ->label('PG Reference ID')
                            ->placeholder('-')
                            ->copyable(),
                        TextEntry::make('authorization_reference')
                            ->label('Auth Reference')
                            ->placeholder('-')
                            ->copyable(),
                    ]),

                Section::make('Plan & Schedule')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('plan_id')
                            ->label('Plan ID')
                            ->placeholder('-'),
                        TextEntry::make('plan_name')
                            ->label('Plan Name')
                            ->placeholder('-'),
                        TextEntry::make('period')
                            ->label('Period')
                            ->placeholder('-'),
                        TextEntry::make('interval')
                            ->label('Interval')
                            ->placeholder('-'),
                        TextEntry::make('max_cycles')
                            ->label('Max Cycles')
                            ->placeholder('-'),
                        TextEntry::make('start_date_time')
                            ->label('Start Date')
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('end_date_time')
                            ->label('End Date')
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('next_charge_date_time')
                            ->label('Next Charge Date')
                            ->dateTime()
                            ->placeholder('-'),
                    ]),

                Section::make('Client & Customer')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('client.name')
                            ->label('Client'),
                        TextEntry::make('pgConnection.name')
                            ->label('PG Connection')
                            ->placeholder('-'),
                        TextEntry::make('customer.name')
                            ->label('Customer')
                            ->placeholder('-'),
                        TextEntry::make('customer.email')
                            ->label('Customer Email')
                            ->placeholder('-'),
                        TextEntry::make('customer.mobile')
                            ->label('Customer Mobile')
                            ->placeholder('-'),
                    ]),

                Section::make('Amounts')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('amount')
                            ->state(fn (Subscription $record): string => ! empty($record->amount['amount']) ? $record->amount['amount']->format(true) : '-'),
                        TextEntry::make('max_amount')
                            ->label('Max Amount')
                            ->state(fn (Subscription $record): string => ! empty($record->max_amount['max_amount']) ? $record->max_amount['max_amount']->format(true) : '-'),
                        TextEntry::make('auth_amount')
                            ->label('Auth Amount')
                            ->state(fn (Subscription $record): string => ! empty($record->auth_amount['auth_amount']) ? $record->auth_amount['auth_amount']->format(true) : '-'),
                        TextEntry::make('currency')
                            ->placeholder('-'),
                    ]),

                Section::make('Raw Payloads')
                    ->columns(1)
                    ->collapsible()
                    ->schema([
                        TextEntry::make('request_data')
                            ->label('Request Data')
                            ->placeholder('-')
                            ->state(function (Subscription $record): ?string {
                                $json = $record->request_data ? json_encode($record->request_data, JSON_PRETTY_PRINT) : null;

                                return $json === false ? null : $json;
                            })
                            ->extraAttributes(['class' => 'font-mono text-xs whitespace-pre-wrap'])
                            ->columnSpanFull(),
                        TextEntry::make('response_data')
                            ->label('Response Data')
                            ->placeholder('-')
                            ->state(function (Subscription $record): ?string {
                                $json = $record->response_data ? json_encode($record->response_data, JSON_PRETTY_PRINT) : null;

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
