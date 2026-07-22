<?php

namespace App\Livewire;

use App\Models\ClientConnection;
use App\Models\PaymentGatewayConnectionApiLog;
use App\Models\PGConnection;
use App\Models\Transaction;
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

class PaymentGatewayConnectionApiLogsTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public ?PGConnection $pgConnection = null;

    public ?ClientConnection $clientConnection = null;

    public ?Transaction $transaction = null;

    public function table(Table $table): Table
    {
        return $table
            ->query(function (): Builder {
                $query = PaymentGatewayConnectionApiLog::query();

                if ($this->transaction) {
                    $query->where('transaction_id', $this->transaction->id);
                }

                if ($this->pgConnection) {
                    $query->where('pg_connection_id', $this->pgConnection->id);
                }

                if ($this->clientConnection) {
                    $query->where('client_id', $this->clientConnection->client_id)
                        ->where('pg_connection_id', $this->clientConnection->pg_connection_id);
                }

                return $query;
            })
            ->columns([
                TextColumn::make('transaction.transaction_id')
                    ->label('Transaction ID')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('pgConnection.name')
                    ->label('PG Connection')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('request_type')
                    ->label('Request Type')
                    ->badge()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('response_status')
                    ->label('Response Status')
                    ->badge()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('request_data')
                    ->label('Request Data')
                    ->state(fn (PaymentGatewayConnectionApiLog $record): string => $this->encodeForDisplay($record->request_data))
                    ->limit(50)
                    ->wrap()
                    ->action($this->makeJsonModalAction('viewRequestData', 'Request Data', fn (PaymentGatewayConnectionApiLog $record) => $record->request_data)),

                TextColumn::make('response_data')
                    ->label('Response Data')
                    ->state(fn (PaymentGatewayConnectionApiLog $record): string => $this->encodeForDisplay($record->response_data))
                    ->limit(50)
                    ->wrap()
                    ->action($this->makeJsonModalAction('viewResponseData', 'Response Data', fn (PaymentGatewayConnectionApiLog $record) => $record->response_data)),

                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime()
                    ->searchable()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
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
     * @param  Closure(PaymentGatewayConnectionApiLog): (array<string, mixed>|null)  $getData
     */
    protected function makeJsonModalAction(string $name, string $heading, Closure $getData): Action
    {
        return Action::make($name)
            ->modalHeading($heading)
            ->modalContent(function (PaymentGatewayConnectionApiLog $record) use ($getData): HtmlString {
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
        return view('livewire.payment-gateway-connection-api-logs-table');
    }
}
