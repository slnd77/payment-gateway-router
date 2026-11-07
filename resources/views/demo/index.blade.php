@extends('demo.layout')

@section('title', 'Checkout')

@section('content')
    <h1>Payment Gateway Router demo</h1>
    <p>This page plays the part of a merchant site. It encrypts an order with its client secret, calls <code>GET /api/v1/initPayment</code>, and sends you to whichever gateway the router returns. All gateways run in test/sandbox mode, so no real money moves.</p>

    <div class="card">
        <h2>Place a test order</h2>
        @if ($gateways === [])
            <p>No demo gateways are configured.</p>
        @else
            <form method="POST" action="{{ route('demo.pay') }}">
                @csrf
                <div class="grid">
                    <div>
                        <label for="gateway">Gateway</label>
                        <select id="gateway" name="gateway">
                            @foreach ($gateways as $key => $gateway)
                                <option value="{{ $key }}" @selected(old('gateway') === $key)>{{ $gateway['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="amount">Amount (INR)</label>
                        <input id="amount" name="amount" type="number" min="1" max="100000" step="0.01" value="{{ old('amount', 499) }}">
                    </div>
                    <div>
                        <label for="name">Name</label>
                        <input id="name" name="name" value="{{ old('name', 'Demo Customer') }}">
                    </div>
                    <div>
                        <label for="email">Email</label>
                        <input id="email" name="email" type="email" value="{{ old('email', 'customer@example.com') }}">
                    </div>
                    <div>
                        <label for="mobile">Mobile</label>
                        <input id="mobile" name="mobile" value="{{ old('mobile', '9876543210') }}">
                    </div>
                </div>
                @if ($errors->any())
                    <div class="error">{{ $errors->first() }}</div>
                @endif
                <button type="submit">Pay</button>
            </form>
        @endif
    </div>

    <div class="card">
        <h2>Test cards and accounts</h2>
        <ul>
            <li><strong>PG Simulator:</strong> pick success, pending or failure on its checkout page.</li>
            <li><strong>Razorpay:</strong> card <code>4111 1111 1111 1111</code>, any future expiry, any CVV; or UPI <code>success@razorpay</code>.</li>
            <li><strong>Stripe:</strong> card <code>4242 4242 4242 4242</code>, any future expiry, any CVC. <code>4000 0000 0000 0002</code> is declined.</li>
            <li><strong>ICICI eazypay:</strong> UAT environment; follow the test options shown on the bank page.</li>
        </ul>
    </div>

    <div class="card">
        <h2>Admin panel</h2>
        <p>See the transactions, gateway API logs and client setup behind this checkout.</p>
        <table>
            <tr><td>URL</td><td><a href="{{ url('/dashboard') }}">{{ url('/dashboard') }}</a></td></tr>
            <tr><td>Email</td><td><code>{{ $admin['email'] }}</code></td></tr>
            <tr><td>Password</td><td><code>{{ $admin['password'] }}</code></td></tr>
        </table>
        <p style="margin-top:12px">The demo database is wiped and reseeded every 3 hours, so feel free to change anything.</p>
    </div>
@endsection
