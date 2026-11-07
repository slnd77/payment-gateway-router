<?php

namespace App\Filament\Widgets;

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use App\Models\User;
use Devhammed\LaravelBrickMoney\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class RecentTransactionsWidget extends TableWidget
{
    protected static ?string $heading = 'Recent Transactions';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getTransactionsQuery())
            ->paginated(false)
            ->columns([
                TextColumn::make('client.name')
                    ->label('Client'),

                TextColumn::make('transaction_id')
                    ->label('Transaction ID')
                    ->searchable(),

                TextColumn::make('amount')
                    ->label('Amount')
                    // The money cast returns [amount, currency]; formatting that state renders each
                    // element ("₹499, ₹499"), so build a single value from the raw columns instead.
                    ->state(fn (Transaction $record): string => (string) Money::ofMinor(
                        $record->getRawOriginal('amount'),
                        $record->getRawOriginal('currency'),
                    )),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (TransactionStatus $state): string => match ($state) {
                        TransactionStatus::SUCCESS => 'success',
                        TransactionStatus::FAILED, TransactionStatus::CANCELLED => 'danger',
                        TransactionStatus::PENDING, TransactionStatus::PROCESSING => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('transaction_date_time')
                    ->label('Date')
                    ->dateTime(),
            ]);
    }

    /**
     * @return Builder<Transaction>
     */
    protected function getTransactionsQuery(): Builder
    {
        $user = auth()->user();

        $query = Transaction::query()
            ->with('client:id,name')
            ->latest('transaction_date_time')
            ->limit(10);

        $this->scopeToPermittedClients($query, $user);

        return $query;
    }

    /**
     * @param  Builder<Transaction>  $query
     */
    protected function scopeToPermittedClients(Builder $query, User $user): void
    {
        if ($user->can('can_view_all_transactions')) {
            return;
        }

        if ($user->can('can_view_client')) {
            $query->whereHas('client.users', fn (Builder $query) => $query->whereKey($user->id));

            return;
        }

        $query->whereRaw('1 = 0');
    }
}
