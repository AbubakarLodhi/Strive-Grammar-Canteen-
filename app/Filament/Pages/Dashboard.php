<?php

namespace App\Filament\Pages;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Merchant;
use App\Models\ProductVariant;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Home;

    public function persistsFiltersInSession(): bool
    {
        return false;
    }

    public function mount(): void
    {
        // Only seed defaults on a fresh visit. Do not overwrite intentionally
        // cleared dates (null) or URL/session-provided filter state.
        if (! empty($this->filters)) {
            return;
        }

        $this->filters = self::defaultFilterDates();
    }

    /**
     * Default dashboard range covers the overview chart window so existing
     * sales/purchases are visible instead of an empty "today only" view.
     *
     * @return array{date_from: string, date_to: string}
     */
    public static function defaultFilterDates(): array
    {
        return [
            'date_from' => now()->startOfMonth()->subMonths(5)->toDateString(),
            'date_to' => now()->toDateString(),
        ];
    }

    public function filtersForm(Schema $schema): Schema
    {
        $defaults = self::defaultFilterDates();

        return $schema
            ->columns(1)
            ->components([
                Section::make('Filters')
                    ->description('Refine dashboard analytics by business, branch, product variant, and date range.')
                    ->extraAttributes(['class' => 'dashboard-filters-section'])
                    ->schema([
                        Select::make('business_id')
                            ->label('Business')
                            ->placeholder('All businesses')
                            ->searchable()
                            ->preload()
                            ->options(function () {
                                $user = Filament::auth()->user();

                                $merchantId = match (true) {
                                    $user instanceof Merchant => $user->id,
                                    $user instanceof User => $user->merchant_id,
                                    default => null,
                                };

                                if (! $merchantId) {
                                    return [];
                                }

                                $query = Business::query()
                                    ->withoutTrashed()
                                    ->where('merchant_id', $merchantId);

                                if ($user instanceof User) {
                                    $query->whereHas('users', fn ($q) => $q->where('users.id', $user->id)
                                    );
                                }

                                return $query
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->toArray();
                            })
                            ->live()
                            ->afterStateUpdated(function (callable $set) {
                                $set('branch_id', null);
                                $set('product_variant_ids', []);
                            }),

                        Select::make('branch_id')
                            ->label('Branch')
                            ->placeholder('All branches')
                            ->searchable()
                            ->preload()
                            ->live()
                            ->options(function (callable $get) {
                                $user = Filament::auth()->user();

                                $merchantId = match (true) {
                                    $user instanceof Merchant => $user->id,
                                    $user instanceof User => $user->merchant_id,
                                    default => null,
                                };

                                if (! $merchantId) {
                                    return [];
                                }

                                $query = Branch::query()
                                    ->withoutTrashed()
                                    ->where('merchant_id', $merchantId);

                                if ($businessId = $get('business_id')) {
                                    $query->where('business_id', $businessId);
                                }

                                if ($user instanceof User) {
                                    $query->whereHas('users', fn ($q) => $q->where('users.id', $user->id)
                                    );
                                }

                                return $query
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->toArray();
                            })
                            ->afterStateUpdated(fn (callable $set) => $set('product_variant_ids', [])),

                        Select::make('product_variant_ids')
                            ->label('Product Variant')
                            ->placeholder('All variants')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->optionsLimit(500)
                            ->wrapOptionLabels(false)
                            ->extraAttributes([
                                'class' => 'dashboard-product-variant-select',
                            ])
                            ->extraFieldWrapperAttributes(fn (callable $get): array => [
                                'class' => 'dashboard-product-variant-select',
                                'data-dashboard-option-values' => json_encode(array_map(
                                    'strval',
                                    array_keys(self::productVariantFilterOptions($get)),
                                )),
                            ])
                            ->options(fn (callable $get): array => self::productVariantFilterOptions($get))
                            ->getOptionLabelsUsing(function (array $values): array {
                                if ($values === []) {
                                    return [];
                                }

                                return ProductVariant::query()
                                    ->withoutTrashed()
                                    ->whereIn('product_variants.id', $values)
                                    ->join('products', 'products.id', '=', 'product_variants.product_id')
                                    ->whereNull('products.deleted_at')
                                    ->select([
                                        'product_variants.id',
                                        'product_variants.name',
                                        'product_variants.sku',
                                        'products.name as product_name',
                                    ])
                                    ->get()
                                    ->mapWithKeys(fn (ProductVariant $variant): array => [
                                        $variant->id => self::productVariantFilterLabel($variant),
                                    ])
                                    ->all();
                            }),

                        DatePicker::make('date_from')
                            ->label('Date From')
                            ->default($defaults['date_from'])
                            ->placeholder('Start date')
                            ->displayFormat('d/m/Y')
                            ->maxDate(now())
                            ->rule('before_or_equal:today')
                            ->suffixAction(
                                Action::make('clear_date_from')
                                    ->icon('heroicon-s-x-mark')
                                    ->tooltip('Clear date')
                                    ->action(fn (callable $set) => $set('date_from', null))
                            )
                            ->native(false),

                        DatePicker::make('date_to')
                            ->label('Date To')
                            ->default($defaults['date_to'])
                            ->placeholder('End date')
                            ->displayFormat('d/m/Y')
                            ->minDate(fn (callable $get) => $get('date_from'))
                            ->maxDate(now())
                            ->rule('before_or_equal:today')
                            ->rule('after_or_equal:date_from')
                            ->suffixAction(
                                Action::make('clear_date_to')
                                    ->icon('heroicon-s-x-mark')
                                    ->tooltip('Clear date')
                                    ->action(fn (callable $set) => $set('date_to', null))
                            )
                            ->native(false),
                    ])
                    ->columns([
                        'default' => 1,
                        'sm' => 2,
                        'xl' => 4,
                    ])
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return array<string, string>
     */
    private static function productVariantFilterOptions(callable $get): array
    {
        $user = Filament::auth()->user();

        $merchantId = match (true) {
            $user instanceof Merchant => $user->id,
            $user instanceof User => $user->merchant_id,
            default => null,
        };

        if (! $merchantId) {
            return [];
        }

        $query = ProductVariant::query()
            ->withoutTrashed()
            ->where('product_variants.merchant_id', $merchantId)
            ->where('product_variants.is_active', true)
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->whereNull('products.deleted_at')
            ->select([
                'product_variants.id',
                'product_variants.name',
                'product_variants.sku',
                'products.name as product_name',
            ]);

        if ($businessId = $get('business_id')) {
            $query->whereHas('product.branches', fn ($q) => $q->where('branches.business_id', $businessId)
                ->whereNull('branches.deleted_at')
            );
        }

        if ($branchId = $get('branch_id')) {
            $query->whereHas('product.branches', fn ($q) => $q->where('branches.id', $branchId)
                ->whereNull('branches.deleted_at')
            );
        }

        if ($user instanceof User) {
            $query->whereHas('product.branches.users', fn ($q) => $q->where('users.id', $user->id));
            $query->whereHas('product.branches', fn ($q) => $q->whereNull('branches.deleted_at'));
        }

        return $query
            ->orderBy('products.name')
            ->orderBy('product_variants.name')
            ->get()
            ->mapWithKeys(fn (ProductVariant $variant): array => [
                $variant->id => self::productVariantFilterLabel($variant),
            ])
            ->all();
    }

    private static function productVariantFilterLabel(ProductVariant $variant): string
    {
        return trim(
            ($variant->product_name ? $variant->product_name.' - ' : '')
            .($variant->name ?: ($variant->sku ?: (string) $variant->id))
            .($variant->sku ? ' ('.$variant->sku.')' : '')
        );
    }
}
