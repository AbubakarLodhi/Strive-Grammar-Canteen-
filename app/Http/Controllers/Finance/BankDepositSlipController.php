<?php

namespace App\Http\Controllers\Finance;

use App\Models\BankDeposit;
use App\Models\Merchant;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class BankDepositSlipController
{
    public function show(Request $request, string $id): View
    {
        $user = auth('merchant')->user() ?? auth('staff')->user();

        if (! $user) {
            abort(401);
        }

        $merchantId = $user instanceof Merchant ? $user->id : $user->merchant_id;

        $deposit = BankDeposit::query()
            ->with(['merchant', 'bankAccount', 'sourceAccount'])
            ->whereKey($id)
            ->where('merchant_id', $merchantId)
            ->firstOrFail();

        return view('filament.pages.bank-deposit-slip', [
            'deposit' => $deposit,
        ]);
    }
}
