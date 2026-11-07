# Payment Gateway Router

[![tests](https://github.com/slnd77/payment-gateway-router/actions/workflows/tests.yml/badge.svg)](https://github.com/slnd77/payment-gateway-router/actions/workflows/tests.yml)
[![linter](https://github.com/slnd77/payment-gateway-router/actions/workflows/lint.yml/badge.svg)](https://github.com/slnd77/payment-gateway-router/actions/workflows/lint.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

A Laravel service that sits between my applications and the payment gateways they use. Each application integrates with one encrypted API; the router decides which gateway handles the payment, calls it, and returns the result in a single normalized format.

Without it, every site needs its own Razorpay/Cashfree/PayPal integration, and changing gateways means changing code in each one. With the router, a client app is registered once with its own credentials, and the gateway behind it (set separately for one-time payments and for subscriptions) is a setting in the admin panel.

It includes a [Filament](https://filamentphp.com) admin panel for clients, gateway connections, transactions and subscriptions. Every gateway API call is logged, so support and reconciliation happen in one place.

### Supported payment gateways

| Gateway | Class | Checkout style | Recurring / Subscriptions |
|---|---|---|---|
| Cashfree | `CASHFREE` | Hosted embedded checkout (Cashfree JS SDK) | Full support (auth, charge, pause/resume/cancel) |
| PG Simulator | `PGSimulator` | Local simulator for dev/QA (no live gateway calls) | Full simulation (auth, charge, pause/resume/cancel) |
| Razorpay | `RAZORPAY` | Hosted embedded checkout | One-time payments |
| PayPal | `PAYPAL` | Redirect to PayPal's hosted checkout | One-time payments |
| Stripe | `STRIPE` | Redirect to Stripe's hosted Checkout page | One-time payments |
| PayU | `PAYU` | Hosted checkout (signed form POST) | One-time payments |
| ICICI eazypay | `ICICI` | Hosted redirect | One-time payments |

Gateways supporting subscriptions implement `App\Contracts\SubscriptionPaymentGatewayInterface` alongside `App\Contracts\PaymentGatewayInterface`.

## Features

- Encrypted, per-client API (`AES-256-GCM`) for initiating one-time payments and recurring subscriptions/mandates
- Per-client routing between a one-time-payment gateway connection and a recurring/subscription gateway connection
- **Recurring Payments & Mandates Engine**:
  - Dedicated `/api/v1/initSubscription` endpoint with mandate authorization flow
  - Periodic (fixed schedule/amount) and on-demand (variable debit up to max amount) subscription types
  - On-demand recurring charging via `/api/v1/subscription/{reference_id}/charge`
  - Subscription lifecycle management (`PAUSE`, `RESUME`, `CANCEL`)
  - Subscription charges history and individual charge status checks
  - Scheduled background synchronization (`php artisan subscriptions:sync`) for non-terminal mandates
- Full request/response logging for every gateway API call (`payment_gateway_connection_api_logs`), independent of the client-facing API logs (`client_api_logs`)
- Role-based Filament admin panel for managing clients, gateway connections, client↔gateway mappings, transactions, and subscriptions with dedicated infolists and action triggers
- Refunds, transaction status checks, and subscription tracking where the underlying gateway supports them
- Optional transaction email notifications

## Tech stack

- **PHP** 8.4+ / **Laravel** 13
- **Filament** 4 (admin panel)
- **Spatie Laravel Permission** (roles & permissions)
- **Spatie Laravel Data**, **Devhammed Laravel Brick Money** (money-safe DTOs)
- **Pest 4** (testing), **Larastan / PHPStan**, **Laravel Pint** (static analysis & style)
- Gateway SDKs: `razorpay/razorpay`, `cashfree/cashfree-pg-sdk-php`, `paypal/paypal-server-sdk`, `stripe/stripe-php`

## Getting started

### Requirements

- PHP ^8.4 with the extensions Laravel requires (`ext-curl`, `ext-json`, `ext-mbstring`, etc.)
- Composer 2
- Node.js 22+ and npm (asset build only — this app has no SPA frontend)
- MySQL/MariaDB or PostgreSQL for a real deployment (SQLite works for local dev)

### Local setup

```bash
git clone https://github.com/slnd77/payment-gateway-router.git payment_gateway_router
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

| Endpoint | Purpose | Authentication |
|---|---|---|
| `GET /api/v1/initPayment` | Start a one-time payment for a client | Encrypted query params (`AES-256-GCM`) |
| `GET /api/v1/transaction/{reference_id}` | Fetch a single transaction by client reference ID | `X-TOKEN: {clientId}:{clientSecret}` |
| `GET /api/v1/transactions` | Paginated transaction list for a client | `X-TOKEN: {clientId}:{clientSecret}` |
| `GET /api/v1/initSubscription` | Start a recurring mandate authorization | Encrypted query params (`AES-256-GCM`) |
| `GET /api/v1/subscription/{reference_id}` | Fetch subscription details and status by client reference ID | `X-TOKEN: {clientId}:{clientSecret}` |
| `GET /api/v1/subscriptions` | Paginated subscription list (with status & date filters) | `X-TOKEN: {clientId}:{clientSecret}` |
| `POST /api/v1/subscription/{reference_id}/manage` | Manage lifecycle (`PAUSE`, `RESUME`, `CANCEL`) | `X-TOKEN: {clientId}:{clientSecret}` |
| `POST /api/v1/subscription/{reference_id}/charge` | Trigger a recurring debit on an active subscription | `X-TOKEN: {clientId}:{clientSecret}` |
| `GET /api/v1/subscription/{reference_id}/charges` | List recurring charges for a subscription | `X-TOKEN: {clientId}:{clientSecret}` |
| `GET /api/v1/subscription/{reference_id}/charges/{charge_reference_id}` | Fetch status of a specific charge | `X-TOKEN: {clientId}:{clientSecret}` |

`initPayment` and `initSubscription` are authenticated by encrypting the payload with the client's `client_secret` (`AES-256-GCM`, see `App\Classes\Encryption` and `HandleApiClientEncryptedRequest` middleware). All management and status endpoints run behind `HandleApiRequest`, which expects the `X-TOKEN: {clientId}:{clientSecret}` header.

### `initPayment`: encrypting the request

Build the payload, encrypt it with your `client_secret`, and send `clientId` + the encrypted `data` as query params to `GET /api/v1/initPayment`. The router initiates the payment with the gateway and replies with a JSON response containing the gateway's checkout URL:

```json
{
    "status": "success",
    "status_code": 0,
    "type": "one_time_payment",
    "url": "https://gateway-checkout-url...",
    "site_reference_id": "order-1234"
}
```

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

$initUrl = 'https://your-router-domain.tld/api/v1/initPayment?'.http_build_query([
    'clientId' => $clientId,
    'data' => encryptPayload($payload, $secretKey),
]);

// Call the router from your backend and redirect the customer to the gateway URL
$response = file_get_contents($initUrl);
$result = json_decode($response, true);

header('Location: '.$result['url']); // redirect customer to the payment gateway
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

## Recurring Payments & Subscriptions

Recurring payments are modelled on Cashfree's subscriptions API: mandate authorization, periodic plans, on-demand debits, `PAUSE`/`RESUME`/`CANCEL`, a charges ledger, and a scheduled sync with the gateway.

### Mandate Flow

```text
+-------------------+      GET /api/v1/initSubscription      +---------------------------+
|  Client Backend   | -------------------------------------> |  Payment Gateway Router   |
+-------------------+      (encrypted AES-256-GCM payload)   +---------------------------+
         |                                                                 |
         | Redirect customer to checkout URL                               | Create mandate with PG
         v                                                                 v
+-------------------+                                            +---------------------------+
| Customer Browser  | =========================================> | Payment Gateway Checkout  |
+-------------------+         Authorize recurring mandate        +---------------------------+
         |                                                                 |
         | Customer completes authorization                                | Postback / return
         v                                                                 v
+-------------------+      GET redirect_uri?data={encrypted}     +---------------------------+
| Client Return URL | <----------------------------------------- |  Return Handler / Sync    |
+-------------------+                                            +---------------------------+
```

### 1. `initSubscription`: Initiating a mandate

Build the subscription request payload, encrypt it using the client's `client_secret` (`AES-256-GCM`), and call `GET /api/v1/initSubscription`. The router registers the mandate with the configured gateway and returns a JSON response containing the checkout URL:

```json
{
    "status": "success",
    "status_code": 0,
    "type": "subscription",
    "url": "https://gateway-checkout-url...",
    "site_reference_id": "sub-ord-5001"
}
```

```php
$clientId = 'your-client-id';
$secretKey = 'your-client-secret';

$payload = [
    'reference_id' => 'sub-ord-5001',        // Unique reference ID per client
    'clientId' => $clientId,
    'subscription_type' => 'periodic',        // periodic | on_demand
    'currency' => 'INR',
    'amount' => 499.00,                      // Per-cycle amount (for periodic)
    'max_amount' => 1000.00,                 // Maximum debit cap per transaction
    'auth_amount' => 0.00,                   // Upfront authorization debit (e.g. 0 or 1)
    'period' => 'month',                     // day | week | month | year
    'interval' => 1,                         // Cycle interval (e.g. every 1 month)
    'max_cycles' => 12,                      // Total occurrences before mandate expires
    'customer' => [
        'name' => 'Alice Johnson',
        'email' => 'alice@example.com',
        'mobile' => '9876543210',
    ],
    'plan_id' => 'plan_gold_monthly',        // Optional plan identifier
    'plan_name' => 'Gold Monthly Membership',// Optional plan label
    'return_url' => 'https://example.test/sub-return', // Optional override of Client redirect_uri
];

$initUrl = 'https://your-router-domain.tld/api/v1/initSubscription?'.http_build_query([
    'clientId' => $clientId,
    'data' => encryptPayload($payload, $secretKey),
]);

// Call the router from your backend and redirect the customer to the gateway URL
$response = file_get_contents($initUrl);
$result = json_decode($response, true);

header('Location: '.$result['url']); // Send customer to checkout
```

### 2. Handling the authorization return

Upon authorization completion at the gateway checkout, the customer is redirected to the configured `return_url` (or `redirect_uri`) with an encrypted `data` parameter. Decrypting the payload produces a normalized `SubscriptionResponseDTO`:

```json
{
    "subscriptionDbId": "14",
    "siteReferenceId": "sub-ord-5001",
    "status": "ACTIVE",
    "subscriptionId": "sub_cf_123456",
    "pgReferenceId": "cf_ref_7890",
    "authorizationReference": "UMRN00000000001",
    "subscriptionType": "periodic",
    "amount": 499.00,
    "maxAmount": 1000.00,
    "currency": "INR",
    "period": "month",
    "interval": 1,
    "paymentMethod": "card",
    "nextChargeDateTime": "2026-11-07T12:00:00+00:00"
}
```

### 3. Triggering a recurring charge (`charge`)

To execute a subsequent debit on an active or on-demand mandate, make an authenticated request (`X-TOKEN`):

```bash
curl -X POST https://your-router-domain.tld/api/v1/subscription/sub-ord-5001/charge \
  -H "X-TOKEN: your-client-id:your-client-secret" \
  -H "Content-Type: application/json" \
  -d '{
    "charge_reference_id": "charge_nov_2026",
    "amount": 499.00,
    "currency": "INR",
    "remarks": "Monthly subscription fee for November 2026"
  }'
```

Response:

```json
{
    "chargeDbId": "42",
    "siteReferenceId": "charge_nov_2026",
    "subscriptionReferenceId": "sub-ord-5001",
    "status": "SUCCESS",
    "transactionId": "cf_pay_987654",
    "paymentId": "SUBTXN42",
    "amount": 499.00,
    "pgFees": 9.98,
    "currency": "INR",
    "paymentMethod": "card",
    "transactionDateTime": "2026-11-07T12:05:00+00:00"
}
```

### 4. Lifecycle management (`manage`)

Manage the status of active subscriptions:

```bash
# Pause a subscription
curl -X POST https://your-router-domain.tld/api/v1/subscription/sub-ord-5001/manage \
  -H "X-TOKEN: your-client-id:your-client-secret" \
  -H "Content-Type: application/json" \
  -d '{"action": "pause"}'

# Resume a paused subscription
curl -X POST https://your-router-domain.tld/api/v1/subscription/sub-ord-5001/manage \
  -H "X-TOKEN: your-client-id:your-client-secret" \
  -H "Content-Type: application/json" \
  -d '{"action": "resume"}'

# Cancel a subscription
curl -X POST https://your-router-domain.tld/api/v1/subscription/sub-ord-5001/manage \
  -H "X-TOKEN: your-client-id:your-client-secret" \
  -H "Content-Type: application/json" \
  -d '{"action": "cancel"}'
```

### 5. Listing and checking charges

```bash
# List all charges for a subscription
curl https://your-router-domain.tld/api/v1/subscription/sub-ord-5001/charges \
  -H "X-TOKEN: your-client-id:your-client-secret"

# Check the real-time status of a specific charge from the gateway
curl https://your-router-domain.tld/api/v1/subscription/sub-ord-5001/charges/charge_nov_2026 \
  -H "X-TOKEN: your-client-id:your-client-secret"
```

### 6. Background synchronization

A scheduled command synchronizes the status of non-terminal subscriptions from their respective payment gateways:

```bash
# Sync all non-terminal subscriptions
php artisan subscriptions:sync

# Sync a specific subscription by its primary key ID
php artisan subscriptions:sync --id=14
```

This command runs hourly via Laravel's scheduler (`routes/console.php`).

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
composer test              # config:clear, Pint (check-only), then Feature + Browser (mocked) tests
composer test:live-sandbox # Feature/Browser tests that hit each gateway's *real* sandbox - opt-in, needs .env.testing.local credentials
composer lint               # Pint, auto-fixing style issues
composer lint:check         # Pint, check-only (used in CI)
vendor/bin/phpstan analyse         # Static analysis (Larastan, level 7)
```

`composer test`/`composer ci:check` never touch a third-party site: `tests/Feature` and `tests/Browser` are fully mocked (`Http::fake()` or an SDK client swapped out). The real, network-dependent end-to-end tests against each gateway's own sandbox (`tests/BrowserLiveSandbox/*LiveSandboxTest.php`) are a separate `LiveSandbox` PHPUnit suite, run only via `composer test:live-sandbox`, and skip automatically when `.env.testing.local` credentials aren't present (see `.env.testing.local.example`).

CI runs `composer test` against PHP 8.4–8.5 on every push/PR to `main` (see `.github/workflows/tests.yml` and `.github/workflows/lint.yml`) — the live-sandbox suite is never run in CI.

## Deployment

1. **Provision the environment** — PHP 8.4+ with required extensions, a MySQL/PostgreSQL database, Redis (recommended for cache/queue in production), and a queue worker process supervisor (e.g. Supervisor/systemd) if `PG_TRANSACTION_EMAIL_ENABLED` or other queued jobs are in use.
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

## License

[MIT](LICENSE)
