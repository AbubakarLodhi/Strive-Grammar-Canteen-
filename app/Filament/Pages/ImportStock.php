<?php

namespace App\Filament\Pages;

use App\Models\Merchant;
use App\Models\User;
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
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ImportStock extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::ArrowUpTray;

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Import Stock';

    protected static ?string $navigationLabel = 'Import Stock';

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
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Upload stock sheet')
                    ->description('Upload the canteen Excel file (.xls / .xlsx) with columns: Product Name, Qty, Pr Price, Sell Price. Matching products are updated; stock quantities become the Qty in the file.')
                    ->schema([
                        FileUpload::make('stock_file')
                            ->label('Stock Excel file')
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
                            ->helperText('Re-uploading replaces the managed STOCK-SHEET purchase so on-hand stock matches the file.'),
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

            $this->lastResult = $result;
            $this->form->fill(['stock_file' => null]);

            Notification::make()
                ->title('Stock imported')
                ->body(sprintf(
                    '%d created, %d updated, %d rows, total qty %s',
                    $result['products_created'],
                    $result['products_updated'],
                    $result['rows_imported'],
                    number_format($result['total_quantity']),
                ))
                ->success()
                ->send();
        } catch (RuntimeException|Throwable $exception) {
            Notification::make()
                ->title('Import failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }
}
