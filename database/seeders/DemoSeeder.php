<?php

namespace Database\Seeders;

use App\Enums\ConnectionType;
use App\Enums\TransactionType;
use App\Models\Client;
use App\Models\ClientConnection;
use App\Models\PGConnection;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds the public demo: one admin login, one TEST PG connection per
 * configured gateway, and one demo client per gateway (a client can only have
 * one active one-time connection, so each gateway gets its own client).
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            SupportedPGSeeder::class,
        ]);

        $admin = User::create([
            'name' => config('demo.admin.name'),
            'email' => config('demo.admin.email'),
            'password' => config('demo.admin.password'),
        ]);
        $admin->forceFill(['email_verified_at' => now()])->save();
        $admin->assignRole('admin');

        foreach (config('demo.gateways') as $key => $gateway) {
            if ($gateway['attributes'] === []) {
                continue;
            }

            $pgConnection = PGConnection::create([
                'name' => $gateway['label'],
                'pg_class' => $gateway['pg_class'],
                'attributes' => $gateway['attributes'],
                'status' => true,
                'type' => ConnectionType::TEST,
            ]);

            $client = Client::create([
                'uuid' => (string) Str::ulid(),
                'name' => 'Demo Store ('.$gateway['label'].')',
                'client_id' => 'DEMO'.Str::upper($key),
                'client_secret' => Str::random(40),
                'website' => url('/demo'),
                'redirect_uri' => route('demo.return', ['gateway' => $key]),
                'redirect_uri_separator' => '?',
                'status' => true,
                'user_id' => $admin->id,
            ]);
            $client->users()->attach($admin->id);

            ClientConnection::create([
                'client_id' => $client->id,
                'pg_connection_id' => $pgConnection->id,
                'is_recurring' => false,
                'type' => ConnectionType::TEST,
                'transaction_type' => TransactionType::SALE,
                'status' => true,
            ]);

            if ($gateway['recurring']) {
                ClientConnection::create([
                    'client_id' => $client->id,
                    'pg_connection_id' => $pgConnection->id,
                    'is_recurring' => true,
                    'type' => ConnectionType::TEST,
                    'transaction_type' => TransactionType::SALE,
                    'status' => true,
                ]);
            }
        }
    }
}
