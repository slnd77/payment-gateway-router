<?php

namespace App\Console\Commands;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;
use Throwable;

class SyncSubscriptions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscriptions:sync {--id= : Specific subscription ID to sync}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync subscriptions status and charges with the payment gateway';

    /**
     * Execute the console command.
     */
    public function handle(SubscriptionService $subscriptionService): int
    {
        $id = $this->option('id');

        if ($id) {
            $subscription = Subscription::find($id);
            if (! $subscription) {
                $this->error("Subscription with ID {$id} not found.");

                return self::FAILURE;
            }

            $this->info("Syncing subscription {$id} ({$subscription->site_reference_id})...");
            try {
                $subscriptionService->syncSubscription($subscription);
                $this->info("Successfully synced subscription {$id}. Current status: {$subscription->fresh()->status->value}");

                return self::SUCCESS;
            } catch (Throwable $e) {
                $this->error("Failed to sync subscription {$id}: {$e->getMessage()}");

                return self::FAILURE;
            }
        }

        $terminalStatuses = [
            SubscriptionStatus::CANCELLED->value,
            SubscriptionStatus::COMPLETED->value,
            SubscriptionStatus::EXPIRED->value,
            SubscriptionStatus::FAILED->value,
        ];

        $query = Subscription::whereNotIn('status', $terminalStatuses);
        $total = $query->count();

        $this->info("Found {$total} non-terminal subscription(s) to sync.");

        $successCount = 0;
        $failCount = 0;

        $query->chunk(50, function ($subscriptions) use ($subscriptionService, &$successCount, &$failCount) {
            foreach ($subscriptions as $subscription) {
                try {
                    $subscriptionService->syncSubscription($subscription);
                    $successCount++;
                } catch (Throwable $e) {
                    $failCount++;
                    $this->warn("Error syncing subscription {$subscription->id}: {$e->getMessage()}");
                }
            }
        });

        $this->info("Sync completed: {$successCount} succeeded, {$failCount} failed.");

        return self::SUCCESS;
    }
}
