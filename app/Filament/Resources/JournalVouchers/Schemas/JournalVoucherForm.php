<?php

namespace App\Filament\Resources\JournalVouchers\Schemas;

use App\Filament\Resources\Vendors\VendorResource;
use App\Models\JournalVoucher;
use App\Models\LedgerAccount;
use App\Models\Purchase;
use App\Models\Vendor;
use App\Services\Finance\FinanceLedger;
use App\Services\Finance\OperationalLedgerPoster;
use App\Services\Inventory\CanteenStockImporter;
use App\Support\FinanceAccess;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class JournalVoucherForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Hidden::make('merchant_id')
                ->default(fn () => FinanceAccess::merchantId())
                ->required(),

            Section::make('Voucher')
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('voucher_no')
                        ->label('Voucher No')
                        ->default(fn (): string => app(FinanceLedger::class)->nextVoucherNo((string) FinanceAccess::merchantId()))
                        ->required()
                        ->maxLength(50),
                    DatePicker::make('voucher_date')
                        ->label('Date')
                        ->default(now())
                        ->required()
                        ->live()
                        ->displayFormat('d/m/Y')
                        ->afterStateUpdated(function (?string $state, Set $set, Get $get, ?JournalVoucher $record): void {
                            if ($record) {
                                return;
                            }

                            $merchantId = (string) ($get('merchant_id') ?: FinanceAccess::merchantId());
                            if ($merchantId === '' || ! filled($state)) {
                                return;
                            }

                            $set('voucher_no', app(FinanceLedger::class)->nextVoucherNo($merchantId, $state));
                        }),
                    Textarea::make('narration')
                        ->label('Narration')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),

            Section::make('From Purchase')
                ->description('Optional. Select a purchase to auto-fill Inventory and vendor payable / cash lines. Purchases that already have a journal voucher are hidden so the same purchase is not posted twice.')
                ->columns(1)
                ->columnSpanFull()
                ->schema([
                    Select::make('purchase_id')
                        ->label('Purchase')
                        ->searchable()
                        ->preload()
                        ->nullable()
                        ->dehydrated()
                        ->options(fn (): array => self::purchaseOptions())
                        ->live()
                        ->afterStateUpdated(fn (Get $get, Set $set) => self::applyPurchaseLines($get, $set)),
                ]),

            Section::make('Vendor Payment')
                ->description('Optional. Select a vendor and payment amount to auto-fill Accounts Payable and Cash/Bank lines.')
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    Select::make('vendor_id')
                        ->label('Vendor')
                        ->searchable()
                        ->preload()
                        ->nullable()
                        ->options(fn (): array => self::vendorOptions())
                        ->live()
                        ->afterStateUpdated(fn (Get $get, Set $set) => self::applyVendorPaymentLines($get, $set)),
                    Select::make('payment_method')
                        ->label('Pay From')
                        ->options([
                            'cash' => 'Cash in Hand',
                            'bank' => 'Bank (UBL)',
                        ])
                        ->default('cash')
                        ->dehydrated(false)
                        ->live()
                        ->afterStateUpdated(fn (Get $get, Set $set) => self::applyVendorPaymentLines($get, $set)),
                    TextInput::make('payment_amount')
                        ->label('Payment Amount')
                        ->numeric()
                        ->minValue(0)
                        ->step(0.01)
                        ->prefix('PKR')
                        ->dehydrated(false)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Get $get, Set $set) => self::applyVendorPaymentLines($get, $set)),
                ]),

            Section::make('Entries')
                ->columnSpanFull()
                ->schema([
                    Repeater::make('lines')
                        ->label('Journal Lines')
                        ->minItems(2)
                        ->defaultItems(2)
                        ->columns(12)
                        ->schema([
                            Select::make('ledger_account_id')
                                ->label('Account')
                                ->options(fn () => self::accountOptions())
                                ->searchable()
                                ->required()
                                ->columnSpan(5),
                            TextInput::make('description')
                                ->label('Description')
                                ->maxLength(255)
                                ->columnSpan(3),
                            TextInput::make('debit')
                                ->label('Debit')
                                ->numeric()
                                ->default(0)
                                ->minValue(0)
                                ->step(0.01)
                                ->columnSpan(2),
                            TextInput::make('credit')
                                ->label('Credit')
                                ->numeric()
                                ->default(0)
                                ->minValue(0)
                                ->step(0.01)
                                ->columnSpan(2),
                        ])
                        ->required(),
                ]),
        ]);
    }

    private static function applyPurchaseLines(Get $get, Set $set): void
    {
        $purchaseId = $get('purchase_id');

        if (blank($purchaseId)) {
            return;
        }

        $merchantId = FinanceAccess::merchantId();

        if (! $merchantId) {
            return;
        }

        $purchase = Purchase::query()
            ->with(['vendor', 'payments'])
            ->where('merchant_id', $merchantId)
            ->whereKey($purchaseId)
            ->first();

        if (! $purchase || CanteenStockImporter::isOpeningStockPurchase($purchase)) {
            return;
        }

        $existing = app(FinanceLedger::class)->findVoucherForPurchase($purchase);

        if ($existing) {
            $set('purchase_id', null);
            $set('lines', []);
            $set('payment_amount', null);

            Notification::make()
                ->title('Purchase already journaled')
                ->body("{$purchase->purchase_no} already has voucher {$existing->voucher_no}. Creating another would double the amount in the General Ledger.")
                ->warning()
                ->send();

            return;
        }

        $ledger = app(FinanceLedger::class);
        $poster = app(OperationalLedgerPoster::class);
        $vendorName = trim((string) ($purchase->vendor?->name ?? ''));
        $payableCode = '2000';

        if ($purchase->vendor) {
            $payableCode = $ledger->ensureVendorPayableAccount($purchase->vendor)->code;
            $set('vendor_id', $purchase->vendor_id);
        }

        $plan = $poster->purchaseLinePlan(
            (float) $purchase->total_amount,
            (float) $purchase->paid_amount,
            (float) $purchase->due_amount,
            $poster->isBankMethod(
                (string) $purchase->payments->pluck('method')->filter()->implode(' ')
            ),
            $vendorName !== '' ? $vendorName : null,
            $payableCode,
            $purchase->purchase_no,
        );

        $lines = [];

        foreach ($plan as $line) {
            $account = $ledger->accountByCode($merchantId, $line['code']);
            $lines[] = [
                'ledger_account_id' => $account->id,
                'description' => $line['description'],
                'debit' => $line['debit'],
                'credit' => $line['credit'],
            ];
        }

        if ($lines === []) {
            return;
        }

        $set('lines', $lines);
        $set('voucher_date', optional($purchase->purchase_date)?->toDateString() ?? now()->toDateString());
        $set(
            'narration',
            'Purchase '.$purchase->purchase_no.($vendorName !== '' ? ' — '.$vendorName : '')
        );
        $set('payment_amount', null);
    }

    private static function applyVendorPaymentLines(Get $get, Set $set): void
    {
        $amount = round((float) ($get('payment_amount') ?? 0), 2);
        $vendorId = $get('vendor_id');

        if ($amount <= 0 || blank($vendorId)) {
            return;
        }

        $merchantId = FinanceAccess::merchantId();

        if (! $merchantId) {
            return;
        }

        $ledger = app(FinanceLedger::class);
        $vendor = Vendor::query()->withTrashed()->find($vendorId);

        if (! $vendor) {
            return;
        }

        $payable = $ledger->ensureVendorPayableAccount($vendor);
        $payFromCode = ($get('payment_method') ?? 'cash') === 'bank'
            ? FinanceLedger::BANK_ACCOUNT_CODE
            : FinanceLedger::CASH_ACCOUNT_CODE;
        $payFrom = $ledger->accountByCode($merchantId, $payFromCode);

        $vendorName = trim((string) $vendor->name) !== '' ? $vendor->name : 'Vendor';

        $set('purchase_id', null);
        $set('lines', [
            [
                'ledger_account_id' => $payable->id,
                'description' => "Payment to {$vendorName}",
                'debit' => $amount,
                'credit' => 0,
            ],
            [
                'ledger_account_id' => $payFrom->id,
                'description' => "Payment to {$vendorName}",
                'debit' => 0,
                'credit' => $amount,
            ],
        ]);

        if (blank($get('narration')) || str_starts_with((string) $get('narration'), 'Purchase ')) {
            $set('narration', "Vendor payment — {$vendorName}");
        }
    }

    /**
     * @return array<string, string>
     */
    private static function purchaseOptions(): array
    {
        $merchantId = FinanceAccess::merchantId();

        if (! $merchantId) {
            return [];
        }

        $already = app(FinanceLedger::class)->purchaseKeysAlreadyJournaled($merchantId);

        return Purchase::query()
            ->with('vendor')
            ->where('merchant_id', $merchantId)
            ->where('purchase_no', '!=', CanteenStockImporter::OPENING_PURCHASE_NO)
            ->orderByDesc('purchase_date')
            ->limit(200)
            ->get()
            ->reject(function (Purchase $purchase) use ($already): bool {
                return in_array((string) $purchase->id, $already['ids'], true)
                    || in_array((string) $purchase->purchase_no, $already['numbers'], true);
            })
            ->mapWithKeys(function (Purchase $purchase): array {
                $vendor = $purchase->vendor?->name ?: 'No vendor';
                $amount = number_format((float) $purchase->total_amount, 2);

                return [
                    $purchase->id => "{$purchase->purchase_no} — {$vendor} (PKR {$amount})",
                ];
            })
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function vendorOptions(): array
    {
        $user = Filament::auth()->user();

        return VendorResource::scopeVisibleVendors(Vendor::query(), $user)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function accountOptions(): array
    {
        $merchantId = FinanceAccess::merchantId();

        if (! $merchantId) {
            return [];
        }

        return LedgerAccount::query()
            ->where('merchant_id', $merchantId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (LedgerAccount $account): array => [
                $account->id => $account->code.' — '.$account->name,
            ])
            ->all();
    }
}
