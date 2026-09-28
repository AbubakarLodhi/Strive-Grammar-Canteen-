<?php

namespace App\Filament\Resources\CashFlows\Tables;

use App\Filament\Resources\CashFlows\CashFlowResource;
use App\Filament\Resources\CashFlows\Schemas\CashFlowForm;
use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\User;
use App\Models\Vendor;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CashFlowsTable
{
    public static function configureGrouped(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('party_type')
                    ->label('Party Type')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        Customer::class => 'Customer',
                        Vendor::class => 'Vendor',
                        default => '-',
                    }),

                TextColumn::make('party.name')
                    ->label('Party')
                    ->searchable()
                    ->sortable()
                    ->default('-'),

                TextColumn::make('flow_date')
                    ->label('Latest Date')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('entries_count')
                    ->label('Entries')
                    ->getStateUsing(fn (CashFlow $record): int => self::partySummary($record)['entries_count']),

                TextColumn::make('payable_total')
                    ->label('Payable (PKR)')
                    ->money('PKR')
                    ->getStateUsing(fn (CashFlow $record): float => self::partySummary($record)['payable_total']),

                TextColumn::make('payable_settled')
                    ->label('Payable Settled (PKR)')
                    ->money('PKR')
                    ->getStateUsing(fn (CashFlow $record): float => self::partySummary($record)['payable_settled']),

                TextColumn::make('payable_remaining')
                    ->label('Payable Remaining (PKR)')
                    ->money('PKR')
                    ->getStateUsing(fn (CashFlow $record): float => self::partySummary($record)['payable_remaining'])
                    ->color(fn ($state) => (float) $state > 0 ? 'danger' : 'success'),

                TextColumn::make('receivable_total')
                    ->label('Receivable (PKR)')
                    ->money('PKR')
                    ->getStateUsing(fn (CashFlow $record): float => self::partySummary($record)['receivable_total']),

                TextColumn::make('receivable_settled')
                    ->label('Receivable Settled (PKR)')
                    ->money('PKR')
                    ->getStateUsing(fn (CashFlow $record): float => self::partySummary($record)['receivable_settled']),

                TextColumn::make('receivable_remaining')
                    ->label('Receivable Remaining (PKR)')
                    ->money('PKR')
                    ->getStateUsing(fn (CashFlow $record): float => self::partySummary($record)['receivable_remaining'])
                    ->color(fn ($state) => (float) $state > 0 ? 'warning' : 'success'),
            ])
            ->filters(static::sharedFilters())
            ->recordActions([
                Action::make('print')
                    ->label('')
                    ->tooltip('Print')
                    ->icon('heroicon-s-printer')
                    ->color('gray')
                    ->action(function (CashFlow $record) {
                        $partyAlias = CashFlowResource::partyAliasFor($record->party_type);
                        $party = CashFlowResource::visiblePartyRecord($partyAlias ?? '', (string) $record->party_id);

                        if (! $party) {
                            Notification::make()
                                ->danger()
                                ->title('Unable to print')
                                ->body('This party is no longer available in your current scope.')
                                ->send();

                            return null;
                        }

                        $cashFlows = CashFlowResource::partyCashFlowsQuery(
                            $partyAlias,
                            (string) $party->getKey(),
                        )
                            ->with(['createdBy', 'settlements'])
                            ->orderBy('flow_date')
                            ->orderBy('created_at')
                            ->get();

                        $merchantLogoDataUri = static::merchantLogoDataUri($record->merchant_id);
                        $safeName = Str::slug($party->name ?? 'party');
                        $timestamp = now()->format('Y-m-d_H-i-s');

                        $primaryEntries = $cashFlows->filter(fn (CashFlow $cf) => $cf->isPrimaryTransaction());
                        $payableEntries = $primaryEntries->where('flow_type', 'advance');
                        $receivableEntries = $primaryEntries->where('flow_type', 'loan');

                        $payableTotal = round((float) $payableEntries->sum('amount'), 2);
                        $payableSettled = round((float) $payableEntries->sum(fn (CashFlow $cf) => self::settledAmount($cf)), 2);
                        $receivableTotal = round((float) $receivableEntries->sum('amount'), 2);
                        $receivableSettled = round((float) $receivableEntries->sum(fn (CashFlow $cf) => self::settledAmount($cf)), 2);

                        $pdfContent = Pdf::loadView('exports.cash-flow-party-pdf', [
                            'party' => $party,
                            'partyLabel' => $record->party_type === Customer::class ? 'Customer' : 'Vendor',
                            'cashFlows' => $cashFlows,
                            'merchantLogoDataUri' => $merchantLogoDataUri,
                            'totals' => [
                                'payable_total' => $payableTotal,
                                'payable_settled' => $payableSettled,
                                'payable_remaining' => max(0, round($payableTotal - $payableSettled, 2)),
                                'receivable_total' => $receivableTotal,
                                'receivable_settled' => $receivableSettled,
                                'receivable_remaining' => max(0, round($receivableTotal - $receivableSettled, 2)),
                            ],
                        ])
                            ->setPaper('a4', 'portrait')
                            ->output();

                        return response()->streamDownload(
                            fn () => print ($pdfContent),
                            "cash-flow-{$safeName}-{$timestamp}.pdf",
                            ['Content-Type' => 'application/pdf']
                        );
                    })
                    ->visible(fn () => auth(Filament::getCurrentPanel()->getAuthGuard())->user()?->hasPermissionTo('cash_flows.view', Filament::getCurrentPanel()->getAuthGuard())),
            ])
            ->recordUrl(fn (CashFlow $record) => CashFlowResource::getUrl('party', [
                'partyType' => CashFlowResource::partyAliasFor($record->party_type),
                'partyId' => $record->party_id,
            ]))
            ->defaultSort('flow_date', 'desc');
    }

    public static function configureDetail(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('flow_date')
                    ->label('Date')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('party_type')
                    ->label('Party Type')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        Customer::class => 'Customer',
                        Vendor::class => 'Vendor',
                        default => '-',
                    }),

                TextColumn::make('party.name')
                    ->label('Party')
                    ->searchable()
                    ->sortable()
                    ->default('-'),

                TextColumn::make('flow_type')
                    ->label('Account Type')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => CashFlow::flowTypeLabel($state)),

                TextColumn::make('direction')
                    ->label('Direction')
                    ->badge()
                    ->color(fn (?string $state) => $state === 'in' ? 'success' : 'danger')
                    ->formatStateUsing(fn (?string $state) => $state === 'in' ? 'In' : 'Out'),

                TextColumn::make('transaction_type')
                    ->label('Transaction')
                    ->badge()
                    ->getStateUsing(fn (CashFlow $record): string => $record->isPrimaryTransaction() ? 'Original' : 'Settlement')
                    ->color(fn (string $state) => $state === 'Original' ? 'primary' : 'gray'),

                TextColumn::make('amount')
                    ->label('Amount')
                    ->money('PKR')
                    ->sortable(),

                TextColumn::make('settled_amount')
                    ->label('Settled')
                    ->money('PKR')
                    ->getStateUsing(function (CashFlow $record): float {
                        if (! $record->isPrimaryTransaction()) {
                            return 0.0;
                        }

                        return self::settledAmount($record);
                    }),

                TextColumn::make('remaining_amount')
                    ->label('Remaining')
                    ->money('PKR')
                    ->getStateUsing(function (CashFlow $record): float {
                        if (! $record->isPrimaryTransaction()) {
                            return 0.0;
                        }

                        return self::remainingAmount($record);
                    })
                    ->color(fn ($state) => (float) $state > 0 ? 'warning' : 'success'),

                TextColumn::make('method')
                    ->label('Method')
                    ->toggleable()
                    ->default('-'),

                TextColumn::make('ledgerAccount.name')
                    ->label('Cash / bank')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('createdBy.name')
                    ->label('Created By')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->default('-'),
            ])
            ->filters(static::sharedFilters())
            ->recordUrl(fn (CashFlow $record) => auth(Filament::getCurrentPanel()->getAuthGuard())
                ->user()
                ?->hasPermissionTo('cash_flows.update', Filament::getCurrentPanel()->getAuthGuard())
                    ? CashFlowResource::getUrl('edit', ['record' => $record])
                    : null
            )
            ->recordActions(static::detailActions())
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn () => auth(Filament::getCurrentPanel()->getAuthGuard())->user()?->hasPermissionTo('cash_flows.delete', Filament::getCurrentPanel()->getAuthGuard())),
                ]),
            ])
            ->defaultSort('flow_date', 'desc');
    }

    protected static function sharedFilters(): array
    {
        return [
            SelectFilter::make('party_type')
                ->label('Party Type')
                ->options([
                    Customer::class => 'Customer',
                    Vendor::class => 'Vendor',
                ]),

            SelectFilter::make('business_id')
                ->label('Business')
                ->options(fn () => CashFlowResource::accessibleBusinessesQuery(Filament::auth()->user())
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->toArray()
                )
                ->query(fn (Builder $query, array $data) => empty($data['value'])
                    ? null
                    : $query->where('business_id', $data['value'])),

            SelectFilter::make('branch_id')
                ->label('Branch')
                ->options(function ($livewire) {
                    $businessId = $livewire->getTableFilterState('business_id')['value'] ?? null;

                    return CashFlowResource::accessibleBranchesQuery(Filament::auth()->user(), $businessId)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->toArray();
                })
                ->query(function (Builder $query, array $data): void {
                    if (empty($data['value'])) {
                        return;
                    }

                    $query->where('branch_id', $data['value']);
                }),

            SelectFilter::make('flow_type')
                ->label('Account Type')
                ->options(CashFlow::flowTypeLabels()),

            SelectFilter::make('direction')
                ->label('Direction')
                ->options([
                    'in' => 'In',
                    'out' => 'Out',
                ]),

            Filter::make('date_range')
                ->label('Date Range')
                ->form([
                    DatePicker::make('from'),
                    DatePicker::make('to'),
                ])
                ->query(fn (Builder $query, array $data) => $query
                    ->when(
                        $data['from'] ?? null,
                        fn (Builder $builder, $date) => $builder->whereDate('flow_date', '>=', $date)
                    )
                    ->when(
                        $data['to'] ?? null,
                        fn (Builder $builder, $date) => $builder->whereDate('flow_date', '<=', $date)
                    )),
        ];
    }

    protected static function detailActions(): array
    {
        return [
            Action::make('settle')
                ->label('')
                ->tooltip('Settle')
                ->icon('heroicon-s-banknotes')
                ->color('success')
                ->modalHeading('Settle Cash Flow')
                ->schema([
                    DatePicker::make('flow_date')
                        ->label('Settlement Date')
                        ->required()
                        ->default(now()),
                    TextInput::make('amount')
                        ->label('Settlement Amount')
                        ->numeric()
                        ->required()
                        ->minValue(0.01),
                    Select::make('method')
                        ->label('Method')
                        ->options(CashFlow::methodLabels())
                        ->default('Cash')
                        ->required()
                        ->live()
                        ->native(false),
                    Select::make('ledger_account_id')
                        ->label('Cash / bank account')
                        ->required()
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->options(fn (callable $get): array => CashFlowForm::cashBankAccountOptions($get('method'))),
                    Textarea::make('notes')
                        ->label('Notes')
                        ->rows(3),
                ])
                ->action(function (CashFlow $record, array $data): void {
                    if (! $record->isPrimaryTransaction()) {
                        Notification::make()
                            ->danger()
                            ->title('Cannot settle this entry')
                            ->body('Only original cash flow entries can be settled.')
                            ->send();

                        return;
                    }

                    $remaining = self::remainingAmount($record);
                    $amount = round((float) ($data['amount'] ?? 0), 2);

                    if ($amount <= 0 || $amount > $remaining) {
                        Notification::make()
                            ->danger()
                            ->title('Invalid settlement amount')
                            ->body('Amount must be between PKR 0.01 and PKR '.number_format($remaining, 2).'.')
                            ->send();

                        return;
                    }

                    $direction = CashFlow::settlementDirectionForFlowType($record->flow_type);
                    $installmentNo = $record->settlements()->withoutTrashed()->count() + 1;
                    $referenceNo = sprintf(
                        'CF-STL-%s-%03d',
                        strtoupper(substr((string) $record->id, 0, 8)),
                        $installmentNo
                    );

                    $authUser = Filament::auth()->user();
                    $createdBy = $authUser instanceof User ? (string) $authUser->getKey() : null;
                    $method = in_array($data['method'] ?? null, ['Cash', 'Bank'], true) ? $data['method'] : 'Cash';

                    CashFlow::query()->create([
                        'merchant_id' => $record->merchant_id,
                        'business_id' => $record->business_id,
                        'branch_id' => $record->branch_id,
                        'party_type' => $record->party_type,
                        'party_id' => $record->party_id,
                        'settlement_for_id' => $record->id,
                        'flow_type' => $record->flow_type,
                        'direction' => $direction,
                        'amount' => $amount,
                        'flow_date' => $data['flow_date'] ?? now()->toDateString(),
                        'method' => $method,
                        'ledger_account_id' => $data['ledger_account_id'] ?? null,
                        'reference_no' => $referenceNo,
                        'notes' => $data['notes'] ?? null,
                        'created_by' => $createdBy,
                    ]);

                    $newRemaining = max(0, round($remaining - $amount, 2));

                    Notification::make()
                        ->success()
                        ->title('Settlement recorded')
                        ->body('PKR '.number_format($amount, 2).' settled. Remaining: PKR '.number_format($newRemaining, 2).'.')
                        ->send();
                })
                ->visible(function (CashFlow $record): bool {
                    $user = auth(Filament::getCurrentPanel()->getAuthGuard())->user();
                    $canSettle = $user?->hasPermissionTo('cash_flows.create', Filament::getCurrentPanel()->getAuthGuard())
                        || $user?->hasPermissionTo('cash_flows.update', Filament::getCurrentPanel()->getAuthGuard());

                    return $canSettle && $record->isPrimaryTransaction() && self::remainingAmount($record) > 0;
                }),

            Action::make('history')
                ->label('')
                ->tooltip('History')
                ->icon('heroicon-s-clock')
                ->color('gray')
                ->modalHeading('Cash Flow History')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalContent(function (CashFlow $record) {
                    $base = $record->settlementFor()->first() ?? $record;
                    $history = CashFlow::query()
                        ->withoutTrashed()
                        ->where(function (Builder $query) use ($base): void {
                            $query->whereKey($base->id)
                                ->orWhere('settlement_for_id', $base->id);
                        })
                        ->orderBy('flow_date')
                        ->orderBy('created_at')
                        ->get();

                    return view('filament.resources.cash-flows.settlement-history', [
                        'base' => $base,
                        'history' => $history,
                        'settled' => self::settledAmount($base),
                        'remaining' => self::remainingAmount($base),
                    ]);
                }),

            EditAction::make()
                ->label('')
                ->tooltip('Edit')
                ->visible(fn () => auth(Filament::getCurrentPanel()->getAuthGuard())->user()?->hasPermissionTo('cash_flows.update', Filament::getCurrentPanel()->getAuthGuard())),
            DeleteAction::make()
                ->label('')
                ->tooltip('Delete')
                ->visible(fn () => auth(Filament::getCurrentPanel()->getAuthGuard())->user()?->hasPermissionTo('cash_flows.delete', Filament::getCurrentPanel()->getAuthGuard())),
        ];
    }

    protected static function partySummary(CashFlow $record): array
    {
        static $cache = [];

        $cacheKey = $record->party_type.':'.$record->party_id;
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $entries = CashFlow::query()
            ->withoutTrashed()
            ->whereNull('settlement_for_id')
            ->where('merchant_id', $record->merchant_id)
            ->where('party_type', $record->party_type)
            ->where('party_id', $record->party_id)
            ->with(['settlements' => fn ($q) => $q->withoutTrashed()])
            ->get();

        $payable = $entries->where('flow_type', 'advance');
        $receivable = $entries->where('flow_type', 'loan');

        $payableTotal = round((float) $payable->sum('amount'), 2);
        $payableSettled = round((float) $payable->sum(fn (CashFlow $e) => self::settledAmount($e)), 2);

        $receivableTotal = round((float) $receivable->sum('amount'), 2);
        $receivableSettled = round((float) $receivable->sum(fn (CashFlow $e) => self::settledAmount($e)), 2);

        return $cache[$cacheKey] = [
            'entries_count' => $entries->count(),
            'payable_total' => $payableTotal,
            'payable_settled' => $payableSettled,
            'payable_remaining' => max(0, round($payableTotal - $payableSettled, 2)),
            'receivable_total' => $receivableTotal,
            'receivable_settled' => $receivableSettled,
            'receivable_remaining' => max(0, round($receivableTotal - $receivableSettled, 2)),
        ];
    }

    protected static function settledAmount(CashFlow $record): float
    {
        if (! $record->isPrimaryTransaction()) {
            return 0.0;
        }

        if ($record->relationLoaded('settlements')) {
            return round((float) $record->settlements->sum('amount'), 2);
        }

        return round((float) $record->settlements()->withoutTrashed()->sum('amount'), 2);
    }

    protected static function remainingAmount(CashFlow $record): float
    {
        if (! $record->isPrimaryTransaction()) {
            return 0.0;
        }

        $remaining = round((float) $record->amount - self::settledAmount($record), 2);

        return max(0, $remaining);
    }

    protected static function merchantLogoDataUri(?string $merchantId): ?string
    {
        if (! $merchantId || ! extension_loaded('gd')) {
            return null;
        }

        $merchant = Merchant::query()
            ->with('logo')
            ->find($merchantId);

        $logoPath = $merchant?->logo?->photo_url;

        if (! filled($logoPath) || ! Storage::disk('public')->exists($logoPath)) {
            return null;
        }

        try {
            $absolutePath = Storage::disk('public')->path($logoPath);
            $contents = file_get_contents($absolutePath);

            if ($contents === false) {
                return null;
            }

            $mime = mime_content_type($absolutePath) ?: 'image/png';

            return 'data:'.$mime.';base64,'.base64_encode($contents);
        } catch (\Throwable) {
            return null;
        }
    }
}
