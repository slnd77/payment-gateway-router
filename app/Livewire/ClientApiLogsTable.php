<?php

namespace App\Livewire;

use App\Enums\ClientApiLogResult;
use App\Models\Client;
use App\Models\ClientApiLog;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Livewire\Component;

class ClientApiLogsTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public Client $client;

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => ClientApiLog::query()->where('client_id', $this->client->id))
            ->columns([
                TextColumn::make('event')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('result')
                    ->badge()
                    ->color(fn (ClientApiLogResult $state): string => match ($state) {
                        ClientApiLogResult::SUCCESS => 'success',
                        ClientApiLogResult::ERROR => 'danger',
                    })
                    ->searchable()
                    ->sortable(),

                TextColumn::make('source_ip')
                    ->label('Source IP')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('request_data')
                    ->label('Request Data')
                    ->state(fn (ClientApiLog $record): string => $this->encodeForDisplay($record->request_data))
                    ->limit(50)
                    ->wrap()
                    ->action($this->makeJsonModalAction('viewRequestData', 'Request Data', fn (ClientApiLog $record) => $record->request_data)),

                TextColumn::make('response_data')
                    ->label('Response Data')
                    ->state(fn (ClientApiLog $record): string => $this->encodeForDisplay($record->response_data))
                    ->limit(50)
                    ->wrap()
                    ->action($this->makeJsonModalAction('viewResponseData', 'Response Data', fn (ClientApiLog $record) => $record->response_data)),

                TextColumn::make('datetime')
                    ->label('Datetime')
                    ->dateTime()
                    ->searchable()
                    ->sortable(),
            ])
            ->defaultSort('datetime', 'desc');
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    protected function encodeForDisplay(?array $data): string
    {
        if (! $data) {
            return '';
        }

        $json = json_encode($data);

        return $json === false ? '' : $json;
    }

    /**
     * @param  Closure(ClientApiLog): (array<string, mixed>|null)  $getData
     */
    protected function makeJsonModalAction(string $name, string $heading, Closure $getData): Action
    {
        return Action::make($name)
            ->modalHeading($heading)
            ->modalContent(function (ClientApiLog $record) use ($getData): HtmlString {
                $data = $getData($record);
                $json = $data ? json_encode($data, JSON_PRETTY_PRINT) : false;

                return new HtmlString(
                    '<pre class="fi-ta-text w-full max-w-full overflow-x-auto whitespace-pre-wrap break-all text-xs">'
                    .e($json === false ? 'No data' : $json)
                    .'</pre>'
                );
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    public function render(): View
    {
        return view('livewire.client-api-logs-table');
    }
}
