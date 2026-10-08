<?php

namespace App\Filament\Resources\Subscriptions\Pages;

use App\DTO\SubscriptionManageDTO;
use App\Enums\SubscriptionAction;
use App\Enums\SubscriptionStatus;
use App\Filament\Resources\Subscriptions\SubscriptionsResource;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewSubscriptions extends ViewRecord
{
    protected static string $resource = SubscriptionsResource::class;

    protected function getSubscription(): Subscription
    {
        $record = $this->getRecord();

        if (! $record instanceof Subscription) {
            throw new \RuntimeException('Expected a Subscription record.');
        }

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sync')
                ->label('Sync Status')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('warning')
                ->requiresConfirmation()
                ->action(function (SubscriptionService $subscriptionService): void {
                    try {
                        $subscriptionService->syncSubscription($this->getSubscription());
                        $this->getSubscription()->refresh();

                        Notification::make()
                            ->title('Subscription synced')
                            ->body("Current status: {$this->getSubscription()->status->value}")
                            ->success()
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Failed to sync subscription')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Action::make('pause')
                ->label('Pause Subscription')
                ->icon(Heroicon::OutlinedPause)
                ->color('warning')
                ->visible(fn (): bool => $this->getSubscription()->status === SubscriptionStatus::ACTIVE)
                ->requiresConfirmation()
                ->action(function (SubscriptionService $subscriptionService): void {
                    try {
                        $dto = new SubscriptionManageDTO(action: SubscriptionAction::PAUSE);
                        $subscriptionService->manageSubscription(
                            $this->getSubscription()->client_id,
                            $this->getSubscription()->site_reference_id,
                            $dto
                        );
                        $this->getSubscription()->refresh();

                        Notification::make()
                            ->title('Subscription paused')
                            ->success()
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Failed to pause subscription')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Action::make('resume')
                ->label('Resume Subscription')
                ->icon(Heroicon::OutlinedPlay)
                ->color('success')
                ->visible(fn (): bool => $this->getSubscription()->status === SubscriptionStatus::PAUSED)
                ->requiresConfirmation()
                ->action(function (SubscriptionService $subscriptionService): void {
                    try {
                        $dto = new SubscriptionManageDTO(action: SubscriptionAction::RESUME);
                        $subscriptionService->manageSubscription(
                            $this->getSubscription()->client_id,
                            $this->getSubscription()->site_reference_id,
                            $dto
                        );
                        $this->getSubscription()->refresh();

                        Notification::make()
                            ->title('Subscription resumed')
                            ->success()
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Failed to resume subscription')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Action::make('cancel')
                ->label('Cancel Subscription')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->visible(fn (): bool => in_array($this->getSubscription()->status, [SubscriptionStatus::ACTIVE, SubscriptionStatus::PAUSED]))
                ->requiresConfirmation()
                ->action(function (SubscriptionService $subscriptionService): void {
                    try {
                        $dto = new SubscriptionManageDTO(action: SubscriptionAction::CANCEL);
                        $subscriptionService->manageSubscription(
                            $this->getSubscription()->client_id,
                            $this->getSubscription()->site_reference_id,
                            $dto
                        );
                        $this->getSubscription()->refresh();

                        Notification::make()
                            ->title('Subscription cancelled')
                            ->success()
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Failed to cancel subscription')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Action::make('pgApiLogs')
                ->label('PG API Logs')
                ->icon(Heroicon::OutlinedDocumentText)
                ->color('gray')
                ->modalHeading(fn (): string => "PG API Logs - {$this->getSubscription()->site_reference_id}")
                ->modalContent(fn () => view('filament.modals.payment-gateway-connection-api-logs', ['subscription' => $this->getSubscription()]))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalWidth('7xl'),
        ];
    }
}
