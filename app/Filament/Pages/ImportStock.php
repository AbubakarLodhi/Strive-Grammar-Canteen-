<?php

namespace App\Filament\Pages;

use App\Models\Merchant;
use App\Models\User;
use App\Services\Finance\FinanceLedger;
use App\Services\Inventory\CanteenStockImporter;
use App\Support\FinanceAccess;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ImportStock extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::ArchiveBox;

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Opening Stock';

    protected static ?string $navigationLabel = 'Opening Stock';

    protected string $view = 'filament.pages.import-stock';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    /**
     * @var array<string, mixed>|null
     */
    public ?array $lastResult = null;

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();
        $guard = Filament::getCurrentPanel()?->getAuthGuard();

        if (! $user || ! $guard) {
            return false;
        }

        return $user->hasPermissionTo('products.create', $guard)
            || $user->hasPermissionTo('products.update', $guard)
            || $user->hasPermissionTo('purchases.create', $guard);
    }

    public function mount(): void
    {
        $this->form->fill();

        $user = Filament::auth()->user();
        $merchantId = match (true) {
            $user instanceof Merchant => $user->id,
            $user instanceof User => $user->merchant_id,
            default => null,
        };

        if (! $merchantId) {
            return;
        }

        app(FinanceLedger::class)->purgeOpeningStockLedger($merchantId);

        $cached = Cache::get($this->cacheKey($merchantId));
        if (is_array($cached)) {
            $this->lastResult = $this->withLiveStock($cached, $merchantId);
        }
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Introduce opening stock')
                    ->description('Upload the stock Excel file (.xls / .xlsx) with columns: Product Name, Qty, Pr Price, Sell Price. Later purchases of the same products increase current stock automatically (opening + purchases − sales). Opening stock is not listed under Purchases or Parties payables.')
                    ->schema([
                        FileUpload::make('stock_file')
                            ->label('Opening stock Excel file')
                            ->acceptedFileTypes([
                                'application/vnd.ms-excel',
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/excel',
                                'text/csv',
                            ])
                            ->disk('local')
                            ->directory('imports/uploads')
                            ->visibility('private')
                            ->required()
                            ->helperText('Re-uploading refreshes the sheet baseline. Current stock still includes any later purchases and subtracts sales.'),
                    ]),
            ]);
    }

    public function import(): void
    {
        $state = $this->form->getState();
        $relativePath = $state['stock_file'] ?? null;

        if (is_array($relativePath)) {
            $relativePath = $relativePath[0] ?? null;
        }

        if (blank($relativePath)) {
            Notification::make()->title('Choose a stock file first.')->danger()->send();

            return;
        }

        $path = Storage::disk('local')->path($relativePath);
        $merchantId = FinanceAccess::merchantId();
        $merchant = $merchantId ? Merchant::query()->find($merchantId) : null;

        if (! $merchant) {
            Notification::make()->title('No merchant context.')->danger()->send();

            return;
        }

        try {
            $result = app(CanteenStockImporter::class)->importFromPath(
                $path,
                $merchant,
                Filament::auth()->user() instanceof User ? Filament::auth()->id() : null,
            );

            Cache::put($this->cacheKey($merchant->id), $result, now()->addDays(60));
            $this->lastResult = $this->withLiveStock($result, $merchant->id);
            $this->form->fill(['stock_file' => null]);

            Notification::make()
                ->title('Opening stock updated')
                ->body(sprintf(
                    '%d products · sheet qty %s · current stock %s',
                    $result['sheet_products'] ?? $result['rows_imported'],
                    number_format((float) ($result['sheet_total_quantity'] ?? $result['total_quantity'])),
                    number_format((float) ($this->lastResult['live_total_quantity'] ?? 0)),
                ))
                ->success()
                ->send();
        } catch (RuntimeException|Throwable $exception) {
            Notification::make()
                ->title('Opening stock import failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    protected function withLiveStock(array $result, string $merchantId): array
    {
        return app(CanteenStockImporter::class)->withLiveStock($result, $merchantId);
    }

    protected function cacheKey(string $merchantId): string
    {
        return 'opening-stock-last-result:'.$merchantId;
    }
}
