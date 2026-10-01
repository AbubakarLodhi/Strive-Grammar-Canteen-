<?php

namespace App\Filament\Resources\Purchases\Schemas;

use App\Filament\Resources\Vendors\Schemas\VendorForm;
use App\Filament\Resources\Vendors\VendorResource;
use App\Models\Branch;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Finance\FinanceLedger;
use App\Services\Inventory\CanteenStockImporter;
use App\Services\PaymentLedgerService;
use App\Support\ProductStockAvailability;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class PurchaseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Grid::make(2)
                ->columnSpanFull()
                ->schema([

                    /* ===========================
                     * PURCHASE INFORMATION
                     * =========================== */
                    Section::make('Purchase Information')
                        ->extraAttributes(['class' => 'blue-section'])
                        ->schema([
                            TextInput::make('purchase_no')
                                ->label('Purchase Number')
                                ->default(fn () => 'PUR-'.date('Ymd').'-'.strtoupper(substr(uniqid(), -6)))
                                ->required()
                                ->maxLength(255)
                                ->unique(ignoreRecord: true)
                                ->live()
                                ->afterStateUpdated(function ($state, callable $set, callable $get, $livewire) {
                                    $livewire->resetValidation('data.purchase_no');
                                    $livewire->resetErrorBag('data.purchase_no');
                                })
                                ->disabled()
                                ->dehydrated(),

                            DatePicker::make('purchase_date')
                                ->label('Purchase Date')
                                ->default(now())
                                ->required()
                                ->displayFormat('d/m/Y')
                                ->live()
                                ->afterStateUpdated(function ($state, callable $set, callable $get, $livewire) {
                                    $livewire->resetValidation('data.purchase_date');
                                    $livewire->resetErrorBag('data.purchase_date');
                                })
                                ->disabled(fn (callable $get) => (bool) ($get('is_partial_return') ?? false)),

                            Select::make('vendor_id')
                                ->label('Vendor')
                                ->relationship(
                                    'activeVendor',
                                    'name',
                                    fn (Builder $query, callable $get) => VendorResource::scopeVisibleVendors(
                                        $query->withoutTrashed(),
                                        Filament::auth()->user(),
                                        self::selectedVendorBranchIds($get),
                                    )
                                )
                                ->searchable()
                                ->preload()
                                ->required()
                                ->suffixAction(
                                    Action::make('createVendor')
                                        ->icon('heroicon-s-plus')
                                        ->tooltip('Create Vendor')
                                        ->modalHeading('Create Vendor')
                                        ->modalSubmitActionLabel('Create')
                                        ->modalWidth('lg')
                                        ->model(Vendor::class)
                                        ->form(VendorForm::components())
                                        ->action(function (array $data, callable $set): void {
                                            $branchIds = array_values($data['branch_ids'] ?? []);
                                            unset($data['branch_ids']);

                                            $email = $data['email'] ?? null;

                                            if (filled($email)) {
                                                $normalizedEmail = mb_strtolower(trim((string) $email));

                                                $alreadyExists = Vendor::query()
                                                    ->where('merchant_id', $data['merchant_id'])
                                                    ->whereNull('deleted_at')
                                                    ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
                                                    ->exists();

                                                if ($alreadyExists) {
                                                    throw ValidationException::withMessages([
                                                        'email' => 'Vendor with this email already exists. Kindly use another email.',
                                                    ]);
                                                }
                                            }

                                            $vendor = DB::transaction(function () use ($data, $branchIds) {
                                                $vendor = Vendor::create($data);

                                                VendorResource::syncVendorBranches(
                                                    $vendor,
                                                    $branchIds,
                                                    Filament::auth()->user(),
                                                );

                                                if (! CanteenStockImporter::isOpeningStockVendor($vendor)) {
                                                    app(FinanceLedger::class)
                                                        ->ensureVendorPayableAccount($vendor);
                                                }

                                                return $vendor;
                                            });

                                            $set('vendor_id', $vendor->id);
                                        })
                                )
                                ->live()
                                ->afterStateUpdated(fn ($_, $__, $___, $livewire) => (
                                    $livewire->resetValidation('data.vendor_id') ||
                                    $livewire->resetErrorBag('data.vendor_id')
                                ))
                                ->disabled(fn (callable $get) => (bool) ($get('is_partial_return') ?? false)),

                            Hidden::make('payment_type')
                                ->default('cash')
                                ->dehydrated(true),

                            Hidden::make('is_partial_return')
                                ->default(false)
                                ->dehydrated(false),

                            Hidden::make('paginated_items_mode')
                                ->default(false)
                                ->dehydrated(),

                            Hidden::make('items_page')
                                ->default(1)
                                ->dehydrated(),

                            Hidden::make('items_per_page')
                                ->default(25)
                                ->dehydrated(false),

                            Hidden::make('items_total')
                                ->default(0)
                                ->dehydrated(false),

                            Hidden::make('items_last_page')
                                ->default(1)
                                ->dehydrated(false),

                            Hidden::make('merchant_id')
                                ->default(fn () => self::merchantId())
                                ->required(),
                        ]),

                    /* ===========================
                     * MERCHANT CARD / LOGO
                     * =========================== */
                    Grid::make(1)
                        ->extraAttributes([
                            'class' => 'h-full flex items-center justify-center',
                        ])
                        ->schema([
                            FileUpload::make('merchant_logo')
                                ->label('')
                                ->extraAttributes(['class' => 'merchant-logo-center'])
                                ->image()
                                ->disk('public')
                                ->directory('merchants/logos')
                                ->imagePreviewHeight(140)
                                ->visible(fn () => ! self::merchantHasLogo())
                                ->dehydrated(false)
                                ->disabled(fn (callable $get) => (bool) ($get('is_partial_return') ?? false)),

                            View::make('filament.pages.merchant-card')
                                ->extraAttributes(['class' => 'merchant-logo-center'])
                                ->visible(fn () => self::merchantHasLogo()),
                        ]),
                ]),

            /* ===========================
             * PURCHASE ITEMS
             * =========================== */
            Section::make('Purchase Items')
                ->extraAttributes(['class' => 'line-items-section'])
                ->columnSpanFull()
                ->headerActions([
                    Action::make('usePercentMode')
                        ->label('Percent')
                        ->extraAttributes(fn (callable $get) => [
                            'class' => 'discount-mode-toggle left'.(($get('discount_mode') ?? 'percent') === 'percent' ? ' is-active' : ''),
                        ])
                        ->disabled(fn (callable $get) => (bool) ($get('is_partial_return') ?? false) || ($get('discount_mode') ?? 'percent') === 'percent'
                        )
                        ->action(function (callable $set, callable $get) {
                            $items = $get('items') ?? [];

                            foreach ($items as &$item) {
                                $lineSubtotal = (float) ($item['line_subtotal'] ?? $item['line_total'] ?? 0);
                                $discountAmountInput = (float) ($item['discount_amount'] ?? 0);
                                $discountAmount = min(max(0, $discountAmountInput), $lineSubtotal);
                                $discountRate = $lineSubtotal > 0 ? ($discountAmount / $lineSubtotal) * 100 : 0;
                                $taxableAmount = max(0, $lineSubtotal - $discountAmount);
                                $taxAmountInput = (float) ($item['tax_amount'] ?? 0);
                                $taxAmount = min(max(0, $taxAmountInput), $lineSubtotal);
                                $taxRate = $taxableAmount > 0 ? ($taxAmount / $taxableAmount) * 100 : 0;

                                $item['line_subtotal'] = $lineSubtotal;
                                $item['discount'] = round(min(100, $discountRate), 6);
                                $item['tax'] = round(min(100, $taxRate), 6);
                                $item['discount_amount'] = round($discountAmount, 2);
                                $item['tax_amount'] = round($taxAmount, 2);
                                $item['line_total'] = round($taxableAmount + $taxAmount, 2);
                            }

                            $set('items', $items);
                            $set('discount_mode', 'percent');
                        }),
                    Action::make('useAmountMode')
                        ->label('Amount')
                        ->extraAttributes(fn (callable $get) => [
                            'class' => 'discount-mode-toggle right'.(($get('discount_mode') ?? 'percent') === 'amount' ? ' is-active' : ''),
                        ])
                        ->disabled(fn (callable $get) => (bool) ($get('is_partial_return') ?? false) || ($get('discount_mode') ?? 'percent') === 'amount'
                        )
                        ->action(function (callable $set, callable $get) {
                            $items = $get('items') ?? [];

                            foreach ($items as &$item) {
                                $lineSubtotal = (float) ($item['line_subtotal'] ?? $item['line_total'] ?? 0);
                                $discountAmountInput = (float) ($item['discount_amount'] ?? 0);
                                $discountAmount = min(max(0, $discountAmountInput), $lineSubtotal);
                                $discountRate = $lineSubtotal > 0 ? ($discountAmount / $lineSubtotal) * 100 : 0;
                                $taxableAmount = max(0, $lineSubtotal - $discountAmount);
                                $taxAmountInput = (float) ($item['tax_amount'] ?? 0);
                                $taxAmount = min(max(0, $taxAmountInput), $lineSubtotal);
                                $taxRate = $taxableAmount > 0 ? ($taxAmount / $taxableAmount) * 100 : 0;

                                $item['line_subtotal'] = $lineSubtotal;
                                $item['discount'] = round(min(100, $discountRate), 6);
                                $item['tax'] = round(min(100, $taxRate), 6);
                                $item['discount_amount'] = round($discountAmount, 2);
                                $item['tax_amount'] = round($taxAmount, 2);
                                $item['line_total'] = round($taxableAmount + $taxAmount, 2);
                            }

                            $set('items', $items);
                            $set('discount_mode', 'amount');
                        }),
                    Action::make('previousItemsPage')
                        ->label('Previous')
                        ->visible(fn (callable $get) => (bool) ($get('paginated_items_mode') ?? false))
                        ->disabled(fn (callable $get) => (int) ($get('items_page') ?? 1) <= 1)
                        ->action(fn ($livewire) => $livewire->previousItemsPage()),
                    Action::make('nextItemsPage')
                        ->label('Next')
                        ->visible(fn (callable $get) => (bool) ($get('paginated_items_mode') ?? false))
                        ->disabled(fn (callable $get) => (int) ($get('items_page') ?? 1) >= (int) ($get('items_last_page') ?? 1))
                        ->action(fn ($livewire) => $livewire->nextItemsPage()),
                ])
                ->disabled(fn (callable $get) => (bool) ($get('is_partial_return') ?? false))
                ->schema([
                    Hidden::make('discount_mode')
                        ->default('percent')
                        ->dehydrated(false),

                    Placeholder::make('items_page_status')
                        ->label('Line items page')
                        ->content(fn (callable $get) => new HtmlString(
                            'Showing page '.(int) ($get('items_page') ?? 1).' of '.(int) ($get('items_last_page') ?? 1)
                            .' for '.(int) ($get('items_total') ?? 0).' line items. '
                            .'Use Previous or Next to save this page and load another set of items.'
                        ))
                        ->visible(fn (callable $get) => (bool) ($get('paginated_items_mode') ?? false)),

                    Repeater::make('items')
                        ->afterStateUpdated(function ($state, callable $set, callable $get, $livewire) {
                            $livewire->resetValidation();
                            $livewire->resetErrorBag();
                        })
                        ->schema([
                            Hidden::make('purchase_item_id')
                                ->dehydrated(),

                            //                            /* -------- BUSINESS -------- */
                            //                            Select::make('business_id')
                            //                                ->label('Business')
                            //                                ->searchable()
                            //                                ->preload()
                            //                                ->required()
                            //                                ->options(fn () =>
                            //                                \App\Models\Business::query()
                            //                                    ->where('merchant_id', self::merchantId())
                            //                                    ->orderBy('name')
                            //                                    ->pluck('name', 'id')
                            //                                    ->toArray()
                            //                                )
                            //                                ->reactive()
                            //                                ->afterStateUpdated(fn (callable $set) => [
                            //                                    $set('branch_id', null),
                            //                                    $set('product_id', null),
                            //                                    $set('product_variant_id', null),
                            //                                ]),

                            /* -------- BRANCH -------- */

                            /* -------- PRODUCT -------- */
                            Select::make('product_id')
                                ->label('Product')
                                ->searchable()
                                ->required()
                                ->live()
                                ->reactive()
                                ->preload()
                                ->options(fn (): array => self::productOptions())
                                ->getSearchResultsUsing(fn (string $search): array => self::productOptions($search))
                                ->getOptionLabelUsing(function ($value): ?string {
                                    if (! $value) {
                                        return null;
                                    }

                                    $product = Product::withTrashed()
                                        ->select(['id', 'name', 'sku', 'track_inventory', 'type'])
                                        ->find($value);

                                    if (! $product) {
                                        return (string) $value;
                                    }

                                    return self::productOptionLabel($product);
                                })
                                ->afterStateUpdated(function ($state, callable $set, callable $get, $livewire) {
                                    $livewire->resetValidation('data.items.*.product_id');
                                    $livewire->resetErrorBag('data.items.*.product_id');

                                    // Reset dependents when product changes
                                    $set('branch_id', null);
                                    $set('product_variant_id', null);
                                    $set('unit_price', 0);
                                    $set('line_subtotal', 0);
                                    $set('line_total', 0);
                                    self::recalcTotals($set, $get);

                                    if (! $state) {
                                        return;
                                    }

                                    $user = Filament::auth()->user();

                                    $branchQuery = Branch::query()
                                        ->withoutTrashed()
                                        ->where('merchant_id', self::merchantId())
                                        ->whereExists(function ($q) use ($state) {
                                            $q->selectRaw(1)
                                                ->from('branch_products')
                                                ->whereColumn('branch_products.branch_id', 'branches.id')
                                                ->where('branch_products.product_id', $state);
                                        });

                                    if ($user instanceof User) {
                                        $branchQuery->whereIn(
                                            'branches.id',
                                            $user->branches()->pluck('branches.id')
                                        );
                                    }

                                    $branchIds = $branchQuery->pluck('branches.id');

                                    // ✅ Auto-select if only one branch exists
                                    if ($branchIds->count() === 1) {
                                        $set('branch_id', $branchIds->first());
                                        $livewire->resetValidation('data.items.*.branch_id');
                                        $livewire->resetErrorBag('data.items.*.branch_id');
                                    }

                                    // Pricing
                                    $product = Product::select(['id', 'purchase_price'])->find($state);
                                    if (! $product) {
                                        return;
                                    }

                                    $qty = (float) ($get('quantity') ?? 1);
                                    $unit = (float) ($product->purchase_price ?? 0);

                                    $set('unit_price', $unit);
                                    $set('line_total', $unit * $qty);

                                    self::recalcTotals($set, $get);
                                }),

                            Select::make('branch_id')
                                ->label('Branch')
                                ->searchable()
                                ->required()
                                ->live()
                                ->reactive()
                                ->allowHtml() // ✅ required for indentation
                                ->options(function (callable $get): array {

                                    $productId = $get('product_id');
                                    if (! $productId) {
                                        return [];
                                    }

                                    $user = Filament::auth()->user();

                                    $query = Branch::query()
                                        ->withoutTrashed()
                                        ->with('business')
                                        ->where('merchant_id', self::merchantId())
                                        ->whereExists(function ($q) use ($productId) {
                                            $q->selectRaw(1)
                                                ->from('branch_products')
                                                ->whereColumn('branch_products.branch_id', 'branches.id')
                                                ->where('branch_products.product_id', $productId);
                                        });

                                    // Staff → only assigned branches
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
                                })
                                ->afterStateHydrated(function (callable $set, callable $get) {

                                    // Auto-select branch if ONLY one exists
                                    $productId = $get('product_id');
                                    if (! $productId || $get('branch_id')) {
                                        return;
                                    }

                                    $branches = Branch::query()
                                        ->withoutTrashed()
                                        ->where('merchant_id', self::merchantId())
                                        ->whereExists(function ($q) use ($productId) {
                                            $q->selectRaw(1)
                                                ->from('branch_products')
                                                ->whereColumn('branch_products.branch_id', 'branches.id')
                                                ->where('branch_products.product_id', $productId);
                                        })
                                        ->pluck('branches.id');

                                    if ($branches->count() === 1) {
                                        $set('branch_id', $branches->first());
                                    }
                                })
                                ->afterStateUpdated(function ($state, callable $set, callable $get, $livewire) {
                                    $livewire->resetValidation('data.items.*.branch_id');
                                    $livewire->resetErrorBag('data.items.*.branch_id');
                                    $set('product_variant_id', null);
                                    $set('unit_price', 0);
                                    $set('line_subtotal', 0);
                                    $set('line_total', 0);
                                    self::recalcTotals($set, $get);
                                }),

                            /* -------- VARIANT -------- */
                            Select::make('product_variant_id')
                                ->label('Product Variant')
                                ->searchable()
                                ->live()
                                ->reactive()
                                ->nullable()
                                ->placeholder('Optional')
                                ->options(function (callable $get): array {
                                    $productId = $get('product_id');

                                    if (! $productId) {
                                        return [];
                                    }

                                    return ProductVariant::query()
                                        ->withoutTrashed()
                                        ->where('product_id', $productId)
                                        ->limit(50)
                                        ->get()
                                        ->mapWithKeys(function ($variant) {
                                            $label =
                                                $variant->name
                                                ?? $variant->sku
                                                ?? substr($variant->id, 0, 8);

                                            return [$variant->id => $label];
                                        })
                                        ->all();
                                })
                                ->getOptionLabelUsing(function ($value): ?string {
                                    if (! $value) {
                                        return null;
                                    }

                                    $variant = ProductVariant::withTrashed()
                                        ->select(['id', 'name', 'sku'])
                                        ->find($value);

                                    if (! $variant) {
                                        return (string) $value;
                                    }

                                    return (string) ($variant->name ?? $variant->sku ?? $variant->id);
                                })

                                /**
                                 * 🔑 CRITICAL: Re-apply value AFTER options exist
                                 */
                                ->afterStateHydrated(function (callable $set, callable $get) {
                                    $productId = $get('product_id');
                                    $variantId = $get('product_variant_id');

                                    if (! $productId || ! $variantId) {
                                        return;
                                    }

                                    // Force Filament to re-bind value
                                    $set('product_variant_id', $variantId);
                                })

                                ->afterStateUpdated(function ($state, callable $set, callable $get, $livewire) {
                                    $livewire->resetValidation('data.items.*.product_variant_id');
                                    $livewire->resetErrorBag('data.items.*.product_variant_id');
                                    if (! $state) {
                                        $set('unit_price', 0);
                                        $set('line_subtotal', 0);
                                        $set('line_total', 0);
                                        self::recalcTotals($set, $get);

                                        return;
                                    }

                                    $variant = ProductVariant::select(['id', 'purchase_price'])->find($state);

                                    if (! $variant) {
                                        return;
                                    }

                                    $qty = (float) ($get('quantity') ?? 1);
                                    $unit = (float) ($variant->purchase_price ?? 0);

                                    $set('unit_price', $unit);
                                    $set('line_subtotal', $unit * $qty);
                                    self::updateLineTotalDisplay($set, $get);

                                    self::recalcTotals($set, $get);
                                }),

                            /* -------- QUANTITY -------- */
                            TextInput::make('quantity')
                                ->label('Quantity')
                                ->inputMode('numeric')
                                ->rule('numeric')
                                ->extraInputAttributes(['data-line-field' => 'quantity'])
                                ->required()
                                ->default(1)
                                ->minValue(1)
                                ->live(onBlur: true)
                                ->afterStateUpdated(function ($state, callable $set, callable $get, $livewire) {
                                    $livewire->resetValidation('data.items.*.quantity');
                                    $livewire->resetErrorBag('data.items.*.quantity');
                                    if ($state === null || $state === '' || ! is_numeric($state)) {
                                        return;
                                    }
                                    $qty = max(1, (float) ($state ?? 1));
                                    $unit = (float) ($get('unit_price') ?? 0);

                                    $set('line_subtotal', $unit * $qty);
                                    self::updateLineTotalDisplay($set, $get);

                                    self::recalcTotals($set, $get);
                                }),

                            /* -------- UNIT PRICE -------- */
                            TextInput::make('unit_price')
                                ->label('Unit Price')
                                ->inputMode('decimal')
                                ->rule('numeric')
                                ->extraInputAttributes(['data-line-field' => 'unit_price'])
                                ->required()
                                ->default(0)
                                ->minValue(0)
                                ->live(onBlur: true)
                                ->afterStateUpdated(function ($state, callable $set, callable $get, $livewire) {
                                    $livewire->resetValidation('data.items.*.unit_price');
                                    $livewire->resetErrorBag('data.items.*.unit_price');
                                    if ($state === null || $state === '' || ! is_numeric($state)) {
                                        return;
                                    }
                                    $unit = max(0, (float) ($state ?? 0));
                                    $qty = (float) ($get('quantity') ?? 1);

                                    $set('line_subtotal', $unit * $qty);

                                    if (($get('../../discount_mode') ?? 'percent') === 'amount') {
                                        $lineSubtotal = (float) ($get('line_subtotal') ?? ($unit * $qty));
                                        $discountAmount = (float) ($get('discount_amount') ?? 0);
                                        $taxAmount = (float) ($get('tax_amount') ?? 0);

                                        $discountAmount = min(max(0, $discountAmount), $lineSubtotal);
                                        $discountRate = $lineSubtotal > 0 ? ($discountAmount / $lineSubtotal) * 100 : 0;
                                        $taxableAmount = max(0, $lineSubtotal - $discountAmount);
                                        $taxRate = $taxableAmount > 0 ? ($taxAmount / $taxableAmount) * 100 : 0;

                                        $set('discount', round(min(100, $discountRate), 6));
                                        $set('tax', round(min(100, $taxRate), 6));
                                    }

                                    self::updateLineTotalDisplay($set, $get);
                                    self::recalcTotals($set, $get);
                                }),

                            TextInput::make('discount')
                                ->label('Discount (%)')
                                ->inputMode('decimal')
                                ->rule('numeric')
                                ->extraInputAttributes(['data-line-field' => 'discount'])
                                ->default(0)
                                ->minValue(0)
                                ->rule('max:100')
                                ->validationMessages([
                                    'max' => 'The discount (%) field must not be greater than 100.',
                                ])
                                ->step(0.01)
                                ->suffix('%')
                                ->live(onBlur: true)
                                ->afterStateHydrated(function ($state, callable $set) {
                                    if ($state === null || $state === '') {
                                        $set('discount', 0);

                                        return;
                                    }
                                    $set('discount', (float) $state);
                                })
                                ->afterStateUpdated(function ($state, callable $set, callable $get, $livewire) {
                                    $livewire->resetValidation('data.items.*.discount');
                                    $livewire->resetErrorBag('data.items.*.discount');
                                    if ($state === null || $state === '' || ! is_numeric($state)) {
                                        return;
                                    }
                                    self::updateLineTotalDisplay($set, $get);
                                    self::recalcTotals($set, $get);
                                })
                                ->dehydrateStateUsing(fn ($state) => $state === null || $state === '' ? 0 : $state)
                                ->visible(fn (callable $get) => ($get('../../discount_mode') ?? 'percent') !== 'amount'),

                            TextInput::make('discount_amount')
                                ->label('Discount (PKR)')
                                ->inputMode('decimal')
                                ->rule('numeric')
                                ->extraInputAttributes(['data-line-field' => 'discount_amount'])
                                ->default(0)
                                ->minValue(0)
                                ->maxValue(function (callable $get) {
                                    $qty = (float) ($get('quantity') ?? 0);
                                    $unit = (float) ($get('unit_price') ?? 0);
                                    $lineSubtotal = $qty * $unit;

                                    return max(0, $lineSubtotal);
                                })
                                ->validationMessages([
                                    'max' => 'Discount amount cannot be greater than the line subtotal.',
                                ])
                                ->step(0.01)
                                ->live(onBlur: true)
                                ->afterStateUpdated(function ($state, callable $set, callable $get, $livewire) {
                                    $livewire->resetValidation('data.items.*.discount_amount');
                                    $livewire->resetErrorBag('data.items.*.discount_amount');
                                    if ($state === null || $state === '' || ! is_numeric($state)) {
                                        return;
                                    }
                                    $lineTotal = (float) ($get('line_subtotal') ?? 0);
                                    $amount = round(max(0, (float) ($state ?? 0)), 2);
                                    $rate = $lineTotal > 0 ? ($amount / $lineTotal) * 100 : 0;

                                    $set('discount', $rate);
                                    self::updateLineTotalDisplay($set, $get);
                                    self::recalcTotals($set, $get);
                                })
                                ->dehydrateStateUsing(fn ($state) => $state === null || $state === '' ? 0 : $state)
                                ->visible(fn (callable $get) => ($get('../../discount_mode') ?? 'percent') === 'amount'),

                            TextInput::make('tax')
                                ->label('Tax (%)')
                                ->inputMode('decimal')
                                ->rule('numeric')
                                ->extraInputAttributes(['data-line-field' => 'tax'])
                                ->default(0)
                                ->minValue(0)
                                ->rule('max:100')
                                ->validationMessages([
                                    'max' => 'The tax (%) field must not be greater than 100.',
                                ])
                                ->step(0.01)
                                ->suffix('%')
                                ->live(onBlur: true)
                                ->afterStateHydrated(function ($state, callable $set) {
                                    if ($state === null || $state === '') {
                                        $set('tax', 0);

                                        return;
                                    }
                                    $set('tax', (float) $state);
                                })
                                ->afterStateUpdated(function ($state, callable $set, callable $get, $livewire) {
                                    $livewire->resetValidation('data.items.*.tax');
                                    $livewire->resetErrorBag('data.items.*.tax');
                                    if ($state === null || $state === '' || ! is_numeric($state)) {
                                        return;
                                    }
                                    self::updateLineTotalDisplay($set, $get);
                                    self::recalcTotals($set, $get);
                                })
                                ->dehydrateStateUsing(fn ($state) => $state === null || $state === '' ? 0 : $state)
                                ->visible(fn (callable $get) => ($get('../../discount_mode') ?? 'percent') !== 'amount'),

                            TextInput::make('tax_amount')
                                ->label('Tax (PKR)')
                                ->inputMode('decimal')
                                ->rule('numeric')
                                ->extraInputAttributes(['data-line-field' => 'tax_amount'])
                                ->default(0)
                                ->minValue(0)
                                ->maxValue(function (callable $get) {
                                    $qty = (float) ($get('quantity') ?? 0);
                                    $unit = (float) ($get('unit_price') ?? 0);
                                    $lineSubtotal = $qty * $unit;

                                    return max(0, $lineSubtotal);
                                })
                                ->validationMessages([
                                    'max' => 'Tax amount cannot be greater than the line subtotal.',
                                ])
                                ->step(0.01)
                                ->live(onBlur: true)
                                ->afterStateUpdated(function ($state, callable $set, callable $get, $livewire) {
                                    $livewire->resetValidation('data.items.*.tax_amount');
                                    $livewire->resetErrorBag('data.items.*.tax_amount');
                                    if ($state === null || $state === '' || ! is_numeric($state)) {
                                        return;
                                    }
                                    $lineSubtotal = (float) ($get('line_subtotal') ?? 0);
                                    $discountAmount = (float) ($get('discount_amount') ?? 0);
                                    $taxableAmount = $lineSubtotal - $discountAmount;
                                    $amount = round(max(0, (float) ($state ?? 0)), 2);
                                    $rate = $taxableAmount > 0 ? ($amount / $taxableAmount) * 100 : 0;

                                    $set('tax', $rate);
                                    self::updateLineTotalDisplay($set, $get);
                                    self::recalcTotals($set, $get);
                                })
                                ->dehydrateStateUsing(fn ($state) => $state === null || $state === '' ? 0 : $state)
                                ->visible(fn (callable $get) => ($get('../../discount_mode') ?? 'percent') === 'amount'),

                            /* -------- LINE TOTAL -------- */
                            TextInput::make('line_total')
                                ->label('Line Total')
                                ->numeric()
                                ->disabled()
                                ->dehydrated()
                                ->extraInputAttributes(['data-line-field' => 'line_total'])
                                ->default(0),
                            Hidden::make('line_subtotal')
                                ->default(0)
                                ->dehydrated(false),
                        ])
                        ->columns(4)
                        ->defaultItems(1)
                        ->minItems(1)
                        ->collapsible()
                        ->itemLabel('Item')
                        ->itemNumbers()
                        ->addActionLabel('Add Item')
                        ->addable(fn (callable $get) => ! (bool) ($get('../../paginated_items_mode') ?? false))
                        ->reorderable(false)
                        ->deletable(fn (callable $get) => ! (bool) ($get('../../paginated_items_mode') ?? false))
                        ->afterStateHydrated(function (callable $set, callable $get) {
                            $items = $get('items') ?? [];
                            $discountMode = $get('discount_mode') ?? 'percent';

                            foreach ($items as &$item) {
                                $qty = (float) ($item['quantity'] ?? 0);
                                $unit = (float) ($item['unit_price'] ?? 0);
                                $lineSubtotal = (float) ($item['line_subtotal'] ?? ($qty * $unit));
                                if ($lineSubtotal <= 0) {
                                    $lineSubtotal = (float) ($item['line_total'] ?? 0);
                                }

                                $discountRate = (float) ($item['discount'] ?? 0);
                                $taxRate = (float) ($item['tax'] ?? 0);

                                if ($discountMode === 'amount') {
                                    $discountAmount = (float) ($item['discount_amount'] ?? 0);
                                    if ($discountAmount <= 0 && $discountRate > 0) {
                                        $discountAmount = $lineSubtotal * ($discountRate / 100);
                                    }
                                    $discountAmount = min(max(0, $discountAmount), $lineSubtotal);
                                    $discountRate = $lineSubtotal > 0 ? ($discountAmount / $lineSubtotal) * 100 : 0;

                                    $taxableAmount = max(0, $lineSubtotal - $discountAmount);
                                    $taxAmount = (float) ($item['tax_amount'] ?? 0);
                                    if ($taxAmount <= 0 && $taxRate > 0) {
                                        $taxAmount = $taxableAmount * ($taxRate / 100);
                                    }
                                    $taxAmount = min(max(0, $taxAmount), $lineSubtotal);
                                    $taxRate = $taxableAmount > 0 ? ($taxAmount / $taxableAmount) * 100 : 0;
                                } else {
                                    $discountRate = max(0, min(100, $discountRate));
                                    $taxRate = max(0, min(100, $taxRate));
                                    $discountAmount = $lineSubtotal * ($discountRate / 100);
                                    $taxableAmount = max(0, $lineSubtotal - $discountAmount);
                                    $taxAmount = $taxableAmount * ($taxRate / 100);
                                }

                                $item['line_subtotal'] = $lineSubtotal;
                                $item['discount'] = round(min(100, $discountRate), 6);
                                $item['tax'] = round(min(100, $taxRate), 6);
                                $item['discount_amount'] = round($discountAmount, 2);
                                $item['tax_amount'] = round($taxAmount, 2);
                                $item['line_total'] = round($taxableAmount + $taxAmount, 2);
                            }

                            $set('items', $items);
                            self::recalcTotals($set, $get);
                        })
                        ->afterStateUpdated(fn (callable $set, callable $get) => self::recalcTotals($set, $get)
                        ),
                ]),

            /* ===========================
             * SUMMARY
             * =========================== */
            Section::make('Summary')
                ->extraAttributes(['class' => 'blue-section'])
                ->columns(6)
                ->columnSpanFull()
                ->schema([
                    Placeholder::make('subtotal_display')
                        ->label('Subtotal')
                        ->extraAttributes(['data-summary' => 'subtotal'])
                        ->content(fn (callable $get) => 'PKR '.number_format((float) ($get('subtotal') ?? 0), 2)
                        ),

                    Placeholder::make('total_discount_display')
                        ->label('Discount')
                        ->extraAttributes(['data-summary' => 'discount'])
                        ->content(fn (callable $get) => 'PKR '.number_format((float) ($get('total_discount') ?? 0), 2)
                        ),

                    Placeholder::make('total_tax_display')
                        ->label('Tax')
                        ->extraAttributes(['data-summary' => 'tax'])
                        ->content(fn (callable $get) => 'PKR '.number_format((float) ($get('total_tax') ?? 0), 2)
                        ),

                    Placeholder::make('total_amount_display')
                        ->label('Total Amount')
                        ->extraAttributes(['data-summary' => 'total'])
                        ->content(fn (callable $get) => 'PKR '.number_format((float) ($get('total_amount') ?? 0), 2)
                        ),

                    TextInput::make('current_payment_amount')
                        ->label('Current Payment')
                        ->numeric()
                        ->default(null)
                        ->minValue(0)
                        ->maxValue(fn (callable $get) => max(
                            0,
                            (float) ($get('total_amount') ?? 0) - (float) ($get('previous_paid_amount') ?? 0)
                        ))
                        ->live(onBlur: true)
                        ->afterStateHydrated(function (callable $set, callable $get) {
                            self::syncPaymentFromTotals($set, $get);
                        })
                        ->afterStateUpdated(function ($state, callable $set, callable $get, $livewire) {
                            $livewire->resetValidation('data.current_payment_amount');
                            $livewire->resetErrorBag('data.current_payment_amount');
                            self::syncPaymentFromTotals($set, $get);
                        })
                        ->rule(function (callable $get) {
                            return function (string $attribute, $value, \Closure $fail) use ($get): void {
                                $entered = (float) ($value ?? 0);
                                $total = max(0, (float) ($get('total_amount') ?? 0));
                                $previousPaid = max(0, (float) ($get('previous_paid_amount') ?? 0));
                                $remaining = max(0, round($total - $previousPaid, 2));

                                if ($entered > $remaining) {
                                    $fail('Current payment cannot exceed the remaining due amount.');
                                }
                            };
                        }),

                    Placeholder::make('due_amount_display')
                        ->label('Amount Due')
                        ->live()
                        ->content(fn (callable $get) => 'PKR '.number_format((float) ($get('due_amount') ?? 0), 2)
                        ),

                    Hidden::make('subtotal')->default(0)->dehydrated(),
                    Hidden::make('total_discount')->default(0)->dehydrated(),
                    Hidden::make('total_tax')->default(0)->dehydrated(),
                    Hidden::make('total_amount')->default(0)->dehydrated(),
                ]),

            Section::make('Payment')
                ->extraAttributes(['class' => 'blue-section'])
                ->columns(1)
                ->columnSpanFull()
                ->schema([
                    Placeholder::make('previous_paid_amount_display')
                        ->label('Already Paid')
                        ->live()
                        ->content(fn (callable $get) => 'PKR '.number_format((float) ($get('previous_paid_amount') ?? 0), 2)
                        ),

                    Placeholder::make('payment_history')
                        ->label('Payment History')
                        ->content(fn ($record) => self::renderPaymentHistory($record)),

                    Hidden::make('paid_amount')->default(0)->dehydrated(),
                    Hidden::make('previous_paid_amount')->default(0)->dehydrated(false),
                    Hidden::make('due_amount')->default(0)->dehydrated(),
                ]),

            /* ===========================
             * NOTES
             * =========================== */
            Section::make('Notes')
                ->extraAttributes(['class' => 'blue-section'])
                ->columnSpanFull()
                ->schema([
                    Textarea::make('notes')
                        ->maxLength(255)
                        ->rows(3)
                        ->disabled(fn (callable $get) => (bool) ($get('is_partial_return') ?? false)),
                ]),
            View::make('filament.forms.line-calc-script'),
        ]);
    }

    /* ======================================================
     * HELPERS (DO NOT REMOVE)
     * ====================================================== */

    private static function selectedVendorBranchIds(callable $get): array
    {
        return collect($get('items') ?? [])
            ->pluck('branch_id')
            ->filter(fn ($branchId) => filled($branchId))
            ->map(fn ($branchId) => (string) $branchId)
            ->unique()
            ->values()
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

    private static function merchantHasLogo(): bool
    {
        $user = Filament::auth()->user();

        $merchant = $user instanceof Merchant
            ? $user
            : $user?->merchant;

        return (bool) $merchant?->logo;
    }

    private static function productOptions(?string $search = null): array
    {
        $user = Filament::auth()->user();

        $query = Product::query()
            ->withoutTrashed()
            ->where('products.is_active', true)
            ->where('products.merchant_id', self::merchantId());

        if ($user instanceof User) {
            $branchIds = $user->branches()->pluck('branches.id');

            $query->whereExists(function ($q) use ($branchIds) {
                $q->selectRaw(1)
                    ->from('branch_products')
                    ->whereColumn('branch_products.product_id', 'products.id')
                    ->whereIn('branch_products.branch_id', $branchIds);
            });
        }

        if (filled($search)) {
            $term = '%'.mb_strtolower(trim($search)).'%';

            $query->where(function ($q) use ($term) {
                $q->whereRaw('LOWER(products.name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(products.sku) LIKE ?', [$term]);
            });
        }

        $query->orderBy('products.name');

        if (filled($search)) {
            $query->limit(50);
        }

        return $query
            ->get(['products.id', 'products.name', 'products.sku', 'products.track_inventory', 'products.type'])
            ->mapWithKeys(fn (Product $product) => [
                $product->id => self::productOptionLabel($product),
            ])
            ->all();
    }

    private static function productOptionLabel(Product $product): string
    {
        $label = "{$product->name} ({$product->sku})";

        if (! ProductStockAvailability::productTracksInventory($product)) {
            return $label;
        }

        $quantity = ProductStockAvailability::productTotalStock($product);

        return $label.' — Qty: '.ProductStockAvailability::formatQuantity($quantity);
    }

    private static function recalcTotals(callable $set, callable $get): void
    {
        $items = $get('items');
        $rootPrefix = '';

        if (! is_array($items)) {
            $items = $get('../../items') ?? [];
            $rootPrefix = '../../';
        }

        if ((bool) ($get($rootPrefix.'paginated_items_mode') ?? false)) {
            self::syncPaymentFromTotals($set, $get, $rootPrefix);

            return;
        }

        $discountMode = $get($rootPrefix.'discount_mode') ?? 'percent';
        $subtotal = 0.0;
        $totalDiscount = 0.0;
        $totalTax = 0.0;

        foreach ($items as $item) {
            $qty = (float) ($item['quantity'] ?? 0);
            $unit = (float) ($item['unit_price'] ?? 0);
            $lineSubtotal = (float) ($item['line_subtotal'] ?? ($qty * $unit));
            if ($lineSubtotal <= 0) {
                $lineSubtotal = (float) ($item['line_total'] ?? 0);
            }

            $subtotal += $lineSubtotal;

            if ($discountMode === 'amount') {
                $discountAmountInput = (float) ($item['discount_amount'] ?? 0);
                $discountAmount = min(max(0, $discountAmountInput), $lineSubtotal);
                $taxableAmount = max(0, $lineSubtotal - $discountAmount);

                $taxAmountInput = (float) ($item['tax_amount'] ?? 0);
                $taxAmount = min(max(0, $taxAmountInput), $lineSubtotal);
            } else {
                $discountRate = max(0, min(100, (float) ($item['discount'] ?? 0)));
                $taxRate = max(0, min(100, (float) ($item['tax'] ?? 0)));

                $discountAmount = $lineSubtotal * ($discountRate / 100);
                $taxableAmount = max(0, $lineSubtotal - $discountAmount);
                $taxAmount = $taxableAmount * ($taxRate / 100);
            }

            $totalDiscount += $discountAmount;
            $totalTax += $taxAmount;
        }

        $set($rootPrefix.'subtotal', $subtotal);
        $set($rootPrefix.'total_discount', $totalDiscount);
        $set($rootPrefix.'total_tax', $totalTax);
        $set($rootPrefix.'total_amount', $subtotal - $totalDiscount + $totalTax);
        self::syncPaymentFromTotals($set, $get, $rootPrefix);
    }

    private static function syncPaymentFromTotals(callable $set, callable $get, string $rootPrefix = ''): void
    {
        $totalAmount = max(0, (float) ($get($rootPrefix.'total_amount') ?? 0));
        $previousPaid = max(0, (float) ($get($rootPrefix.'previous_paid_amount') ?? 0));
        $maxCurrentPayment = max(0, $totalAmount - $previousPaid);
        $currentPaymentValue = $get($rootPrefix.'current_payment_amount');

        $currentPayment = $currentPaymentValue === null || $currentPaymentValue === ''
            ? ($previousPaid > 0 ? 0.0 : $totalAmount)
            : (float) $currentPaymentValue;

        $currentPayment = max(0, min($maxCurrentPayment, $currentPayment));
        $paidAmount = max(0, min($totalAmount, $previousPaid + $currentPayment));
        $dueAmount = max(0, $totalAmount - $paidAmount);

        $set($rootPrefix.'current_payment_amount', round($currentPayment, 2));
        $set($rootPrefix.'paid_amount', round($paidAmount, 2));
        $set($rootPrefix.'due_amount', round($dueAmount, 2));
        $set($rootPrefix.'payment_type', $dueAmount > 0 ? 'credit' : 'cash');
    }

    private static function renderPaymentHistory($record): HtmlString
    {
        if (! $record || ! method_exists($record, 'payments')) {
            return new HtmlString('<span class="text-gray-500">No payment history available yet.</span>');
        }

        $payments = PaymentLedgerService::displayablePayments($record);

        if ($payments->isEmpty()) {
            return new HtmlString('<span class="text-gray-500">No payment history available yet.</span>');
        }

        $rows = $payments->map(function ($payment) {
            $date = $payment->payment_date?->format('d/m/Y') ?? '—';
            $type = ucfirst((string) ($payment->entry_type ?? 'payment'));
            $amount = 'PKR '.number_format((float) ($payment->display_amount ?? 0), 2);

            $actionButton = '';
            if ((float) ($payment->amount ?? 0) > 0 && (string) ($payment->entry_type ?? 'payment') === 'payment') {
                $actionButton = '<button type="button" wire:click="confirmReversePayment(\''.e((string) $payment->id).'\')"'
                    .' style="padding:2px 8px;border:1px solid #ef4444;border-radius:6px;color:#b91c1c;background:#fff;">-</button>';
            }

            return '<tr>'
                .'<td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;">'.e($date).'</td>'
                .'<td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;">'.e($type).'</td>'
                .'<td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;text-align:right;">'.e($amount).'</td>'
                .'<td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;text-align:center;">'.$actionButton.'</td>'
                .'</tr>';
        })->implode('');

        $html = '<div style="overflow:auto;">'
            .'<table style="width:100%;border-collapse:collapse;font-size:12px;">'
            .'<thead><tr>'
            .'<th style="text-align:left;padding:6px 8px;border-bottom:1px solid #d1d5db;">Date</th>'
            .'<th style="text-align:left;padding:6px 8px;border-bottom:1px solid #d1d5db;">Type</th>'
            .'<th style="text-align:right;padding:6px 8px;border-bottom:1px solid #d1d5db;">Amount</th>'
            .'<th style="text-align:center;padding:6px 8px;border-bottom:1px solid #d1d5db;">Action</th>'
            .'</tr></thead><tbody>'
            .$rows
            .'</tbody></table></div>';

        return new HtmlString($html);
    }

    private static function updateLineTotalDisplay(callable $set, callable $get): void
    {
        $unitPrice = (float) ($get('unit_price') ?? 0);
        $qty = max(1, (float) ($get('quantity') ?? 1));
        $lineSubtotal = $unitPrice * $qty;
        $discountRate = (float) ($get('discount') ?? 0);
        $taxRate = (float) ($get('tax') ?? 0);
        $discountAmountInput = (float) ($get('discount_amount') ?? 0);
        $taxAmountInput = (float) ($get('tax_amount') ?? 0);
        $discountMode = $get('../../discount_mode') ?? 'percent';

        $set('line_subtotal', $lineSubtotal);

        if ($discountMode === 'amount' && $lineSubtotal > 0) {
            $discountAmountInput = min(max(0, $discountAmountInput), $lineSubtotal);
            $discountRate = ($discountAmountInput / $lineSubtotal) * 100;
            $set('discount', round(min(100, $discountRate), 6));
        }

        $discountAmount = $lineSubtotal * ($discountRate / 100);
        $taxableLine = max(0, $lineSubtotal - $discountAmount);

        if ($discountMode === 'amount' && $taxableLine > 0) {
            $taxAmountInput = min(max(0, $taxAmountInput), $lineSubtotal);
            $taxRate = ($taxAmountInput / $taxableLine) * 100;
            $set('tax', round(min(100, $taxRate), 6));
        }

        $taxAmount = $discountMode === 'amount'
            ? $taxAmountInput
            : ($taxableLine * ($taxRate / 100));
        $lineTotal = $taxableLine + $taxAmount;

        if ($discountMode !== 'amount') {
            $set('discount_amount', round($discountAmount, 2));
            $set('tax_amount', round($taxAmount, 2));
        }

        $set('line_total', round($lineTotal, 2));
    }
}
