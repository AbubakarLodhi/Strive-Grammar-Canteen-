<?php

use App\Http\Controllers\Asset\AssetPreviewController;
use App\Http\Controllers\DemoAccountController;
use App\Http\Controllers\DemoExitController;
use App\Http\Controllers\Finance\BankDepositSlipController;
use App\Http\Controllers\Invoice\InvoiceController;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Merchant;
use App\Support\ProductStockAvailability;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/merchant/login')->name('home');
Route::get('/demo/login', DemoAccountController::class)->name('demo.login');
Route::get('/demo/exit', DemoExitController::class)->name('demo.exit');

Route::get('/bank-deposits/{id}/slip', [BankDepositSlipController::class, 'show'])
    ->middleware(['web', 'auth.staff_or_merchant'])
    ->name('bank-deposits.slip');

Route::get('/invoices/{type}/{id}', [InvoiceController::class, 'show'])
    ->middleware(['web', 'auth.staff_or_merchant'])
    ->name('invoices.show');

Route::post('/invoices/{type}/{id}/slip-number', [InvoiceController::class, 'nextSlipNumber'])
    ->middleware(['web', 'auth.staff_or_merchant'])
    ->name('invoices.slip-number');

Route::get('/assets/preview/{id}', [AssetPreviewController::class, 'show'])
    ->middleware(['web', 'auth.staff_or_merchant'])
    ->name('assets.preview');

// POS product search endpoint
Route::get('/pos/products', function (Request $request) {
    $user = auth('staff')->user() ?? auth('merchant')->user();

    if (! $user) {
        return response()->json([]);
    }

    $merchantId = $user instanceof Merchant
        ? $user->id
        : $user->merchant_id;

    return response()->json(
        ProductStockAvailability::posProductsForMerchant(
            (string) $merchantId,
            $request->get('search'),
            $request->get('category_id'),
            inStockOnly: false,
        )
    );
})->middleware(['web', 'auth.staff_or_merchant'])->name('pos.products');

// POS variants endpoint
Route::get('/pos/variants', function (Request $request) {
    $productId = $request->get('product_id');
    if (! $productId) {
        return response()->json([]);
    }

    return response()->json(
        ProductStockAvailability::posVariantsForProduct(
            (string) $productId,
            $request->get('branch_id'),
            inStockOnly: false,
        )
    );
})->middleware(['web', 'auth.staff_or_merchant'])->name('pos.variants');

// POS branches endpoint
Route::get('/pos/branches', function (Request $request) {
    $productId = $request->get('product_id');
    if (! $productId) {
        return response()->json([]);
    }

    $user = auth('staff')->user() ?? auth('merchant')->user();
    if (! $user) {
        return response()->json([]);
    }

    $merchantId = $user instanceof Merchant
        ? $user->id
        : $user->merchant_id;

    $hasBranchAssignments = DB::table('branch_products')
        ->where('product_id', $productId)
        ->exists();

    $query = Branch::query()
        ->withoutTrashed()
        ->where('merchant_id', $merchantId);

    if ($hasBranchAssignments) {
        $query->whereExists(fn ($q) => $q->selectRaw(1)
            ->from('branch_products')
            ->whereColumn('branch_products.branch_id', 'branches.id')
            ->where('branch_products.product_id', $productId)
        );
    }

    return response()->json(
        $query->orderBy('name')->get(['id', 'name'])
    );
})->middleware(['web', 'auth.staff_or_merchant'])->name('pos.branches');

// POS categories endpoint - only shows categories that have products
// POS categories endpoint
Route::get('/pos/categories', function (Request $request) {
    $user = auth('staff')->user() ?? auth('merchant')->user();
    if (! $user) {
        return response()->json([]);
    }

    $merchantId = $user instanceof Merchant
        ? $user->id
        : $user->merchant_id;

    return response()->json(
        Category::query()
            ->where('merchant_id', $merchantId)
            ->whereNull('parent_id')
            ->whereExists(fn ($q) => $q->selectRaw(1)
                ->from('products')
                ->whereColumn('products.category_id', 'categories.id')
                ->where('products.merchant_id', $merchantId)
                ->where('products.is_active', true)
                ->whereNull('products.deleted_at')
            )
            ->orderBy('name')
            ->get(['id', 'name'])
    );
})->middleware(['web', 'auth.staff_or_merchant'])->name('pos.categories');
