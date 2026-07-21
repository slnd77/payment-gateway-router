# Payment Gateway Router

[![tests](https://github.com/amol-beast/payment-gateway-router/actions/workflows/tests.yml/badge.svg)](https://github.com/amol-beast/payment-gateway-router/actions/workflows/tests.yml)
[![linter](https://github.com/amol-beast/payment-gateway-router/actions/workflows/lint.yml/badge.svg)](https://github.com/amol-beast/payment-gateway-router/actions/workflows/lint.yml)
![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)

A single, self-hosted API that sits in front of multiple payment gateways so your applications only ever have to integrate once. Clients call one encrypted API, and the router picks the right payment gateway, normalizes the response, and reports back a consistent payload — regardless of which processor actually handled the money.

## What is this?

Payment Gateway Router is a Laravel application that acts as a **merchant-side aggregator/router** between your own products (websites, mobile apps, other backends) and one or more third-party payment gateways. Each of your client applications is registered once with its own encrypted credentials and routing rules; the router then decides, per transaction, which payment gateway connection to use (e.g. one-time payments vs. recurring subscriptions), talks to that gateway's API, and hands back a normalized transaction result.

It ships with a [Filament](https://filamentphp.com) admin panel for managing clients, gateway connections, and transactions, and a small encrypted REST API that client applications integrate against.

### Use cases

- **Multi-gateway checkout** — run several payment gateways behind one API and switch/failover between them per client, without every downstream app needing its own gateway-specific integration.
- **Marketplace / platform billing** — onboard many client applications (tenants) with their own gateway credentials and payment routing rules (one-time vs. recurring), managed centrally.
- **Gateway migration** — swap or A/B test payment gateways for a client without changing anything in the client's own codebase.
- **Centralized reconciliation** — every gateway API call and every transaction is logged in one place for auditing, support, and reconciliation, regardless of which gateway processed it.

### Supported payment gateways

| Gateway | Class | Checkout style |
|---|---|---|
| Razorpay | `RAZORPAY` | Hosted embedded checkout |
| Cashfree | `CASHFREE` | Hosted embedded checkout (Cashfree JS SDK) |
| PayPal | `PAYPAL` | Redirect to PayPal's hosted checkout |
| ICICI eazypay | `ICICI` | Hosted redirect |
| PG Simulator | `PGSimulator` | Local simulator for dev/QA (no live gateway calls) |

Each gateway is implemented against `App\Contracts\PaymentGatewayInterface`, so adding a new one means implementing that interface and wiring it into `PaymentGatewayFactory` — no changes needed anywhere else in the request flow.

## Features

- Encrypted, per-client API (`AES-256-GCM`) for initiating payments, checking status, and retrieving transaction history
- Per-client routing between a one-time-payment gateway connection and a recurring/subscription gateway connection
- Full request/response logging for every gateway API call (`payment_gateway_connection_api_logs`), independent of the client-facing API logs (`client_api_logs`)
- Role-based Filament admin panel for managing clients, gateway connections, client↔gateway mappings, and transactions
- Refunds, transaction status checks, and subscription tracking where the underlying gateway supports them
- Optional transaction email notifications

## Tech stack

- **PHP** 8.3+ / **Laravel** 13
- **Filament** 4 (admin panel)
- **Spatie Laravel Permission** (roles & permissions)
- **Spatie Laravel Data**, **Devhammed Laravel Brick Money** (money-safe DTOs)
- **Pest 4** (testing), **Larastan / PHPStan**, **Laravel Pint** (static analysis & style)
- Gateway SDKs: `razorpay/razorpay`, `cashfree/cashfree-pg-sdk-php`, `paypal/paypal-server-sdk`, `stripe/stripe-php`

## Getting started

### Requirements

- PHP ^8.3 with the extensions Laravel requires (`ext-curl`, `ext-json`, `ext-mbstring`, etc.)
- Composer 2
- Node.js 22+ and npm (asset build only — this app has no SPA frontend)
- MySQL/MariaDB or PostgreSQL for a real deployment (SQLite works for local dev)

### Local setup

```bash
git clone <this-repo>
cd payment_gateway_router

composer setup
```

`composer setup` (see `composer.json`) will install PHP dependencies, copy `.env.example` to `.env`, generate an application key, run migrations, install npm dependencies, and build assets. Equivalent manual steps:

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install && npm run build
```

Then seed roles/permissions and the list of supported gateway definitions:

```bash
php artisan db:seed --class="Database\Seeders\RolesAndPermissionsSeeder"
php artisan db:seed --class="Database\Seeders\SupportedPGSeeder"
```

Create an admin user and assign a role via Filament/tinker, then start the app:

```bash
composer dev
```

This runs `php artisan serve` and `php artisan queue:listen` together. The admin panel is available at `/dashboard`.

### Configuring a payment gateway

1. In the admin panel (or via `PGConnection`), create a **PG Connection** for the gateway you want to use, filling in the attributes defined for that gateway in `SupportedPGSeeder` (API keys/secrets, fee settings, refund support, etc.), and mark it `TEST` or `PRODUCTION`.
2. Create a **Client** for the application that will call the API — this generates a `client_id` and `client_secret` used to encrypt/decrypt API payloads.
3. Create a **Client Connection** linking that client to a PG Connection, choosing whether it's the one-time-payment route or the recurring/subscription route.
4. The client application can now call `GET /api/v1/initPayment` with its `clientId` and an encrypted `data` payload (see `App\Classes\Encryption`) to start a transaction.

## API overview

All endpoints live under `/api/v1`.

| Endpoint | Purpose |
|---|---|
| `GET /api/v1/initPayment` | Start a payment for a client (encrypted request/response) |
| `GET /api/v1/transaction/{reference_id}` | Fetch a single transaction by the client's own reference id |
| `GET /api/v1/transactions` | Paginated transaction list for a client |

`initPayment` is authenticated by encrypting the payload with the client's `client_secret` (`AES-256-GCM`, see `App\Classes\Encryption` and the `HandleApiClientEncryptedRequest` middleware). Everything else runs behind `HandleApiRequest`, which expects a simple `X-TOKEN: {clientId}:{clientSecret}` header instead.

### `initPayment`: encrypting the request

Build the payload, encrypt it with your `client_secret`, and send `clientId` + the encrypted `data` as query params to `GET /api/v1/initPayment`. The router replies with a redirect straight to the gateway's checkout — there's nothing to decrypt on this leg.

```php
function encryptPayload(mixed $data, string $secretKey): string
{
    $cipher = 'aes-256-gcm';
    $iv = random_bytes(openssl_cipher_iv_length($cipher));
    $tag = '';

    $ciphertext = openssl_encrypt(
        json_encode($data),
        $cipher,
        $secretKey,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    // IV + auth tag + ciphertext, base64-encoded, is what the router expects.
    return base64_encode($iv.$tag.$ciphertext);
}

$clientId = 'your-client-id';       // Client::client_id from the admin panel
$secretKey = 'your-client-secret';  // Client::client_secret from the admin panel

$payload = [
    'reference_id' => 'order-1234',              // must be unique per client
    'clientId' => $clientId,
    'currency' => 'INR',
    'amount' => 500,
    'transactionType' => 'sale',                  // sale | donation
    'paymentType' => 'one_time_payment',          // one_time_payment | subscription
    'customer' => [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'mobile' => '9876543210',
    ],
];

$url = 'https://your-router-domain.tld/api/v1/initPayment?'.http_build_query([
    'clientId' => $clientId,
    'data' => encryptPayload($payload, $secretKey),
]);

header('Location: '.$url); // send the customer here to start the payment
```

### `initPayment`: decrypting the final result

Once the gateway finishes, the router redirects the customer back to **your** `redirect_uri` (configured on the `Client`) with an encrypted `data` query param carrying the final transaction result (the same shape as `PaymentResponseDTO`). Decrypt it the same way the request was encrypted:

```php
function decryptPayload(string $encrypted, string $secretKey): string
{
    $cipher = 'aes-256-gcm';
    $ivLength = openssl_cipher_iv_length($cipher);
    $tagLength = 16; // AES-GCM authentication tag is always 16 bytes

    $raw = base64_decode($encrypted);
    $iv = substr($raw, 0, $ivLength);
    $tag = substr($raw, $ivLength, $tagLength);
    $ciphertext = substr($raw, $ivLength + $tagLength);

    return openssl_decrypt($ciphertext, $cipher, $secretKey, OPENSSL_RAW_DATA, $iv, $tag);
}

// On your redirect_uri route, e.g. GET /handlePaymentResponse?data=...
$result = json_decode(decryptPayload($_GET['data'], $secretKey), true);

// $result now holds transactionDbId, siteReferenceId, status, transactionId,
// amount, pgFees, totalAmount, currency, paymentMethod, etc.
if ($result['status'] === 'success') {
    // mark the order as paid
}
```

### Other endpoints: sample requests

These are authenticated with the simpler `X-TOKEN: {clientId}:{clientSecret}` header instead of payload encryption.

**`GET /api/v1/transaction/{reference_id}`** — fetch one transaction by your own reference id:

```php
$clientId = 'your-client-id';
$clientSecret = 'your-client-secret';

$ch = curl_init("https://your-router-domain.tld/api/v1/transaction/order-1234");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "X-TOKEN: {$clientId}:{$clientSecret}",
    ],
]);

$transaction = json_decode(curl_exec($ch), true);
curl_close($ch);
```

**`GET /api/v1/transactions`** — paginated transaction list, optionally filtered by date range:

```php
$clientId = 'your-client-id';
$clientSecret = 'your-client-secret';

$query = http_build_query([
    'start_date' => '20260101', // Ymd
    'end_date' => '20260131',   // Ymd
    'per_page' => 20,
]);

$ch = curl_init("https://your-router-domain.tld/api/v1/transactions?{$query}");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "X-TOKEN: {$clientId}:{$clientSecret}",
    ],
]);

$transactions = json_decode(curl_exec($ch), true);
curl_close($ch);
```

## Roles

Three roles are seeded by `RolesAndPermissionsSeeder`, each scoped to what it needs in the admin panel:

| Role | Can do |
|---|---|
| **superadmin** | Everything below, plus create/edit/delete other admin users |
| **admin** | Manage users, clients, PG connections, client↔PG connections, and view all transactions |
| **user** | View clients and view transactions only (read-only) |

Permissions are managed through [spatie/laravel-permission](https://spatie.be/docs/laravel-permission) and can be inspected/extended in `database/seeders/RolesAndPermissionsSeeder.php`.

## Testing & quality

```bash
composer test        # config:clear, Pint (check-only), then the Pest suite
composer lint         # Pint, auto-fixing style issues
composer lint:check   # Pint, check-only (used in CI)
vendor/bin/phpstan analyse   # Static analysis (Larastan, level 7)
```

CI runs the same checks against PHP 8.3–8.5 on every push/PR to `main` (see `.github/workflows/tests.yml` and `.github/workflows/lint.yml`).

## Deployment

1. **Provision the environment** — PHP 8.3+ with required extensions, a MySQL/PostgreSQL database, Redis (recommended for cache/queue in production), and a queue worker process supervisor (e.g. Supervisor/systemd) if `PG_TRANSACTION_EMAIL_ENABLED` or other queued jobs are in use.
2. **Configure `.env`** for the target environment:
   - `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` set to your real, publicly reachable HTTPS domain — several gateways (e.g. Cashfree, PayPal) validate that callback/return URLs point at a real, resolvable domain and will reject local/reserved domains.
   - Real `DB_*`, `CACHE_STORE`, `QUEUE_CONNECTION`, and `SESSION_DRIVER` values (production should not run on SQLite/array/file drivers).
   - Mail settings if `PG_TRANSACTION_EMAIL_ENABLED=true`.
3. **Install dependencies for production**:
   ```bash
   composer install --no-dev --optimize-autoloader --no-interaction
   npm ci && npm run build
   ```
4. **Run migrations and seeders**:
   ```bash
   php artisan migrate --force
   php artisan db:seed --class="Database\Seeders\RolesAndPermissionsSeeder" --force
   php artisan db:seed --class="Database\Seeders\SupportedPGSeeder" --force
   ```
5. **Create a Filament admin user with a role.** This project extends Filament's stock `make:filament-user` command with a `--role` option that assigns one of the seeded roles (`superadmin`, `admin`, `user`) in the same step:
   ```bash
   php artisan make:filament-user --role=superadmin
   ```
   You'll be prompted for a name, email, and password. To run it non-interactively (e.g. in a deploy script):
   ```bash
   php artisan make:filament-user \
     --name="Jane Admin" \
     --email="jane@example.com" \
     --password="a-strong-password" \
     --role=superadmin
   ```
   `--role` must be one of the roles already seeded by `RolesAndPermissionsSeeder` (step 4) — the command fails fast and lists the available roles if you pass one that doesn't exist.
6. **Cache framework bootstrapping** for performance:
   ```bash
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   php artisan event:cache
   ```
7. **Serve the app** behind Nginx/Apache + PHP-FPM (or your platform of choice — Laravel Forge, Vapor, Docker, etc.), pointing the webroot at `public/`, and run a persistent queue worker:
   ```bash
   php artisan queue:work --tries=3 --daemon
   ```
8. **Point real gateway credentials** at `TYPE=PRODUCTION` PG Connections only after verifying the flow end-to-end in `TEST` mode — every gateway class here talks to the gateway's real sandbox/production endpoints directly, so `TEST` connections should always use that gateway's sandbox credentials.
9. On every deploy, re-run `php artisan migrate --force` and the cache-priming commands above; consider `php artisan queue:restart` so workers pick up new code.

## Contributing

Issues and pull requests are welcome. Please run `composer test` and `vendor/bin/phpstan analyse` before opening a PR — CI enforces both.

## License

Released under the MIT license, as declared in `composer.json` (add a `LICENSE` file to the repository root to make this explicit for tooling and GitHub's license detector).

## Sponsorship

If this project saves you the trouble of writing your own multi-gateway payment router, consider supporting its development:

- ⭐ Star the repository — it helps others find the project.
- 💖 [Sponsor via GitHub Sponsors](https://github.com/sponsors) to support ongoing maintenance and new gateway integrations.
- 🐛 Sponsors and backers get priority on issues and feature requests.

<!-- Add your sponsors/backers here, e.g.: -->
<!--
### Sponsors

<a href="https://example.com"><img src="https://example.com/logo.png" width="120" alt="Sponsor name" /></a>
-->
