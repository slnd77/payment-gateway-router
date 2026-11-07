<?php

use App\Filament\Widgets\RecentTransactionsWidget;
use Livewire\Livewire;

require_once __DIR__.'/FilamentTestHelpers.php';

it('shows each transaction amount once', function () {
    actingAsFilamentAdmin();
    $transaction = makeFilamentTestTransaction();

    Livewire::test(RecentTransactionsWidget::class)
        ->assertCanSeeTableRecords([$transaction])
        ->assertTableColumnStateSet('amount', '₹100', $transaction)
        ->assertSeeHtml('₹100')
        ->assertDontSeeHtml('₹100, ₹100');
});
