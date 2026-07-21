<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Redirecting to Cashfree...</title>
    <script src="https://sdk.cashfree.com/js/v3/cashfree.js"></script>
    <style>
        body { font-family: sans-serif; background: #f3f4f6; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        p { color: #374151; font-size: 14px; }
    </style>
</head>
<body>
    <p>Redirecting to Cashfree checkout...</p>

    <script>
        const cashfree = new Cashfree({ mode: "{{ $mode }}" });

        cashfree.checkout({
            paymentSessionId: "{{ $paymentSessionId }}",
            redirectTarget: "_self",
        });
    </script>
</body>
</html>
