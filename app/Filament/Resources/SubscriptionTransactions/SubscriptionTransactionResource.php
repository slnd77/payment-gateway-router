<?php

namespace App\Filament\Resources\SubscriptionTransactions;

use App\Filament\Resources\SubscriptionTransactions\Pages\CreateSubscriptionTransaction;
use App\Filament\Resources\SubscriptionTransactions\Pages\EditSubscriptionTransaction;
use App\Filament\Resources\SubscriptionTransactions\Pages\ListSubscriptionTransactions;
use App\Filament\Resources\SubscriptionTransactions\Pages\ViewSubscriptionTransaction;
use App\Filament\Resources\SubscriptionTransactions\Schemas\SubscriptionTransactionForm;
use App\Filament\Resources\SubscriptionTransactions\Schemas\SubscriptionTransactionInfolist;
use App\Filament\Resources\SubscriptionTransactions\Tables\SubscriptionTransactionsTable;
use App\Models\SubscriptionTransaction;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class SubscriptionTransactionResource extends Resource
{
    protected static ?string $model = SubscriptionTransaction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static string|UnitEnum|null $navigationGroup = 'Transactions';

    protected static ?string $navigationLabel = 'Recurring Transactions';

    public static function form(Schema $schema): Schema
    {
        return SubscriptionTransactionForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SubscriptionTransactionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SubscriptionTransactionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubscriptionTransactions::route('/'),
            'create' => CreateSubscriptionTransaction::route('/create'),
            'view' => ViewSubscriptionTransaction::route('/{record}'),
            'edit' => EditSubscriptionTransaction::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['subscription']);
    }
}
