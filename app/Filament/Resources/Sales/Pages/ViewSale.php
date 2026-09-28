<?php

namespace App\Filament\Resources\Sales\Pages;

use App\Filament\Resources\Sales\SaleResource;
use App\Models\Sale;
use App\Models\User;
use App\Services\SalePostingService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ViewSale extends ViewRecord
{
    protected static string $resource = SaleResource::class;

    public function getTitle(): string
    {
        $name = (string) ($this->record?->sale_no ?? $this->record?->name ?? '');

        return 'View '.Str::limit($name, 30);
    }

    protected function getHeaderActions(): array
    {
        $guard = Filament::getCurrentPanel()->getAuthGuard();

        return [
            Action::make('push')
                ->label('Push')
                ->icon('heroicon-o-paper-airplane')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Push sale')
                ->modalDescription('This will add the sale to sales totals and cash.')
                ->visible(fn (): bool => $this->record instanceof Sale
                    && $this->record->isDraft()
                    && (
                        auth($guard)->user()?->hasPermissionTo('sales.create', $guard)
                        || auth($guard)->user()?->hasPermissionTo('sales.update', $guard)
                    )
                )
                ->action(function (): void {
                    try {
                        $user = Filament::auth()->user();
                        app(SalePostingService::class)->post(
                            $this->record,
                            $user instanceof User ? $user : null,
                        );

                        Notification::make()
                            ->title('Sale pushed')
                            ->body('Sale is now included in sales totals, cash, and stock.')
                            ->success()
                            ->send();

                        $this->refreshFormData(['status', 'posted_at', 'posted_by']);
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->title('Cannot push sale')
                            ->body(collect($exception->errors())->flatten()->first() ?: $exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Action::make('invoice')
                ->label('Invoice')
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->url(fn (): string => route('invoices.show', [
                    'type' => 'sale',
                    'id' => $this->record->id,
                ]))
                ->openUrlInNewTab()
                ->visible(fn (): bool => (bool) auth($guard)->user()?->hasPermissionTo('sales.view', $guard)),
            EditAction::make()
                ->visible(fn () => auth($guard)->user()?->hasPermissionTo('sales.update', $guard)),
        ];
    }
}
