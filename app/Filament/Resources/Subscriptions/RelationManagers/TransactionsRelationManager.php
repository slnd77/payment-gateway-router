<?php

namespace App\Filament\Resources\Subscriptions\RelationManagers;

use App\Filament\Resources\SubscriptionTransactions\SubscriptionTransactionResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class TransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transactions';

    protected static ?string $relatedResource = SubscriptionTransactionResource::class;

    protected static ?string $title = 'Subscription Transactions';

    protected static string|\BackedEnum|null $icon = Heroicon::OutlinedArrowPath;

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->transactions()->count();

        return (string) $count;
    }

    public function table(Table $table): Table
    {
        return SubscriptionTransactionResource::table($table)
            ->heading('Subscription Transactions')
            ->description('Billing transactions and recurring charge attempts for this subscription.')
            ->emptyStateHeading('No transactions yet')
            ->emptyStateDescription('No billing transactions or recurring charges have been recorded for this subscription yet.')
            ->emptyStateIcon(Heroicon::OutlinedArrowPath);
    }
}
