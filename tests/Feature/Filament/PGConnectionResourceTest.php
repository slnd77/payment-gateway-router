<?php

use App\Enums\ConnectionType;
use App\Filament\Resources\PGConnections\Pages\CreatePGConnection;
use App\Filament\Resources\PGConnections\Pages\EditPGConnection;
use App\Filament\Resources\PGConnections\PGConnectionResource;
use App\Models\PGConnection;
use App\Models\SupportedPaymentGateway;
use Livewire\Livewire;

require_once __DIR__.'/FilamentTestHelpers.php';

it('visits the PG connection list, create, view, and edit pages', function () {
    actingAsFilamentAdmin();
    $pgConnection = makeFilamentTestPgConnection();

    $this->get(PGConnectionResource::getUrl('index'))->assertOk();
    $this->get(PGConnectionResource::getUrl('create'))->assertOk();
    $this->get(PGConnectionResource::getUrl('view', ['record' => $pgConnection]))->assertOk();
    $this->get(PGConnectionResource::getUrl('edit', ['record' => $pgConnection]))->assertOk();
});

it('renders the radio-typed attribute field for gateways whose schema uses one (PAYPAL\'s "mode")', function () {
    actingAsFilamentAdmin();
    $paypalConnection = makeFilamentTestPgConnection('PAYPAL');

    $this->get(PGConnectionResource::getUrl('edit', ['record' => $paypalConnection]))->assertOk();
});

it('renders the url-typed attribute field for gateways whose schema declares one', function () {
    actingAsFilamentAdmin();

    SupportedPaymentGateway::create([
        'name' => 'TEST_URL_GATEWAY',
        'pg_class' => 'TEST_URL_GATEWAY',
        'attributes' => [
            'required' => [
                'webhook_url' => 'url',
            ],
        ],
        'status' => true,
    ]);

    $connection = makeFilamentTestPgConnection('TEST_URL_GATEWAY');

    $this->get(PGConnectionResource::getUrl('edit', ['record' => $connection]))->assertOk();
});

/*
|--------------------------------------------------------------------------
| Form-submission tests
|--------------------------------------------------------------------------
|
| Everything else in this directory only visits pages. These go further
| and actually submit the create/edit forms, because
| ValidatesGatewayAttributes::validateGatewayAttributes() only runs on a
| real create/save call - no page visit reaches it.
*/

it('creates a PG connection when its attributes satisfy the gateway\'s required schema', function () {
    actingAsFilamentAdmin();

    Livewire::test(CreatePGConnection::class)
        ->fillForm([
            'name' => 'PG Simulator Connection',
            'pg_class' => 'PGSimulator',
            'type' => ConnectionType::TEST->value,
            'status' => true,
            'attributes' => [
                'supports_refunds' => true,
                'fees_included_in_amount' => false,
                'fees_rate' => '2.5',
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(PGConnection::where('name', 'PG Simulator Connection')->exists())->toBeTrue();
});

it('saves an edited PG connection when its attributes satisfy the gateway\'s required schema', function () {
    actingAsFilamentAdmin();
    $connection = makeFilamentTestPgConnection('PGSimulator');

    Livewire::test(EditPGConnection::class, ['record' => $connection->getRouteKey()])
        ->fillForm([
            'attributes' => [
                'supports_refunds' => true,
                'fees_included_in_amount' => false,
                'fees_rate' => '3.5',
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();
});

it('halts and notifies when PG connection attributes are missing required fields', function () {
    // Every value ValidatesGatewayAttributes rejects (non-numeric fees_rate,
    // a non-boolean toggle, an empty required string, ...) is a value
    // Filament's own field-level rules (numeric()/required()) already
    // reject first, so a real Livewire form submission never reaches this
    // trait's Halt branch to exercise it. Calling it directly on the real
    // trait (not mocked) is the only way to reach that code path.
    actingAsFilamentAdmin();

    $page = new class
    {
        use \App\Filament\Resources\PGConnections\Concerns\ValidatesGatewayAttributes;

        /** @param  array<string, mixed>  $data */
        public function run(array $data): void
        {
            $this->validateGatewayAttributes($data);
        }
    };

    expect(fn () => $page->run(['pg_class' => 'PGSimulator', 'attributes' => []]))
        ->toThrow(\Filament\Support\Exceptions\Halt::class);
});
