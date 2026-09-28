<?php

namespace App\Filament\Resources\CashFlows\Schemas;

use App\Filament\Resources\CashFlows\CashFlowResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Vendors\VendorResource;
use App\Models\Branch;
use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\LedgerAccount;
use App\Models\Merchant;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Finance\FinanceLedger;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CashFlowForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Cash Flow')
                ->columnSpanFull()
                ->columns(3)
                ->schema([
                    Hidden::make('merchant_id')
                        ->default(fn () => match (true) {
                            Filament::auth()->user() instanceof Merchant => Filament::auth()->user()->id,
                            Filament::auth()->user() instanceof User => Filament::auth()->user()->merchant_id,
                            default => null,
                        })
                        ->required(),

                    Hidden::make('created_by')
                        ->default(fn () => Filament::auth()->user() instanceof User ? Filament::auth()->id() : null),

                    Hidden::make('business_id'),

                    Select::make('party_type')
                        ->label('Party Type')
                        ->options([
                            Customer::class => 'Customer',
                            Vendor::class => 'Vendor',
                        ])
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (callable $set): void {
                            $set('party_id', null);
                            $set('branch_id', null);
                            $set('business_id', null);
                        })
                        ->columnSpan(1),

                    Select::make('party_id')
                        ->label('Party')
                        ->required()
                        ->searchable()
                        ->preload()
                        ->options(function (callable $get): array {
                            $partyType = $get('party_type');

                            if ($partyType === Customer::class) {
                                return CustomerResource::scopeVisibleCustomers(
                                    Customer::query()->withoutTrashed(),
                                    Filament::auth()->user(),
                                )
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->toArray();
                            }

                            if ($partyType === Vendor::class) {
                                return VendorResource::scopeVisibleVendors(
                                    Vendor::query()->withoutTrashed(),
                                    Filament::auth()->user(),
                                )
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->toArray();
                            }

                            return [];
                        })
                        ->live()
                        ->afterStateUpdated(function (callable $get, callable $set, $state): void {
                            $branchOptions = self::partyBranchOptions($get('party_type'), $state);
                            $branchIds = array_keys($branchOptions);

                            if (count($branchIds) === 1) {
                                $set('branch_id', $branchIds[0]);
                                $set('business_id', Branch::query()->whereKey($branchIds[0])->value('business_id'));

                                return;
                            }

                            $set('branch_id', null);
                            $set('business_id', null);
                        })
                        ->columnSpan(1),

                    Select::make('branch_id')
                        ->label('Branch')
                        ->required()
                        ->searchable()
                        ->preload()
                        ->disabled(fn (callable $get): bool => blank($get('party_type')) || blank($get('party_id')))
                        ->options(fn (callable $get): array => self::partyBranchOptions(
                            $get('party_type'),
                            $get('party_id'),
                        ))
                        ->live()
                        ->afterStateUpdated(function (callable $set, $state): void {
                            $set(
                                'business_id',
                                filled($state)
                                    ? Branch::query()->whereKey($state)->value('business_id')
                                    : null
                            );
                        })
                        ->columnSpan(1),

                    Select::make('flow_type')
                        ->label('Account Type')
                        ->options(CashFlow::flowTypeLabels())
                        ->required()
                        ->columnSpan(1),

                    TextInput::make('amount')
                        ->label('Amount')
                        ->numeric()
                        ->required()
                        ->minValue(0.01)
                        ->columnSpan(1),

                    DatePicker::make('flow_date')
                        ->label('Flow Date')
                        ->required()
                        ->default(now())
                        ->displayFormat('d/m/Y')
                        ->columnSpan(1),

                    Select::make('method')
                        ->label('Method')
                        ->options(CashFlow::methodLabels())
                        ->default('Cash')
                        ->required()
                        ->live()
                        ->native(false)
                        ->afterStateUpdated(function (callable $set): void {
                            $set('ledger_account_id', null);
                        })
                        ->columnSpan(1),

                    Select::make('ledger_account_id')
                        ->label('Cash / bank account')
                        ->required()
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->options(fn (callable $get): array => self::cashBankAccountOptions($get('method')))
                        ->helperText('Account used to receive or pay this cash flow.')
                        ->columnSpan(1),

                    Textarea::make('notes')
                        ->label('Notes')
                        ->columnSpanFull()
                        ->rows(4),
                ]),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public static function cashBankAccountOptions(?string $method): array
    {
        $merchantId = match (true) {
            Filament::auth()->user() instanceof Merchant => Filament::auth()->user()->id,
            Filament::auth()->user() instanceof User => Filament::auth()->user()->merchant_id,
            default => null,
        };

        if (! $merchantId) {
            return [];
        }

        $query = LedgerAccount::query()
            ->where('merchant_id', $merchantId)
            ->where('is_active', true);

        if ($method === 'Bank') {
            $query->where('is_bank', true);
        } else {
            $query->where('code', FinanceLedger::CASH_ACCOUNT_CODE);
        }

        return $query
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (LedgerAccount $account) => [
                $account->id => $account->is_bank
                    ? $account->bankLabel()
                    : (($account->code ? $account->code.' — ' : '').$account->name),
            ])
            ->all();
    }

    protected static function partyBranchOptions(?string $partyType, ?string $partyId): array
    {
        if (! filled($partyType) || ! filled($partyId)) {
            return [];
        }

        $query = CashFlowResource::accessibleBranchesQuery(Filament::auth()->user());

        if ($partyType === Customer::class) {
            $query->whereHas('customers', fn ($query) => $query->where('customers.id', $partyId));
        } elseif ($partyType === Vendor::class) {
            $query->whereHas('vendors', fn ($query) => $query->where('vendors.id', $partyId));
        } else {
            return [];
        }

        return $query
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }
}
