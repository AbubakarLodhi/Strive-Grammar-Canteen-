<?php

namespace App\Filament\Resources\Customers\Tables;

use App\Filament\Exports\CustomerSalesExport;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Branch;
use App\Models\Business;
use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\Sale;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Forms\Components\DatePicker;

use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Grid;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->limit(30)
                    ->searchable(),

                TextColumn::make('phone')
                    ->searchable(),

                TextColumn::make('email')
                    ->label('Email address')
                    ->limit(30)
                    ->searchable(),

                TextColumn::make('total_amount')
                    ->label('Total Amount (PKR)')
                    ->alignRight()
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2))
                    ->getStateUsing(function (Customer $record, $livewire) {
                        $branchIds = $livewire->getTableFilterState('branch_id')['values'] ?? [];
                        return self::customerLedgerTotals($record, $branchIds)['total_amount'];
                    })
                    ->sortable(),

                TextColumn::make('amount_paid')
                    ->label('Amount Paid (PKR)')
                    ->alignRight()
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2))
                    ->getStateUsing(function (Customer $record, $livewire) {
                        $branchIds = $livewire->getTableFilterState('branch_id')['values'] ?? [];
                        return self::customerLedgerTotals($record, $branchIds)['amount_paid'];
                    }),

                TextColumn::make('amount_pending')
                    ->label('Amount Pending (PKR)')
                    ->alignRight()
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2))
                    ->getStateUsing(function (Customer $record, $livewire) {
                        $branchIds = $livewire->getTableFilterState('branch_id')['values'] ?? [];
                        return self::customerLedgerTotals($record, $branchIds)['amount_pending'];
                    }),
                TextColumn::make('occupation')
                    ->label('Occupation')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->limit(30)
                    ->searchable(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                // Show merchant name instead of ID
                BadgeColumn::make('merchant.name')
                    ->label('Merchant')
                    ->color('primary')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable(),

                // Show reference customer name instead of ID
                TextColumn::make('reference')
                    ->label('Reference Customer')
                    ->limit(30)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable(),
            ])
            ->filters([
                SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->multiple()
                    ->searchable()
                    ->options(function () {
                        $user = Filament::auth()->user();

                        $merchantId = match (true) {
                            $user instanceof \App\Models\Merchant => $user->id,
                            $user instanceof \App\Models\User     => $user->merchant_id,
                            default                               => null,
                        };

                        if (! $merchantId) {
                            return [];
                        }

                        $query = Branch::query()
                            ->withoutTrashed()
                            ->where('merchant_id', $merchantId);

                        if ($user instanceof \App\Models\User) {
                            $query->whereHas('users', fn ($q) =>
                                $q->where('users.id', $user->id)
                            );
                        }

                        return $query->orderBy('name')->pluck('name', 'id')->toArray();
                    })
                    ->query(function (Builder $query, array $data) {
                        if (empty($data['values'])) {
                            return;
                        }

                        $query->whereHas('branches', fn ($q) =>
                            $q->whereIn('branches.id', $data['values'])
                        );
                    }),
            ])
            ->recordUrl(fn (Customer $record) =>
            auth(Filament::getCurrentPanel()->getAuthGuard())
                ->user()
                ?->hasPermissionTo('customers.view', Filament::getCurrentPanel()->getAuthGuard())
                ? CustomerResource::getUrl('sales', [
                'record' => $record,
            ])
                : null
            )

            ->recordActions([
                Action::make('export_customer_sales')
                    ->label('')
                    ->icon('heroicon-s-arrow-down-tray')
                    ->color('success')
                    ->tooltip('Export Customer Sales')
                    ->modalHeading('Export Customer Sales')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Select::make('format')
                                    ->label('Export Format')
                                    ->options([
                                        'excel' => 'Excel (.xlsx)',
                                        'pdf'   => 'PDF (.pdf)',
                                    ])
                                    ->default('excel')
                                    ->required()
                                    ->native(false),
                                DatePicker::make('date_from')
                                    ->label('From Date')
                                    ->native(false),
                                Select::make('columns')
                                    ->label('Columns')
                                    ->multiple()
                                    ->searchable()
                                    ->native(false)
                                    ->options(fn () => ['__all__' => 'Select All'] + CustomerSalesExport::selectableColumns()),
                                DatePicker::make('date_to')
                                    ->label('To Date')
                                    ->native(false),
                            ]),
                    ])
                    ->visible(fn () => auth(Filament::getCurrentPanel()->getAuthGuard())
                        ->user()?->hasPermissionTo('reports.view', Filament::getCurrentPanel()->getAuthGuard()))
                    ->action(function (array $data, Customer $record) {
                        $user = Filament::auth()->user();

                        $merchantId = match (true) {
                            $user instanceof \App\Models\Merchant => $user->id,
                            $user instanceof \App\Models\User     => $user->merchant_id,
                            default                               => null,
                        };

                        $baseQuery = Sale::query()
                            ->withoutTrashed()
                            ->posted()
                            ->where('customer_id', $record->id)
                            ->when($merchantId, fn ($q) =>
                                $q->where('merchant_id', $merchantId)
                            )
                            ->when(
                                filled($data['date_from'] ?? null),
                                fn ($q) => $q->whereDate('sale_date', '>=', $data['date_from'])
                            )
                            ->when(
                                filled($data['date_to'] ?? null),
                                fn ($q) => $q->whereDate('sale_date', '<=', $data['date_to'])
                            );

                        if ($user instanceof \App\Models\User) {
                            $baseQuery
                                ->whereHas('items.business.users', fn ($q) =>
                                    $q->where('users.id', $user->id)
                                )
                                ->whereHas('items.branch.users', fn ($q) =>
                                    $q->where('users.id', $user->id)
                                );
                        }

                        $exportQuery = (clone $baseQuery)
                            ->withCount('items')
                            ->with([
                                'merchant',
                                'customer',
                                'items.product',
                                'items.branch',
                                'items.variants.variant',
                                'returns',
                                'payments',
                            ]);

                        $safeName = Str::slug($record->name ?? 'customer');
                        $timestamp = now()->format('Y-m-d_H-i-s');
                        $format = $data['format'] ?? 'excel';
                        $selectedColumns = is_array($data['columns'] ?? null)
                            ? array_values($data['columns'])
                            : [];
                        if (in_array('__all__', $selectedColumns, true)) {
                            $selectedColumns = array_keys(CustomerSalesExport::selectableColumns());
                        }
                        $merchantLogoDataUri = null;
                        if ($merchantId && extension_loaded('gd')) {
                            $merchant = \App\Models\Merchant::query()
                                ->with('logo')
                                ->find($merchantId);
                            $logoPath = $merchant?->logo?->photo_url;

                            if (filled($logoPath) && Storage::disk('public')->exists($logoPath)) {
                                try {
                                    $absolutePath = Storage::disk('public')->path($logoPath);
                                    $contents = file_get_contents($absolutePath);
                                    if ($contents !== false) {
                                        $mime = mime_content_type($absolutePath) ?: 'image/png';
                                        $merchantLogoDataUri = 'data:' . $mime . ';base64,' . base64_encode($contents);
                                    }
                                } catch (\Throwable) {
                                    $merchantLogoDataUri = null;
                                }
                            }
                        }
                        $sales = (clone $exportQuery)
                            ->orderBy('sale_date')
                            ->orderBy('created_at')
                            ->get();
                        $cashFlows = CashFlow::query()
                            ->withoutTrashed()
                            ->activeLedger()
                            ->where('merchant_id', $merchantId)
                            ->where('party_type', Customer::class)
                            ->where('party_id', $record->id)
                            ->when(
                                filled($data['date_from'] ?? null),
                                fn ($q) => $q->whereDate('flow_date', '>=', $data['date_from'])
                            )
                            ->when(
                                filled($data['date_to'] ?? null),
                                fn ($q) => $q->whereDate('flow_date', '<=', $data['date_to'])
                            )
                            ->with(['party', 'merchant'])
                            ->orderBy('flow_date')
                            ->orderBy('created_at')
                            ->get();
                        $totals = CustomerSalesExport::calculateTotals($sales, $cashFlows);

                        if ($format === 'pdf') {
                            $rows = CustomerSalesExport::buildStatementRows($sales, $cashFlows, $selectedColumns);
                            $headings = CustomerSalesExport::headingsFor($selectedColumns);
                            $paperOrientation = count($headings) > 10 ? 'landscape' : 'portrait';

                            $pdfContent = Pdf::loadView('exports.customer-sales-pdf', [
                                'customer' => $record,
                                'headings' => $headings,
                                'rows' => $rows,
                                'totals' => $totals,
                                'merchantLogoDataUri' => $merchantLogoDataUri,
                                'dateFrom' => $data['date_from'] ?? null,
                                'dateTo' => $data['date_to'] ?? null,
                            ])
                                ->setPaper('a4', $paperOrientation)
                                ->output();

                            return response()->streamDownload(
                                fn () => print($pdfContent),
                                "customer-sales-{$safeName}-{$timestamp}.pdf",
                                ['Content-Type' => 'application/pdf']
                            );
                        }

                        return Excel::download(
                            new CustomerSalesExport($sales, $cashFlows, $totals, $selectedColumns),
                            "customer-sales-{$safeName}-{$timestamp}.xlsx"
                        );
                    }),
                EditAction::make()
                    ->color('warning')
                    ->label('')
                    ->tooltip('Edit')
                    ->visible(fn () => auth(Filament::getCurrentPanel()->getAuthGuard())->user()?->hasPermissionTo('customers.update', Filament::getCurrentPanel()->getAuthGuard())),
                DeleteAction::make()
                    ->color('danger')
                    ->label('')
                    ->tooltip('Delete')
                    ->before(function (DeleteAction $action, ?Customer $record) {
                        if (! $record) {
                            return;
                        }

                        $hasOutstandingCredit = Sale::query()
                            ->withoutTrashed()
                            ->posted()
                            ->where('customer_id', $record->id)
                            ->where('due_amount', '>', 0)
                            ->exists();

                        if ($hasOutstandingCredit) {
                            Notification::make()
                                ->title('Cannot delete customer')
                                ->body('This customer has outstanding credit sales. Clear pending dues first.')
                                ->danger()
                                ->send();

                            $action->halt();
                        }
                    })
                    ->visible(fn () => auth(Filament::getCurrentPanel()->getAuthGuard())->user()?->hasPermissionTo('customers.delete', Filament::getCurrentPanel()->getAuthGuard())),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->before(function (DeleteBulkAction $action, $records) {
                            $customerIds = collect($records)->pluck('id')->filter()->values();

                            if ($customerIds->isEmpty()) {
                                return;
                            }

                            $hasOutstandingCredit = Sale::query()
                                ->withoutTrashed()
                                ->posted()
                                ->whereIn('customer_id', $customerIds)
                                ->where('due_amount', '>', 0)
                                ->exists();

                            if ($hasOutstandingCredit) {
                                Notification::make()
                                    ->title('Cannot delete customers')
                                    ->body('One or more selected customers have outstanding credit sales.')
                                    ->danger()
                                    ->send();

                                $action->halt();
                            }
                        })
                        ->visible(fn () => auth(Filament::getCurrentPanel()->getAuthGuard())->user()?->hasPermissionTo('customers.delete', Filament::getCurrentPanel()->getAuthGuard())),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    protected static function customerLedgerTotals(Customer $record, array $branchIds = []): array
    {
        static $cache = [];

        $cacheKey = $record->id . '|' . implode(',', $branchIds);
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $salesQuery = Sale::query()
            ->withoutTrashed()
            ->posted()
            ->where('customer_id', $record->id)
            ->where('merchant_id', $record->merchant_id);

        if (! empty($branchIds)) {
            $salesQuery->whereHas('items', fn ($q) => $q->whereIn('branch_id', $branchIds));
        }

        $salesDebits = round((float) (clone $salesQuery)->sum('total_amount'), 2);
        $salesCredits = round((float) (clone $salesQuery)->sum('paid_amount'), 2);

        $cashFlowQuery = CashFlow::query()
            ->withoutTrashed()
            ->activeLedger()
            ->where('merchant_id', $record->merchant_id)
            ->where('party_type', Customer::class)
            ->where('party_id', $record->id);

        if (! empty($branchIds)) {
            // Collect only the original (parent) cash flow IDs for the selected branches,
            // then include those originals and their child settlements — this prevents
            // settlements of other-branch originals from leaking into the totals.
            $branchOriginalIds = CashFlow::query()
                ->withoutTrashed()
                ->whereNull('settlement_for_id')
                ->where('merchant_id', $record->merchant_id)
                ->where('party_type', Customer::class)
                ->where('party_id', $record->id)
                ->whereIn('branch_id', $branchIds)
                ->pluck('id')
                ->all();

            $cashFlowQuery->where(function ($q) use ($branchOriginalIds) {
                $q->whereIn('id', $branchOriginalIds)
                  ->orWhereIn('settlement_for_id', $branchOriginalIds);
            });
        }

        // Customer statement rules:
        // - flow direction "in" reduces receivable (credit)
        // - flow direction "out" increases receivable (debit)
        $cashFlowDebits = round((float) (clone $cashFlowQuery)->where('direction', 'out')->sum('amount'), 2);
        $cashFlowCredits = round((float) (clone $cashFlowQuery)->where('direction', 'in')->sum('amount'), 2);

        $totalDebits = round($salesDebits + $cashFlowDebits, 2);
        $totalCredits = round($salesCredits + $cashFlowCredits, 2);

        return $cache[$cacheKey] = [
            'total_amount' => $totalDebits,
            'amount_paid' => $totalCredits,
            'amount_pending' => round($totalDebits - $totalCredits, 2),
        ];
    }
}
