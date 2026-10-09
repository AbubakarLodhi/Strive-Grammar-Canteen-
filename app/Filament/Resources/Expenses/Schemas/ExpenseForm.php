<?php

namespace App\Filament\Resources\Expenses\Schemas;

use App\Models\Branch;
use App\Models\LedgerAccount;
use App\Models\Merchant;
use App\Models\User;
use App\Services\Finance\FinanceLedger;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class ExpenseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Expense Information')
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('expense_no')
                        ->label('Expense Number')
                        ->default(fn () => 'EXP-'.date('Ymd').'-'.strtoupper(substr(uniqid(), -6)))
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true),

                    DatePicker::make('expense_date')
                        ->label('Expense Date')
                        ->default(now())
                        ->required()
                        ->displayFormat('d/m/Y'),

                    TextInput::make('expense_account_name')
                        ->label('Expense account')
                        ->required()
                        ->maxLength(255)
                        ->dehydrated()
                        ->helperText('Type the expense name. It will be created in the ledger if it does not exist yet.'),

                    Hidden::make('expense_account_id')
                        ->dehydrated(),

                    Select::make('paid_from_account_id')
                        ->label('Paid from')
                        ->required()
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->options(fn (): array => self::paidFromAccountOptions())
                        ->helperText('Cash or bank account that paid this expense.'),

                    Hidden::make('merchant_id')
                        ->default(fn () => match (true) {
                            Filament::auth()->user() instanceof Merchant => Filament::auth()->user()->id,
                            Filament::auth()->user() instanceof User => Filament::auth()->user()->merchant_id,
                            default => null,
                        })
                        ->required(),

                    //                    Select::make('business_id')
                    //                        ->label('Business')
                    //                        ->relationship(
                    //                            'business',
                    //                            'name',
                    //                            function (Builder $query) {
                    //                                $user = Filament::auth()->user();
                    //                                $query->where('status', true);
                    //                                $merchantId = match (true) {
                    //                                    $user instanceof \App\Models\Merchant => $user->id,
                    //                                    $user instanceof \App\Models\User     => $user->merchant_id,
                    //                                    default                               => null,
                    //                                };
                    //
                    //                                if (! $merchantId) {
                    //                                    $query->whereRaw('1 = 0');
                    //                                    return;
                    //                                }
                    //
                    //                                $query->where('merchant_id', $merchantId);
                    //
                    //                                // 🔵 Staff → assigned businesses only
                    //                                if ($user instanceof \App\Models\User) {
                    //                                    $query->whereHas('users', fn ($q) =>
                    //                                    $q->where('users.id', $user->id)
                    //                                    );
                    //                                }
                    //                            }
                    //                        )
                    //                        ->searchable()
                    //                        ->preload()
                    //                        ->required()
                    //                        ->reactive()
                    //                        ->live()
                    //                        ->afterStateUpdated(function (callable $set,$livewire){
                    //                            $set('branch_id', null);
                    //                            $livewire->resetValidation('data.business_id');
                    //                            $livewire->resetErrorBag('data.business_id');
                    //                        }),

                    Select::make('branch_id')
                        ->label('Branch')
                        ->searchable()
                        ->required()
                        ->reactive()
                        ->allowHtml() // ✅ enables indentation
                        ->options(function (): array {

                            $user = Filament::auth()->user();

                            $query = Branch::query()
                                ->withoutTrashed()
                                ->with('business')
                                ->where('is_active', true);

                            // Merchant → all branches
                            if ($user instanceof Merchant) {
                                $query->where('merchant_id', $user->id);
                            }

                            // Staff → assigned branches only
                            if ($user instanceof User) {
                                $query->whereIn(
                                    'branches.id',
                                    $user->branches()->pluck('branches.id')
                                );
                            }

                            return $query
                                ->orderBy('business_id')
                                ->orderBy('branches.name')
                                ->get()
                                ->groupBy(fn ($branch) => $branch->business?->name ?? 'Other')
                                ->map(fn ($group) => $group->pluck('name', 'id')
                                    ->map(fn ($name) => '&nbsp;&nbsp;&nbsp;&nbsp;'.e($name))
                                    ->toArray()
                                )
                                ->toArray();
                        }),

                    Hidden::make('created_by')
                        ->default(fn () => Filament::auth()->id()),
                ]),

            Section::make('Expense Items')
                ->extraAttributes(['class' => 'line-items-section'])
                ->columnSpanFull()
                ->schema([
                    Repeater::make('items')
                        ->schema([
                            TextInput::make('description')
                                ->label('Description')
                                ->required()
                                ->maxLength(255)
                                ->columnSpan(2)
                                ->live()
                                ->afterStateUpdated(function ($state, callable $set, callable $get, $livewire) {
                                    $livewire->resetValidation('data.items.*.description');
                                    $livewire->resetErrorBag('data.items.*.description');
                                }),

                            TextInput::make('quantity')
                                ->label('Quantity')
                                ->inputMode('numeric')
                                ->rule('numeric')
                                ->extraInputAttributes(['data-line-field' => 'quantity'])
                                ->required()
                                ->default(1)
                                ->minValue(1)
                                ->live()
                                ->afterStateUpdated(function ($state, callable $set, callable $get) {
                                    if ($state === null || $state === '' || ! is_numeric($state)) {
                                        $set('line_total', 0);
                                        self::recalcTotals($set, $get);

                                        return;
                                    }
                                    // ✅ Clamp quantity to minimum 1
                                    $qty = max(1, (float) ($state ?? 1));

                                    $unit = (float) ($get('unit_price') ?? 0);

                                    $set('line_total', $unit * $qty);

                                    self::recalcTotals($set, $get);
                                }),

                            TextInput::make('unit_price')
                                ->label('Unit Price')
                                ->inputMode('decimal')
                                ->rule('numeric')
                                ->extraInputAttributes(['data-line-field' => 'unit_price'])
                                ->required()
                                ->default(0)
                                ->minValue(0)
                                ->live(onBlur: true)
                                ->afterStateUpdated(function ($state, callable $set, callable $get) {
                                    if ($state === null || $state === '') {
                                        self::recalcTotals($set, $get);

                                        return;
                                    }

                                    $raw = trim((string) $state);

                                    // Allow in-progress decimal input (e.g. "12.")
                                    if (! preg_match('/^\d*\.?\d*$/', $raw)) {
                                        return;
                                    }

                                    $unit = max(0, (float) $raw);
                                    $qty = (float) ($get('quantity') ?? 1);

                                    $set('line_total', $unit * $qty);

                                    self::recalcTotals($set, $get);
                                }),

                            TextInput::make('line_total')
                                ->label('Line Total')
                                ->numeric()
                                ->disabled()
                                ->dehydrated()
                                ->extraInputAttributes(['data-line-field' => 'line_total'])
                                ->default(0),
                        ])
                        ->columns(4)
                        ->defaultItems(1)
                        ->minItems(1)
                        ->collapsible()
                        ->itemLabel('Item')
                        ->itemNumbers()
                        ->addActionLabel('Add Item')
                        ->reorderable(false)
                        ->deletable(true)
                        ->afterStateUpdated(function (callable $set, callable $get) {
                            self::recalcTotals($set, $get);
                        }),
                ]),

            Section::make('Summary')
                ->columns(4)
                ->columnSpanFull()
                ->schema([
                    Placeholder::make('subtotal_display')
                        ->label('Subtotal')
                        ->content(fn (callable $get): string => number_format((float) ($get('subtotal') ?? 0), 2)),

                    TextInput::make('discount')
                        ->label('Discount')
                        ->numeric()
                        ->default(0)
                        ->minValue(0)
                        ->reactive()
                        ->debounce(300)
                        ->afterStateUpdated(fn ($state, callable $set, callable $get) => self::recalcTotals($set, $get)),

                    TextInput::make('tax')
                        ->label('Tax')
                        ->numeric()
                        ->default(0)
                        ->minValue(0)
                        ->reactive()
                        ->debounce(300)
                        ->afterStateUpdated(fn ($state, callable $set, callable $get) => self::recalcTotals($set, $get)),

                    Placeholder::make('total_amount_display')
                        ->label('Total Amount')
                        ->content(fn (callable $get): string => number_format((float) ($get('total_amount') ?? 0), 2)),

                    Hidden::make('subtotal')->default(0)->dehydrated(),
                    Hidden::make('total_amount')->default(0)->dehydrated(),
                ]),

            Section::make('Notes')
                ->columnSpanFull()
                ->schema([
                    Textarea::make('notes')
                        ->label('Notes')
                        ->rows(3)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    private static function recalcTotals(callable $set, callable $get): void
    {
        $items = $get('items') ?? [];
        $subtotal = collect($items)->sum(function ($item) {
            $qty = is_numeric($item['quantity'] ?? null) ? (float) ($item['quantity'] ?? 0) : 0;
            $unit = is_numeric($item['unit_price'] ?? null) ? (float) ($item['unit_price'] ?? 0) : 0;

            return max(0, $qty) * max(0, $unit);
        });

        $discount = (float) ($get('discount') ?? 0);
        $tax = (float) ($get('tax') ?? 0);

        $set('subtotal', $subtotal);
        $set('total_amount', $subtotal - $discount + $tax);
    }

    /**
     * @return array<string, string>
     */
    private static function paidFromAccountOptions(): array
    {
        $merchantId = self::merchantId();
        if (! $merchantId) {
            return [];
        }

        return LedgerAccount::query()
            ->where('merchant_id', $merchantId)
            ->where('is_active', true)
            ->where(function ($q): void {
                $q->where('code', FinanceLedger::CASH_ACCOUNT_CODE)
                    ->orWhere('is_bank', true);
            })
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn ($account) => [
                $account->id => $account->is_bank
                    ? $account->bankLabel()
                    : trim(($account->code ? $account->code.' — ' : '').$account->name),
            ])
            ->all();
    }

    private static function merchantId(): ?string
    {
        $user = Filament::auth()->user();

        return match (true) {
            $user instanceof Merchant => $user->id,
            $user instanceof User => $user->merchant_id,
            default => null,
        };
    }
}
