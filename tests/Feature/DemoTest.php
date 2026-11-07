<?php

use App\Classes\Encryption;
use App\Models\Client;
use App\Models\ClientConnection;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['demo.enabled' => true]);
});

it('returns 404 for the demo pages when demo mode is off', function () {
    config(['demo.enabled' => false]);

    $this->get('/demo')->assertNotFound();
});

it('refuses to reset the database when demo mode is off', function () {
    config(['demo.enabled' => false]);

    $this->artisan('demo:reset')->assertFailed();
});

it('seeds an admin and one client per configured gateway', function () {
    $this->seed(DemoSeeder::class);

    $admin = User::where('email', config('demo.admin.email'))->firstOrFail();
    expect($admin->hasRole('admin'))->toBeTrue();

    // Only the simulator has attributes by default; the others need env credentials.
    expect(Client::pluck('client_id')->all())->toBe(['DEMOSIMULATOR']);
    expect(ClientConnection::where('client_id', Client::first()->id)->pluck('is_recurring')->all())->toBe([false, true]);
});

it('shows the checkout with the seeded gateways and demo login', function () {
    $this->seed(DemoSeeder::class);

    $this->get('/demo')
        ->assertOk()
        ->assertSee('PG Simulator')
        ->assertSee(config('demo.admin.email'));
});

it('sends an encrypted initPayment request and redirects to the gateway url', function () {
    $this->seed(DemoSeeder::class);
    $client = Client::where('client_id', 'DEMOSIMULATOR')->firstOrFail();

    Http::fake([
        route('initPayment').'*' => Http::response(['status' => 'success', 'url' => 'https://gateway.test/checkout/1']),
    ]);

    $this->post('/demo/pay', [
        'gateway' => 'simulator',
        'amount' => 250,
        'name' => 'Demo Customer',
        'email' => 'customer@example.com',
        'mobile' => '9876543210',
    ])->assertRedirect('https://gateway.test/checkout/1');

    Http::assertSent(function (HttpRequest $request) use ($client) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $payload = json_decode(Encryption::decrypt($query['data'], $client->client_secret), true);

        return $query['clientId'] === 'DEMOSIMULATOR'
            && (float) $payload['amount'] === 250.0
            && $payload['customer']['email'] === 'customer@example.com';
    });
});

it('decrypts the result on the return page', function () {
    $this->seed(DemoSeeder::class);
    $client = Client::where('client_id', 'DEMOSIMULATOR')->firstOrFail();

    $data = Encryption::encrypt(['status' => 'success', 'siteReferenceId' => 'demo-abc'], $client->client_secret);

    $this->get('/demo/return/simulator?data='.urlencode($data))
        ->assertOk()
        ->assertSee('success')
        ->assertSee('demo-abc');
});

it('shows the reset notice on the admin login page only in demo mode', function () {
    $this->get('/dashboard/login')->assertOk()->assertSee('All data resets every 3 hours');

    config(['demo.enabled' => false]);

    $this->get('/dashboard/login')->assertOk()->assertDontSee('All data resets every 3 hours');
});
