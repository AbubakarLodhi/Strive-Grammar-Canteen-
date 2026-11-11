<?php

namespace App\Filament\Resources\Products\Tables;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\ProductVariants\ProductVariantResource;
use App\Models\PermissionModule;
use App\Models\Product;
use App\Models\ProductVariant;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class ProductsTable
{
    private static function resolveImageUrl(?string $photoUrl): string
    {
        if (! $photoUrl) {
            return asset('images/placeholder.jpg');
        }

        if (Storage::disk('public')->exists($photoUrl)) {
            return asset('storage/'.$photoUrl);
        }

        // Fallback: image was stored on Supabase S3
        return 'https://hdojyhoqzioxnbkuxjno.supabase.co/storage/v1/object/public/product-images/'.$photoUrl;
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(query: function ($query, string $search) {
                        return $query->where(function ($builder) use ($search): void {
                            $builder
                                ->where('name', 'like', "%{$search}%")
                                ->orWhere('sku', 'like', "%{$search}%");
                        });
                    })
                    ->limit(30)
                    ->sortable(),
                ImageColumn::make('product_image')
                    ->label('Image')
                    ->size(50)
                    ->square()
                    ->getStateUsing(fn (Product $record) => self::resolveImageUrl($record->productImage?->photo_url)
                    ),

                TextColumn::make('type')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->badge(),

                TextColumn::make('unit')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->badge(),

                //                TextColumn::make('selling_price')
                //                    ->money()
                //                    ->sortable(),

                IconColumn::make('is_active')
                    ->boolean()
                    ->color(fn ($state) => $state ? 'primary' : 'danger'),

                TextColumn::make('merchant.name')
                    ->label('Merchant')
                    ->limit(30)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable(),

                //                TextColumn::make('business.name')
                //                    ->label('Business')
                //                    ->sortable()
                //                    ->searchable(),

                TextColumn::make('category.name')
                    ->label('Category')
                    ->limit(30)
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->toggleable(),

                TextColumn::make('brand.name')
                    ->label('Brand')
                    ->limit(30)
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordUrl(fn (Product $record) => auth(Filament::getCurrentPanel()->getAuthGuard())
                ->user()
                ?->hasPermissionTo('products.update', Filament::getCurrentPanel()->getAuthGuard())
                ? ProductResource::getUrl('edit', [
                    'record' => $record,
                ])
                : null
            )
            ->recordActions([
                Action::make('view-variants')
                    ->color('secondary')
                    ->icon('heroicon-s-eye')
                    ->label('')
                    ->tooltip('View Variants')
                    ->url(fn ($record) => ProductVariantResource::getUrl('index', [
                        'product_id' => $record->id,
                        'brand_model_id' => $record->brand_model_id,
                        'brand_id' => $record->brand_id,
                        'category_id' => $record->category_id,
                    ])
                    )
                    // ✅ SHOW ONLY IF VARIANTS EXIST FOR THIS PRODUCT
                    ->visible(function ($record) {
                        if (! $record) {
                            return false;
                        }

                        $guard = Filament::getCurrentPanel()->getAuthGuard();
                        $user = Auth::guard($guard)->user();

                        if (! PermissionModule::isEnabledForCurrentMerchant('products')) {
                            return false;
                        }
                        if (! $user?->hasPermissionTo('products.view', $guard)) {
                            return false;
                        }

                        // 📦 3. Variants must exist for this product
                        return ProductVariant::where('product_id', $record->id)->exists();
                    })
                    ->openUrlInNewTab(false),
                EditAction::make()
                    ->color('warning')
                    ->label('')
                    ->tooltip('Edit')
                    ->visible(fn () => auth(Filament::getCurrentPanel()->getAuthGuard())->user()?->hasPermissionTo('products.update', Filament::getCurrentPanel()->getAuthGuard())),
                DeleteAction::make()
                    ->color('danger')
                    ->label('')
                    ->tooltip('Delete')
                    ->visible(fn () => auth(Filament::getCurrentPanel()->getAuthGuard())->user()?->hasPermissionTo('products.delete', Filament::getCurrentPanel()->getAuthGuard())),

            ])->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn () => auth(Filament::getCurrentPanel()->getAuthGuard())->user()?->hasPermissionTo('products.delete', Filament::getCurrentPanel()->getAuthGuard())),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
