<?php

namespace App\Filament\Resources\JournalVouchers\Pages;

use App\Filament\Resources\JournalVouchers\JournalVoucherResource;
use App\Services\Finance\FinanceLedger;
use App\Support\FinanceAccess;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditJournalVoucher extends EditRecord
{
    protected static string $resource = JournalVoucherResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['lines'] = $this->record->lines()
            ->get(['ledger_account_id', 'description', 'debit', 'credit'])
            ->toArray();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $lines = $data['lines'] ?? [];
        unset($data['lines']);

        $ledger = app(FinanceLedger::class);
        $ledger->assertBalanced($lines);

        return DB::transaction(function () use ($record, $data, $lines) {
            $record->update($data);
            $record->lines()->delete();

            foreach (array_values($lines) as $index => $line) {
                $record->lines()->create([
                    'ledger_account_id' => $line['ledger_account_id'],
                    'description' => $line['description'] ?? null,
                    'debit' => $line['debit'] ?? 0,
                    'credit' => $line['credit'] ?? 0,
                    'sort_order' => $index + 1,
                ]);
            }

            return $record;
        });
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (): bool => FinanceAccess::can('journal_vouchers', 'delete') && ! $this->record->isPosted()),
        ];
    }
}
