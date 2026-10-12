<?php

namespace App\Filament\Resources\Products\Pages;

use App\Enums\AttachmentMetaType;
use App\Enums\AttachmentType;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Branch;
use App\Support\ProductUnit;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Str;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    public function getTitle(): string
    {
        return 'Edit '.Str::limit((string) $this->record?->name, 30);
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->color('danger'),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['unit'] = ProductUnit::normalize($data['unit'] ?? null);

        return $data;
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['branches'] = $this->record
            ->branches()
            ->pluck('branches.id')
            ->toArray();
        if ($this->record->productImage) {
            $data['product_image'] = [
                $this->record->productImage->photo_url,
            ];
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $state = $this->form->getRawState();
        $product = $this->record;

        /* -------------------------
         | Sync Variant Prices
         |
         | Variants snapshot their own price at creation time (e.g. the
         | "Standard" variant auto-created on first purchase/sale). Sales
         | and purchases price a line from the variant, not the product,
         | so a price edit here would otherwise never reach them.
         |--------------------------*/
        if (! $product->is_variable_price) {
            $product->variants()->update([
                'selling_price' => $product->selling_price,
                'purchase_price' => $product->purchase_price,
            ]);
        }

        /* -------------------------
         | Sync Branches
         |--------------------------*/
        if (isset($state['branches'])) {

            $branchSync = [];

            foreach ($state['branches'] as $branchId) {
                $branchSync[$branchId] = ['id' => (string) Str::uuid()];
            }

            $product->branches()->sync($branchSync);

            /* -------------------------
             | Derive Businesses from Branches
             |--------------------------*/
            $businessIds = Branch::whereIn('id', $state['branches'])
                ->pluck('business_id')
                ->unique()
                ->values();

            $businessSync = [];

            foreach ($businessIds as $businessId) {
                $businessSync[$businessId] = ['id' => (string) Str::uuid()];
            }

            $product->businesses()->sync($businessSync);
        }

        /* -------------------------
         | Product Image
         |--------------------------*/
        $path = collect($state['product_image'] ?? null)->first();

        if ($path) {
            $product->productImage()?->delete();

            $product->productImage()->create([
                'merchant_id' => $product->merchant_id,
                'type' => AttachmentType::IMAGE,
                'meta_type' => AttachmentMetaType::PRODUCT_IMAGE,
                'photo_url' => $path,
            ]);
        }
    }
}
