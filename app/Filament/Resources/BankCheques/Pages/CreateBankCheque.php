<?php

namespace App\Filament\Resources\BankCheques\Pages;

use App\Enums\ChequeDirection;
use App\Enums\ChequeStatus;
use App\Filament\Resources\BankCheques\BankChequeResource;
use App\Models\ChequeBook;
use App\Services\Finance\FinanceLedger;
use App\Support\FinanceAccess;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateBankCheque extends CreateRecord
{
    protected static string $resource = BankChequeResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['merchant_id'] = FinanceAccess::merchantId();
        $data['created_by'] = FinanceAccess::createdBy();
        $data['status'] = ChequeStatus::Pending->value;

        $direction = $data['direction'] ?? null;

        if ($direction === ChequeDirection::Outgoing->value) {
            $bookId = $data['cheque_book_id'] ?? null;
            if (! $bookId) {
                throw ValidationException::withMessages([
                    'cheque_book_id' => 'Select a cheque book for outgoing cheques.',
                ]);
            }

            $book = ChequeBook::query()->find($bookId);
            if (! $book) {
                throw ValidationException::withMessages([
                    'cheque_book_id' => 'Cheque book not found.',
                ]);
            }

            $data['bank_account_id'] = $book->bank_account_id;
            $data['cheque_number'] = app(FinanceLedger::class)->allocateChequeNumber($book);
            $data['payer_name'] = null;
        } else {
            $data['cheque_book_id'] = null;
            $data['payee_name'] = null;
        }

        return $data;
    }
}
