<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>PG Simulator Subscription Authorization</title>
    <style>
        body { font-family: sans-serif; background: #f3f4f6; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .card { background: #fff; border-radius: 8px; padding: 32px; width: 380px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        h1 { font-size: 18px; margin: 0 0 4px; }
        .badge { display: inline-block; background: #e0e7ff; color: #3730a3; font-size: 12px; padding: 2px 8px; border-radius: 4px; margin-bottom: 16px; }
        .row { display: flex; justify-content: space-between; font-size: 14px; margin-bottom: 8px; color: #374151; }
        .row strong { color: #111827; }
        select { width: 100%; padding: 8px; margin: 12px 0; border: 1px solid #d1d5db; border-radius: 4px; }
        button { width: 100%; padding: 10px; border: none; border-radius: 4px; font-size: 14px; cursor: pointer; margin-top: 8px; }
        .success { background: #16a34a; color: #fff; }
        .pending { background: #f59e0b; color: #fff; }
        .failed { background: #dc2626; color: #fff; }
    </style>
</head>
<body>
    <div class="card">
        <span class="badge">PG Simulator — Recurring Mandate</span>
        <h1>{{ $subscription->pgConnection->name }}</h1>
        <div class="row"><span>Reference</span><strong>{{ $subscription->site_reference_id }}</strong></div>
        <div class="row"><span>Type</span><strong>{{ strtoupper($subscription->subscription_type->value) }}</strong></div>
        <div class="row"><span>Recurring Amount</span><strong>{{ (string) $subscription->currency }} {{ $subscription->amount['amount']->getAmount() }}</strong></div>
        <div class="row"><span>Max Amount</span><strong>{{ (string) $subscription->currency }} {{ $subscription->max_amount['max_amount']->getAmount() }}</strong></div>
        @if($subscription->period)
            <div class="row"><span>Frequency</span><strong>Every {{ $subscription->interval }} {{ $subscription->period->value }}</strong></div>
        @endif

        <form method="POST" action="{{ $responseUrl }}">
            @csrf
            <input type="hidden" name="subscriptionDbId" value="{{ $subscription->id }}">
            <input type="hidden" name="subscription_id" value="{{ $subscription->subscription_id }}">

            <select name="paymentMethod">
                <option value="upi">UPI AutoPay</option>
                <option value="card">Card Standing Instructions</option>
                <option value="nettbanking">eNACH Netbanking</option>
            </select>

            <button type="submit" name="status" value="ACTIVE" class="success">Simulate Authorize (ACTIVE)</button>
            <button type="submit" name="status" value="BANK_APPROVAL_PENDING" class="pending">Simulate Bank Approval Pending</button>
            <button type="submit" name="status" value="FAILED" class="failed">Simulate Mandate Failed</button>
        </form>
    </div>
</body>
</html>
