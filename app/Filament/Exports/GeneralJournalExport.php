<?php

namespace App\Filament\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class GeneralJournalExport implements FromArray, WithHeadings, WithTitle
{
    /**
     * @param  Collection<int, object>  $rows
     */
    public function __construct(
        private Collection $rows,
        private float $debitTotal,
        private float $creditTotal,
    ) {}

    public function title(): string
    {
        return 'General Journal';
    }

    public function headings(): array
    {
        return ['Date', 'Voucher', 'Account', 'Description', 'Debit', 'Credit'];
    }

    public function array(): array
    {
        $data = $this->rows->map(fn (object $row): array => [
            optional($row->date)->format('d/m/Y'),
            $row->voucher_no,
            $row->account,
            $row->description,
            $row->debit,
            $row->credit,
        ])->all();

        $data[] = ['', '', '', 'Total', $this->debitTotal, $this->creditTotal];

        return $data;
    }
}
