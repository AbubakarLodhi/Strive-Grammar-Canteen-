<?php

namespace App\Http\Controllers\Invoice;

use App\Models\InvoiceDynamicGroup;
use App\Models\Merchant;
use App\Models\Purchase;
use App\Models\Sale;
use App\Services\InvoiceDynamicFieldResolver;
use App\Services\InvoiceSlipCounterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class InvoiceController
{
    public function show(string $type, string $id)
    {
        $record = match ($type) {
            'sale' => Sale::with([
                'merchant.logo',
                'customer',
                'items.product',
                'items.variants.variant',
                'items.business.logo',
                'items.branch',
                'payments',
            ])->find($id),

            'purchase' => Purchase::with([
                'merchant.logo',
                'vendor',
                'items.product',
                'items.variants.variant',
                'items.business.logo',
                'items.branch',
                'payments',
            ])->find($id),

            default => throw new NotFoundHttpException(),
        };

        if (! $record) {
            abort(404);
        }

        $this->authorizeInvoiceAccess((string) $record->merchant_id);

        $headerGroupOptions = $this->invoiceGroupOptions((string) $record->merchant_id, 'header');
        $footerGroupOptions = $this->invoiceGroupOptions((string) $record->merchant_id, 'footer');
        $fallbackHeaderId = (string) array_key_first($headerGroupOptions);
        $fallbackFooterId = (string) array_key_first($footerGroupOptions);

        // Backward compatibility for old combo links.
        $combo = trim((string) request()->query('combo', ''));
        [$comboHeaderId, $comboFooterId] = $this->splitCombo($combo);

        $selectedHeaderGroupId = trim((string) request()->query('header', $comboHeaderId ?? $fallbackHeaderId));
        $selectedFooterGroupId = trim((string) request()->query('footer', $comboFooterId ?? $fallbackFooterId));

        if (! array_key_exists($selectedHeaderGroupId, $headerGroupOptions)) {
            $selectedHeaderGroupId = $fallbackHeaderId;
        }

        if (! array_key_exists($selectedFooterGroupId, $footerGroupOptions)) {
            $selectedFooterGroupId = $fallbackFooterId;
        }

        $dynamicGroups = InvoiceDynamicFieldResolver::resolveGroups(
            $record,
            $selectedHeaderGroupId,
            $selectedFooterGroupId,
        );

        [$previousInvoiceUrl, $nextInvoiceUrl] = $this->adjacentInvoiceUrls($type, $record);

        return view('filament.pages.invoice', [
            'type'   => $type,
            'record' => $record,
            'headerGroupOptions' => $headerGroupOptions,
            'footerGroupOptions' => $footerGroupOptions,
            'selectedHeaderGroupId' => $selectedHeaderGroupId,
            'selectedFooterGroupId' => $selectedFooterGroupId,
            'dynamicGroups' => $dynamicGroups,
            'previousInvoiceUrl' => $previousInvoiceUrl,
            'nextInvoiceUrl' => $nextInvoiceUrl,
            'closeUrl' => $this->resolveCloseUrl($type),
        ]);
    }

    public function nextSlipNumber(Request $request, string $type, string $id): JsonResponse
    {
        $record = $this->findInvoiceRecord($type, $id);
        $this->authorizeInvoiceAccess((string) $record->merchant_id);

        try {
            $number = app(InvoiceSlipCounterService::class)->nextNumber((string) $record->merchant_id);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Could not assign today\'s slip number. Please run database migrations and try again.',
            ], 503);
        }

        return response()->json([
            'number' => $number,
            'date' => now()->format('d/m/Y'),
        ]);
    }

    protected function findInvoiceRecord(string $type, string $id): Sale|Purchase
    {
        $record = match ($type) {
            'sale' => Sale::query()->find($id),
            'purchase' => Purchase::query()->find($id),
            default => throw new NotFoundHttpException(),
        };

        if (! $record) {
            abort(404);
        }

        return $record;
    }

    protected function authorizeInvoiceAccess(string $merchantId): void
    {
        $user = auth('merchant')->user() ?? auth('staff')->user();

        if (! $user) {
            abort(401);
        }

        $userMerchantId = $user instanceof Merchant
            ? (string) $user->id
            : (string) $user->merchant_id;

        if ($userMerchantId !== (string) $merchantId) {
            abort(403);
        }
    }

    protected function resolveCloseUrl(string $type): string
    {
        $resource = $type === 'sale' ? 'sales' : 'purchases';

        if (auth('staff')->check()) {
            return route("filament.user.resources.{$resource}.index");
        }

        if (auth('merchant')->check()) {
            return route("filament.merchant.resources.{$resource}.index");
        }

        if (\Illuminate\Support\Facades\Route::has("filament.admin.resources.{$resource}.index")) {
            return route("filament.admin.resources.{$resource}.index");
        }

        return url('/');
    }

    /**
     * @return array<string, string>
     */
    protected function invoiceGroupOptions(string $merchantId, string $section): array
    {
        $groups = InvoiceDynamicGroup::query()
            ->where('merchant_id', $merchantId)
            ->where('is_active', true)
            ->where('section', $section)
            ->orderBy('name')
            ->pluck('name', 'id');

        $defaultLabel = $section === 'header'
            ? 'Default (Current Header)'
            : 'Default (Current Footer)';

        $options = ['__default' => $defaultLabel];

        foreach ($groups as $groupId => $groupName) {
            $options[(string) $groupId] = trim((string) $groupName) ?: 'Details';
        }

        return $options;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    protected function adjacentInvoiceUrls(string $type, Sale|Purchase $record): array
    {
        $ids = match ($type) {
            'sale' => Sale::query()
                ->where('merchant_id', $record->merchant_id)
                ->orderBy('sale_date')
                ->orderBy('created_at')
                ->orderBy('id')
                ->pluck('id')
                ->values(),
            'purchase' => Purchase::query()
                ->where('merchant_id', $record->merchant_id)
                ->orderBy('purchase_date')
                ->orderBy('created_at')
                ->orderBy('id')
                ->pluck('id')
                ->values(),
            default => collect(),
        };

        $currentIndex = $ids->search((string) $record->id);
        if ($currentIndex === false) {
            return [null, null];
        }

        $combo = request()->query('combo');
        $header = request()->query('header');
        $footer = request()->query('footer');
        $buildUrl = function (?string $invoiceId) use ($type, $combo, $header, $footer): ?string {
            if (! $invoiceId) {
                return null;
            }

            $params = ['type' => $type, 'id' => $invoiceId];
            if (filled($combo)) {
                $params['combo'] = $combo;
            }
            if (filled($header)) {
                $params['header'] = $header;
            }
            if (filled($footer)) {
                $params['footer'] = $footer;
            }

            return route('invoices.show', $params);
        };

        $previousId = $ids->get($currentIndex - 1);
        $nextId = $ids->get($currentIndex + 1);

        return [$buildUrl($previousId), $buildUrl($nextId)];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    protected function splitCombo(string $combo): array
    {
        if (! str_contains($combo, '|')) {
            return [null, null];
        }

        [$header, $footer] = explode('|', $combo, 2);

        return [
            filled($header) ? $header : null,
            filled($footer) ? $footer : null,
        ];
    }
}
