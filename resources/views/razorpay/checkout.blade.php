<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Redirecting to Razorpay...</title>
    <style>
        body { font-family: sans-serif; background: #f3f4f6; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        p { color: #374151; font-size: 14px; }
    </style>
</head>
<body>
    <p>Redirecting to Razorpay checkout...</p>

    <form id="razorpay-embedded-form" method="POST" action="{{ $checkoutEndpoint }}">
        <input type="hidden" name="key_id" value="{{ $keyId }}">
        <input type="hidden" name="order_id" value="{{ $orderId }}">
        <input type="hidden" name="amount" value="{{ $amountMinorUnits }}">
        <input type="hidden" name="currency" value="{{ $currency }}">
        <input type="hidden" name="name" value="{{ $name }}">
        <input type="hidden" name="description" value="{{ $description }}">
        <input type="hidden" name="prefill[name]" value="{{ $customerName }}">
        <input type="hidden" name="prefill[email]" value="{{ $customerEmail }}">
        <input type="hidden" name="prefill[contact]" value="{{ $customerContact }}">
        <input type="hidden" name="callback_url" value="{{ $callbackUrl }}">
        <input type="hidden" name="cancel_url" value="{{ $cancelUrl }}">
    </form>

    <script>
        document.getElementById('razorpay-embedded-form').submit();
    </script>
</body>
</html>
