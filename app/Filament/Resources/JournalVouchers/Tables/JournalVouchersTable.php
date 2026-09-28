<?php

namespace App\Filament\Resources\JournalVouchers\Tables;

use App\Enums\FinanceDocumentStatus;
use App\Filament\Resources\JournalVouchers\JournalVoucherResource;
use App\Models\Expense;
use App\Models\JournalVoucher;
use App\Models\Payroll;
use App\Models\Purchase;
use App\Models\Sale;
use App\Support\FinanceAccess;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class JournalVouchersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('voucher_no')
                    ->label('Voucher')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('voucher_date')
                    ->label('Date')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('narration')
                    ->limit(40)
                    ->toggleable(),
                TextColumn::make('source_type')
                    ->label('Source')
                    ->formatStateUsing(function (?string $state, JournalVoucher $record): string {
                        if (! filled($state)) {
                            return 'Manual';
                        }

                        $base = class_basename($state);

                        return match ($base) {
                            'Sale' => 'Sale',
                            'SaleReturn' => 'Sale return',
                            'Purchase' => 'Purchase',
                            'PurchaseReturn' => 'Purchase return',
                            'Expense' => 'Expense',
                            'Payroll' => 'Payroll',
                            'BankDeposit' => 'Bank deposit',
                            'CashVoucher' => 'Cash voucher',
                            'OnlineBankTransfer' => 'Online transfer',
                            'BankCheque' => 'Bank cheque',
                            default => $base,
                        };
                    })
                    ->badge()
                    ->toggleable(),
                TextColumn::make('vendor.name')
                    ->label('Vendor')
                    ->toggleable()
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (FinanceDocumentStatus|string $state): string => $state instanceof FinanceDocumentStatus ? $state->label() : $state)
                    ->color(fn (FinanceDocumentStatus|string $state): string => ($state instanceof FinanceDocumentStatus ? $state : FinanceDocumentStatus::tryFrom((string) $state))?->value === 'posted' ? 'success' : 'warning'),
                TextColumn::make('debit_total')
                    ->label('Debit')
                    ->state(fn (JournalVoucher $record): string => $record->totalDebit())
                    ->numeric(2),
                TextColumn::make('credit_total')
                    ->label('Credit')
                    ->state(fn (JournalVoucher $record): string => $record->totalCredit())
                    ->numeric(2),
            ])
            ->defaultSort('voucher_date', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(FinanceDocumentStatus::options()),
                SelectFilter::make('source_type')
                    ->label('Source')
                    ->options([
                        'manual' => 'Manual',
                        Sale::class => 'Sale',
                        Purchase::class => 'Purchase',
                        Expense::class => 'Expense',
                        Payroll::class => 'Payroll',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if (! filled($value)) {
                            return $query;
                        }
                        if ($value === 'manual') {
                            return $query->whereNull('source_type');
                        }

                        return $query->where('source_type', $value);
                    }),
            ])
            ->recordActions([
                ViewAction::make()->label('')->tooltip('View'),
                EditAction::make()
                    ->color('warning')
                    ->label('')
                    ->tooltip('Edit')
                    ->visible(fn (JournalVoucher $record): bool => FinanceAccess::can('journal_vouchers', 'update') && ! $record->isPosted()),
                DeleteAction::make()
                    ->color('danger')
                    ->label('')
                    ->tooltip('Delete')
                    ->visible(fn (JournalVoucher $record): bool => FinanceAccess::can('journal_vouchers', 'delete') && ! $record->isPosted()),
            ])
            ->recordUrl(fn (JournalVoucher $record): string => JournalVoucherResource::getUrl('view', ['record' => $record]));
    }
}
