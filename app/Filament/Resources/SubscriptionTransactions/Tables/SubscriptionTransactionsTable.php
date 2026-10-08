<?php

namespace App\Filament\Resources\SubscriptionTransactions\Tables;

use App\Enums\TransactionStatus;
use App\Filament\Resources\Subscriptions\SubscriptionsResource;
use App\Filament\Resources\SubscriptionTransactions\SubscriptionTransactionResource;
use App\Models\SubscriptionTransaction;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SubscriptionTransactionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->sortable()
                    ->tooltip('View transaction')
                    ->url(fn (SubscriptionTransaction $record): string => SubscriptionTransactionResource::getUrl('view', ['record' => $record]))
                    ->color('primary')
                    ->openUrlInNewTab(),

                TextColumn::make('subscription.site_reference_id')
                    ->label('Subscription Ref')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->tooltip('View subscription')
                    ->description(fn (SubscriptionTransaction $record): ?string => $record->subscription?->subscription_id)
                    ->url(fn (SubscriptionTransaction $record): ?string => $record->subscription_id ? SubscriptionsResource::getUrl('view', ['record' => $record->subscription_id]) : null)
                    ->color('primary')
                    ->openUrlInNewTab(),

                TextColumn::make('site_reference_id')
                    ->label('Charge Ref')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-')
                    ->copyable()
                    ->tooltip('View transaction')
                    ->url(fn (SubscriptionTransaction $record): string => SubscriptionTransactionResource::getUrl('view', ['record' => $record]))
                    ->color('primary')
                    ->openUrlInNewTab(),

                TextColumn::make('payment_id')
                    ->label('Payment ID')
                    ->searchable()
                    ->placeholder('-')
                    ->copyable()
                    ->tooltip('View transaction')
                    ->url(fn (SubscriptionTransaction $record): string => SubscriptionTransactionResource::getUrl('view', ['record' => $record]))
                    ->color('primary')
                    ->openUrlInNewTab(),

                TextColumn::make('transaction_id')
                    ->label('Transaction ID')
                    ->searchable()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (TransactionStatus $state): string => match ($state) {
                        TransactionStatus::SUCCESS => 'success',
                        TransactionStatus::FAILED => 'danger',
                        TransactionStatus::PENDING, TransactionStatus::PROCESSING => 'warning',
                        TransactionStatus::CANCELLED => 'gray',
                        TransactionStatus::REFUNDED => 'info',
                    })
                    ->searchable()
                    ->sortable(),

                TextColumn::make('amount')
                    ->state(function (SubscriptionTransaction $record): string {
                        try {
                            return ! empty($record->amount['amount']) ? $record->amount['amount']->format(true) : '-';
                        } catch (\Throwable) {
                            return '-';
                        }
                    })
                    ->sortable(),

                TextColumn::make('payment_method')
                    ->badge()
                    ->placeholder('-')
                    ->searchable(),

                TextColumn::make('pg_fees')
                    ->label('PG Fees')
                    ->state(function (SubscriptionTransaction $record): string {
                        try {
                            return ! empty($record->pg_fees['pg_fees']) ? $record->pg_fees['pg_fees']->format(true) : '-';
                        } catch (\Throwable) {
                            return '-';
                        }
                    })
                    ->sortable(),

                TextColumn::make('pg_tax')
                    ->label('PG Tax')
                    ->state(function (SubscriptionTransaction $record): string {
                        try {
                            return ! empty($record->pg_tax['pg_tax']) ? $record->pg_tax['pg_tax']->format(true) : '-';
                        } catch (\Throwable) {
                            return '-';
                        }
                    })
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('transaction_date_time')
                    ->label('Date')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->recordUrl(
                fn (SubscriptionTransaction $record): string => SubscriptionTransactionResource::getUrl('view', ['record' => $record]),
                shouldOpenInNewTab: true,
            )
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('pgApiLogs')
                    ->label('PG API Logs')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->color('gray')
                    ->modalHeading(fn (SubscriptionTransaction $record): string => "PG API Logs - {$record->transaction_id}")
                    ->modalContent(fn (SubscriptionTransaction $record) => view('filament.modals.payment-gateway-connection-api-logs', [
                        'subscription' => $record->subscription,
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalWidth('7xl'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
