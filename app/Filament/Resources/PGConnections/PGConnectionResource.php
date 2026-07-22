<?php

namespace App\Filament\Resources\PGConnections;

use App\Filament\Resources\PGConnections\Pages\CreatePGConnection;
use App\Filament\Resources\PGConnections\Pages\EditPGConnection;
use App\Filament\Resources\PGConnections\Pages\ListPGConnections;
use App\Filament\Resources\PGConnections\Pages\ViewPGConnection;
use App\Filament\Resources\PGConnections\Schemas\PGConnectionForm;
use App\Filament\Resources\PGConnections\Schemas\PGConnectionInfolist;
use App\Filament\Resources\PGConnections\Tables\PGConnectionsTable;
use App\Models\PGConnection;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class PGConnectionResource extends Resource
{
    protected static ?string $model = PGConnection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'PG Connections';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return PGConnectionForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PGConnectionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PGConnectionsTable::configure($table);
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
            'index' => ListPGConnections::route('/'),
            'create' => CreatePGConnection::route('/create'),
            'view' => ViewPGConnection::route('/{record}'),
            'edit' => EditPGConnection::route('/{record}/edit'),
        ];
    }
}
