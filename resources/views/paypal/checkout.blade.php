<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Redirecting to PayPal...</title>
    <script src="https://www.paypal.com/sdk/js?client-id={{ $clientId }}&currency={{ $currency }}&intent=capture"></script>
    <style>
        body { font-family: sans-serif; background: #f3f4f6; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        p { color: #374151; font-size: 14px; }
        #paypal-button-container { width: 320px; }
    </style>
</head>
<body>
    <div>
        <p>Complete your payment with PayPal.</p>
        <div id="paypal-button-container"></div>
    </div>

    <script>
        paypal.Buttons({
            createOrder: function () {
                return '{{ $orderId }}';
            },
            onApprove: function (data) {
                window.location.href = '{{ $returnUrl }}' + '&orderId=' + encodeURIComponent(data.orderID);
            },
            onCancel: function () {
                window.location.href = '{{ $returnUrl }}' + '&status=cancelled';
            },
        }).render('#paypal-button-container');
    </script>
</body>
</html>
