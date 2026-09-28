<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Sales\SaleResource;
use App\Models\Merchant;
use App\Models\PermissionModule;
use App\Models\Sale;
use App\Models\User;
use App\Services\SalePostingService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class PendingSales extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Clock;

    protected static string|\UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Pending Sales';

    protected static ?string $navigationLabel = 'Pending Sales';

    protected string $view = 'filament.pages.pending-sales';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();
        $guard = Filament::getCurrentPanel()?->getAuthGuard();

        if (! $user || ! $guard) {
            return false;
        }

        if (! PermissionModule::isEnabledForCurrentMerchant('sales')) {
            return false;
        }

        return $user->hasPermissionTo('sales.view', $guard);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::draftQuery()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'warning';
    }

    /**
     * @return array{count: int, total_amount: float}
     */
    public function getPendingStats(): array
    {
        $query = static::draftQuery();

        return [
            'count' => (int) (clone $query)->count(),
            'total_amount' => (float) (clone $query)->sum('total_amount'),
        ];
    }

    public function table(Table $table): Table
    {
        $guard = Filament::getCurrentPanel()?->getAuthGuard();

        return $table
            ->query(fn (): Builder => static::draftQuery()->with([
                'customer:id,name',
                'merchant:id,name',
                'items.product:id,name',
                'createdBy:id,name',
            ]))
            ->columns([
                TextColumn::make('sale_no')
                    ->label('Sale No.')
                    ->searchable()
                    ->sortable()
                    ->url(fn (Sale $record): string => SaleResource::getUrl('view', ['record' => $record])),

                TextColumn::make('sale_date')
                    ->label('Date')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (): string => 'Draft')
                    ->color('warning'),

                TextColumn::make('customer.name')
                    ->label('Customer')
                    ->searchable()
                    ->sortable()
                    ->limit(30),

                TextColumn::make('total_amount')
                    ->label('Total')
                    ->money('PKR')
                    ->sortable(),

                TextColumn::make('paid_amount')
                    ->label('Paid')
                    ->money('PKR')
                    ->sortable(),

                TextColumn::make('due_amount')
                    ->label('Due')
                    ->money('PKR')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Saved At')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('push')
                    ->label('Push')
                    ->icon('heroicon-s-paper-airplane')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Push sale')
                    ->modalDescription('This will add the sale to sales totals and cash.')
                    ->visible(fn (): bool => (bool) (
                        auth($guard)->user()?->hasPermissionTo('sales.create', $guard)
                        || auth($guard)->user()?->hasPermissionTo('sales.update', $guard)
                    ))
                    ->action(function (Sale $record): void {
                        $this->pushSale($record);
                    }),

                ViewAction::make()
                    ->url(fn (Sale $record): string => SaleResource::getUrl('view', ['record' => $record]))
                    ->visible(fn (): bool => (bool) auth($guard)->user()?->hasPermissionTo('sales.view', $guard)),

                EditAction::make()
                    ->url(fn (Sale $record): string => SaleResource::getUrl('edit', ['record' => $record]))
                    ->visible(fn (): bool => (bool) auth($guard)->user()?->hasPermissionTo('sales.update', $guard)),

                DeleteAction::make()
                    ->visible(fn (): bool => (bool) auth($guard)->user()?->hasPermissionTo('sales.delete', $guard)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('pushSelected')
                        ->label('Push selected')
                        ->icon('heroicon-s-paper-airplane')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Push selected sales')
                        ->modalDescription('This will add the selected sales to sales totals and cash.')
                        ->visible(fn (): bool => (bool) (
                            auth($guard)->user()?->hasPermissionTo('sales.create', $guard)
                            || auth($guard)->user()?->hasPermissionTo('sales.update', $guard)
                        ))
                        ->action(function (Collection $records): void {
                            $pushed = 0;
                            $failed = 0;

                            foreach ($records as $record) {
                                if (! $record instanceof Sale || ! $record->isDraft()) {
                                    continue;
                                }

                                try {
                                    $this->pushSale($record, notify: false);
                                    $pushed++;
                                } catch (ValidationException) {
                                    $failed++;
                                }
                            }

                            if ($pushed > 0) {
                                Notification::make()
                                    ->title($pushed === 1 ? '1 sale pushed' : "{$pushed} sales pushed")
                                    ->success()
                                    ->send();
                            }

                            if ($failed > 0) {
                                Notification::make()
                                    ->title($failed === 1 ? '1 sale could not be pushed' : "{$failed} sales could not be pushed")
                                    ->body('Check stock and try again for the remaining drafts.')
                                    ->danger()
                                    ->send();
                            }
                        }),
                    DeleteBulkAction::make()
                        ->visible(fn (): bool => (bool) auth($guard)->user()?->hasPermissionTo('sales.delete', $guard)),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No pending sales')
            ->emptyStateDescription('Draft sales appear here until you Push them into sales totals, cash, and stock.')
            ->emptyStateActions([
                Action::make('createDraft')
                    ->label('Create sale')
                    ->icon('heroicon-o-plus')
                    ->url(SaleResource::getUrl('create')),
            ]);
    }

    protected function pushSale(Sale $sale, bool $notify = true): void
    {
        $user = Filament::auth()->user();

        app(SalePostingService::class)->post(
            $sale,
            $user instanceof User ? $user : null,
        );

        if ($notify) {
            Notification::make()
                ->title('Sale pushed')
                ->body('Sale is now included in sales totals, cash, and stock.')
                ->success()
                ->send();
        }
    }

    protected static function draftQuery(): Builder
    {
        $user = Filament::auth()->user();

        $merchantId = match (true) {
            $user instanceof Merchant => $user->id,
            $user instanceof User => $user->merchant_id,
            default => null,
        };

        if (! $merchantId) {
            return Sale::query()->withoutTrashed()->whereRaw('1 = 0');
        }

        $query = Sale::query()
            ->withoutTrashed()
            ->draft()
            ->where('merchant_id', $merchantId);

        if ($user instanceof User) {
            $query
                ->whereHas('items.business.users', fn ($q) => $q->where('users.id', $user->id))
                ->whereHas('items.branch.users', fn ($q) => $q->where('users.id', $user->id));
        }

        return $query;
    }
}
