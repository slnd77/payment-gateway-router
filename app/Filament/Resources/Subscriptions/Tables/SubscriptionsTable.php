<?php

namespace App\Filament\Resources\Subscriptions\Tables;

use App\DTO\SubscriptionManageDTO;
use App\Enums\SubscriptionAction;
use App\Enums\SubscriptionStatus;
use App\Enums\SubscriptionType;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class SubscriptionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->sortable(),

                TextColumn::make('client.name')
                    ->label('Client')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('site_reference_id')
                    ->label('Site Ref ID')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('subscription_id')
                    ->label('Subscription ID')
                    ->placeholder('-')
                    ->copyable()
                    ->searchable(),

                TextColumn::make('subscription_type')
                    ->label('Type')
                    ->badge()
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (SubscriptionStatus $state): string => match ($state) {
                        SubscriptionStatus::ACTIVE => 'success',
                        SubscriptionStatus::PAUSED, SubscriptionStatus::BANK_APPROVAL_PENDING => 'warning',
                        SubscriptionStatus::CANCELLED, SubscriptionStatus::FAILED, SubscriptionStatus::EXPIRED => 'danger',
                        SubscriptionStatus::CREATED, SubscriptionStatus::INITIALIZED, SubscriptionStatus::AUTHENTICATED => 'info',
                        SubscriptionStatus::COMPLETED => 'gray',
                    })
                    ->searchable()
                    ->sortable(),

                TextColumn::make('amount')
                    ->state(fn (Subscription $record): string => ! empty($record->amount['amount']) ? $record->amount['amount']->format(true) : '-')
                    ->sortable(),

                TextColumn::make('max_amount')
                    ->label('Max Amount')
                    ->state(fn (Subscription $record): string => ! empty($record->max_amount['max_amount']) ? $record->max_amount['max_amount']->format(true) : '-')
                    ->sortable(),

                TextColumn::make('period')
                    ->label('Period')
                    ->placeholder('-'),

                TextColumn::make('created_at')
                    ->label('Date Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(SubscriptionStatus::class),
                SelectFilter::make('subscription_type')
                    ->options(SubscriptionType::class),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('sync')
                    ->label('Sync')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function (Subscription $record, SubscriptionService $subscriptionService): void {
                        try {
                            $subscriptionService->syncSubscription($record);
                            $record->refresh();
                            Notification::make()
                                ->title('Subscription synced')
                                ->body("Current status: {$record->status->value}")
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Sync failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('pause')
                    ->label('Pause')
                    ->icon(Heroicon::OutlinedPause)
                    ->color('warning')
                    ->visible(fn (Subscription $record): bool => $record->status === SubscriptionStatus::ACTIVE)
                    ->requiresConfirmation()
                    ->action(function (Subscription $record, SubscriptionService $subscriptionService): void {
                        try {
                            $dto = new SubscriptionManageDTO(action: SubscriptionAction::PAUSE);
                            $subscriptionService->manageSubscription($record->client_id, $record->site_reference_id, $dto);
                            $record->refresh();
                            Notification::make()
                                ->title('Subscription paused')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Pause failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('resume')
                    ->label('Resume')
                    ->icon(Heroicon::OutlinedPlay)
                    ->color('success')
                    ->visible(fn (Subscription $record): bool => $record->status === SubscriptionStatus::PAUSED)
                    ->requiresConfirmation()
                    ->action(function (Subscription $record, SubscriptionService $subscriptionService): void {
                        try {
                            $dto = new SubscriptionManageDTO(action: SubscriptionAction::RESUME);
                            $subscriptionService->manageSubscription($record->client_id, $record->site_reference_id, $dto);
                            $record->refresh();
                            Notification::make()
                                ->title('Subscription resumed')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Resume failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('cancel')
                    ->label('Cancel')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->visible(fn (Subscription $record): bool => in_array($record->status, [SubscriptionStatus::ACTIVE, SubscriptionStatus::PAUSED]))
                    ->requiresConfirmation()
                    ->action(function (Subscription $record, SubscriptionService $subscriptionService): void {
                        try {
                            $dto = new SubscriptionManageDTO(action: SubscriptionAction::CANCEL);
                            $subscriptionService->manageSubscription($record->client_id, $record->site_reference_id, $dto);
                            $record->refresh();
                            Notification::make()
                                ->title('Subscription cancelled')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Cancel failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('pgApiLogs')
                    ->label('PG API Logs')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->color('gray')
                    ->modalHeading(fn (Subscription $record): string => "PG API Logs - {$record->site_reference_id}")
                    ->modalContent(fn (Subscription $record) => view('filament.modals.payment-gateway-connection-api-logs', ['subscription' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalWidth('7xl'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
