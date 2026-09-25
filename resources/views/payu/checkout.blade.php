<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Redirecting to PayU...</title>
    <style>
        body { font-family: sans-serif; background: #f3f4f6; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        p { color: #374151; font-size: 14px; }
    </style>
</head>
<body>
    <p>Redirecting to PayU checkout...</p>

    <form id="payu-checkout-form" method="POST" action="{{ $checkoutEndpoint }}">
        @foreach ($fields as $name => $value)
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endforeach
    </form>

    <script>
        document.getElementById('payu-checkout-form').submit();
    </script>
</body>
</html>
