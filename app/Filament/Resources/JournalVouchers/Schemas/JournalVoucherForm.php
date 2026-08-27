<?php

namespace App\Filament\Resources\JournalVouchers\Schemas;

use App\Filament\Resources\Vendors\VendorResource;
use App\Models\LedgerAccount;
use App\Models\Vendor;
use App\Services\Finance\FinanceLedger;
use App\Support\FinanceAccess;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
                        ->displayFormat('d/m/Y'),
                    Textarea::make('narration')
                        ->label('Narration')
                        ->rows(2)
                        ->columnSpanFull(),
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
        $payable = $ledger->accountByCode($merchantId, '2000');
        $payFromCode = ($get('payment_method') ?? 'cash') === 'bank'
            ? FinanceLedger::BANK_ACCOUNT_CODE
            : FinanceLedger::CASH_ACCOUNT_CODE;
        $payFrom = $ledger->accountByCode($merchantId, $payFromCode);

        $vendorName = Vendor::query()->whereKey($vendorId)->value('name') ?? 'Vendor';

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

        if (blank($get('narration'))) {
            $set('narration', "Vendor payment — {$vendorName}");
        }
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
                $account->id => $account->name,
            ])
            ->all();
    }
}
