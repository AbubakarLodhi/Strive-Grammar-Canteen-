<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Branch;
use App\Models\Merchant;
use App\Services\Finance\OperationalLedgerPoster;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateExpense extends CreateRecord
{
    protected static string $resource = ExpenseResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data) {

            $items = $data['items'] ?? [];
            unset($data['items']);

            $user = Filament::auth()->user();

            /* --------------------------------
             | Resolve merchant + creator
             |-------------------------------- */
            if ($user instanceof Merchant) {
                $data['merchant_id'] = $user->id;
                $data['created_by'] = null;
            } else {
                $data['merchant_id'] = $user->merchant_id;
                $data['created_by'] = $user->id;
            }

            /* --------------------------------
             | Resolve business from branch
             |-------------------------------- */
            $data['business_id'] = Branch::where('id', $data['branch_id'])
                ->value('business_id');

            /* --------------------------------
             | Totals
             |-------------------------------- */
            $subtotal = collect($items)->sum(fn ($i) => (float) ($i['line_total'] ?? 0));
            $discount = (float) ($data['discount'] ?? 0);
            $tax = (float) ($data['tax'] ?? 0);

            $data['subtotal'] = $subtotal;
            $data['total_amount'] = $subtotal - $discount + $tax;

            $expense = static::getModel()::create($data);

            foreach ($items as $item) {
                $expense->items()->create($item);
            }

            app(OperationalLedgerPoster::class)->syncExpense($expense);

            return $expense;
        });
    }
}
