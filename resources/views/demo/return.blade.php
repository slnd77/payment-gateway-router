@extends('demo.layout')

@section('title', 'Payment result')

@section('content')
    <h1>Payment result</h1>
    <p>The router redirected back to this merchant's <code>redirect_uri</code> with an encrypted <code>data</code> parameter. Decrypted with the client secret, it reads:</p>

    <div class="card">
        @if ($error)
            <div class="error">{{ $error }}</div>
        @else
            @php($status = strtolower((string) ($result['status'] ?? '')))
            <h2>{{ $gateway }}: <span class="status status-{{ $status }}">{{ $status ?: 'unknown' }}</span></h2>
            <table>
                @foreach ($result as $field => $value)
                    <tr>
                        <td>{{ $field }}</td>
                        <td>{{ is_scalar($value) || $value === null ? var_export($value, true) : json_encode($value) }}</td>
                    </tr>
                @endforeach
            </table>
        @endif
    </div>

    <a class="button" href="{{ route('demo.index') }}">Place another order</a>
@endsection
