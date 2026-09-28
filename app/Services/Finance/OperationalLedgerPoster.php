<?php

namespace App\Services\Finance;

use App\Models\Expense;
use App\Models\Payroll;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Services\Inventory\CanteenStockImporter;
use Illuminate\Database\Eloquent\Model;

class OperationalLedgerPoster
{
    public function __construct(private FinanceLedger $ledger) {}

    /**
     * @return list<array{code: string, debit: float, credit: float, description: string}>
     */
    public function saleLinePlan(
        float $total,
        float $paid,
        float $due,
        bool $paidToBank = false,
        float $cogs = 0,
    ): array {
        $total = round(max(0, $total), 2);
        $paid = round(max(0, $paid), 2);
        $due = round(max(0, $due), 2);
        $cogs = round(max(0, $cogs), 2);

        if (round($paid + $due, 2) !== $total) {
            $due = round(max(0, $total - $paid), 2);
            $paid = round(max(0, $total - $due), 2);
        }

        $lines = [
            ['code' => $paidToBank ? '1010' : '1000', 'debit' => $paid, 'credit' => 0, 'description' => 'Amount received'],
            ['code' => '1100', 'debit' => $due, 'credit' => 0, 'description' => 'Amount receivable'],
            ['code' => '4000', 'debit' => 0, 'credit' => $total, 'description' => 'Sales'],
        ];

        if ($cogs > 0) {
            $lines[] = ['code' => FinanceLedger::COGS_ACCOUNT_CODE, 'debit' => $cogs, 'credit' => 0, 'description' => 'Cost of goods sold'];
            $lines[] = ['code' => FinanceLedger::INVENTORY_ACCOUNT_CODE, 'debit' => 0, 'credit' => $cogs, 'description' => 'Inventory issued'];
        }

        return $this->compactLines($lines);
    }

    /**
     * @return list<array{code: string, debit: float, credit: float, description: string}>
     */
    public function purchaseLinePlan(
        float $total,
        float $paid,
        float $due,
        bool $paidFromBank = false,
        ?string $vendorName = null,
        string $payableCode = '2000',
    ): array {
        $total = round(max(0, $total), 2);
        $paid = round(max(0, $paid), 2);
        $due = round(max(0, $due), 2);

        if (round($paid + $due, 2) !== $total) {
            $due = round(max(0, $total - $paid), 2);
            $paid = round(max(0, $total - $due), 2);
        }

        $vendorSuffix = filled($vendorName) ? ' — '.$vendorName : '';

        return $this->compactLines([
            ['code' => FinanceLedger::INVENTORY_ACCOUNT_CODE, 'debit' => $total, 'credit' => 0, 'description' => 'Inventory'.$vendorSuffix],
            ['code' => $paidFromBank ? '1010' : '1000', 'debit' => 0, 'credit' => $paid, 'description' => 'Amount paid'.$vendorSuffix],
            ['code' => $payableCode, 'debit' => 0, 'credit' => $due, 'description' => 'Amount payable'.$vendorSuffix],
        ]);
    }

    /**
     * @return list<array{code: string, debit: float, credit: float, description: string}>
     */
    public function expenseLinePlan(
        float $total,
        bool $paidFromBank = false,
        ?string $expenseCode = null,
        ?string $paidFromCode = null,
    ): array {
        $total = round(max(0, $total), 2);
        $expenseCode = $expenseCode ?: '5100';
        $paidFromCode = $paidFromCode ?: ($paidFromBank ? '1010' : '1000');

        return $this->compactLines([
            ['code' => $expenseCode, 'debit' => $total, 'credit' => 0, 'description' => 'Operating expense'],
            ['code' => $paidFromCode, 'debit' => 0, 'credit' => $total, 'description' => 'Expense paid'],
        ]);
    }

    /**
     * @return list<array{code: string, debit: float, credit: float, description: string}>
     */
    public function payrollLinePlan(float $netSalary, bool $paidFromBank = false): array
    {
        $netSalary = round(max(0, $netSalary), 2);

        return $this->compactLines([
            ['code' => '5200', 'debit' => $netSalary, 'credit' => 0, 'description' => 'Payroll'],
            ['code' => $paidFromBank ? '1010' : '1000', 'debit' => 0, 'credit' => $netSalary, 'description' => 'Salary paid'],
        ]);
    }

    /**
     * @return list<array{code: string, debit: float, credit: float, description: string}>
     */
    public function saleReturnLinePlan(float $total, bool $refundToBank = false, bool $creditCustomer = false): array
    {
        $total = round(max(0, $total), 2);
        $settlementCode = $creditCustomer ? '1100' : ($refundToBank ? '1010' : '1000');

        return $this->compactLines([
            ['code' => '4000', 'debit' => $total, 'credit' => 0, 'description' => 'Sales return'],
            ['code' => $settlementCode, 'debit' => 0, 'credit' => $total, 'description' => 'Return settlement'],
        ]);
    }

    /**
     * @return list<array{code: string, debit: float, credit: float, description: string}>
     */
    public function purchaseReturnLinePlan(
        float $total,
        bool $refundFromBank = false,
        bool $creditVendor = false,
        ?string $vendorName = null,
        string $payableCode = '2000',
    ): array {
        $total = round(max(0, $total), 2);
        $settlementCode = $creditVendor ? $payableCode : ($refundFromBank ? '1010' : '1000');
        $vendorSuffix = filled($vendorName) ? ' — '.$vendorName : '';

        return $this->compactLines([
            ['code' => $settlementCode, 'debit' => $total, 'credit' => 0, 'description' => 'Return settlement'.$vendorSuffix],
            ['code' => FinanceLedger::INVENTORY_ACCOUNT_CODE, 'debit' => 0, 'credit' => $total, 'description' => 'Inventory return'.$vendorSuffix],
        ]);
    }

    public function syncSale(Sale $sale): void
    {
        if ($sale->isDraft()) {
            $this->ledger->removeForSource($sale);

            return;
        }

        $sale->loadMissing(['payments', 'items.product']);

        $cogs = round($sale->items->sum(function ($item): float {
            $unitCost = (float) ($item->product?->purchase_price ?? 0);

            return $unitCost * (float) ($item->quantity ?? 0);
        }), 2);

        $this->postPlan(
            $sale,
            $sale->merchant_id,
            $sale->sale_date,
            'Sale '.$sale->sale_no,
            $this->saleLinePlan(
                (float) $sale->total_amount,
                (float) $sale->paid_amount,
                (float) $sale->due_amount,
                $this->documentUsesBank($sale),
                $cogs,
            ),
            $sale->created_by,
        );
    }

    public function syncPurchase(Purchase $purchase): void
    {
        if (CanteenStockImporter::isOpeningStockPurchase($purchase)) {
            $this->ledger->removeForSource($purchase);

            return;
        }

        $purchase->loadMissing(['payments', 'vendor']);
        $vendorName = $this->partyName($purchase->vendor?->name);
        $payableCode = '2000';

        if ($purchase->vendor) {
            $payableCode = $this->ledger->ensureVendorPayableAccount($purchase->vendor)->code;
        }

        $this->postPlan(
            $purchase,
            $purchase->merchant_id,
            $purchase->purchase_date,
            'Purchase '.$purchase->purchase_no.($vendorName ? ' — '.$vendorName : ''),
            $this->purchaseLinePlan(
                (float) $purchase->total_amount,
                (float) $purchase->paid_amount,
                (float) $purchase->due_amount,
                $this->documentUsesBank($purchase),
                $vendorName,
                $payableCode,
            ),
            $purchase->created_by,
            $purchase->vendor_id,
        );
    }

    public function syncExpense(Expense $expense): void
    {
        $expense->loadMissing(['expenseAccount', 'paidFromAccount']);

        $expenseCode = $expense->expenseAccount?->code ?: '5100';
        $paidFromCode = $expense->paidFromAccount?->code ?: '1000';

        $this->postPlan(
            $expense,
            $expense->merchant_id,
            $expense->expense_date,
            'Expense '.$expense->expense_no,
            $this->expenseLinePlan((float) $expense->total_amount, false, $expenseCode, $paidFromCode),
            $expense->created_by,
        );
    }

    public function syncPayroll(Payroll $payroll): void
    {
        if ($payroll->status !== Payroll::STATUS_PAID || (float) $payroll->net_salary <= 0) {
            $this->ledger->removeForSource($payroll);

            return;
        }

        $this->postPlan(
            $payroll,
            $payroll->merchant_id,
            $payroll->payment_date ?? now(),
            'Payroll '.$payroll->payroll_no,
            $this->payrollLinePlan((float) $payroll->net_salary),
            $payroll->created_by,
        );
    }

    public function syncSaleReturn(SaleReturn $return): void
    {
        $return->loadMissing('sale.payments');
        $sale = $return->sale;
        $creditCustomer = $sale?->payment_type === 'credit' && (float) ($sale->due_amount ?? 0) > 0;

        $this->postPlan(
            $return,
            $return->merchant_id,
            $return->return_date,
            'Sale return '.$return->return_no,
            $this->saleReturnLinePlan(
                (float) $return->total_amount,
                $sale ? $this->documentUsesBank($sale) : false,
                $creditCustomer,
            ),
            $return->created_by,
        );
    }

    public function syncPurchaseReturn(PurchaseReturn $return): void
    {
        $return->loadMissing('purchase.payments', 'purchase.vendor');
        $purchase = $return->purchase;
        $creditVendor = $purchase?->payment_type === 'credit' && (float) ($purchase->due_amount ?? 0) > 0;
        $vendorName = $this->partyName($purchase?->vendor?->name);
        $payableCode = '2000';

        if ($purchase?->vendor) {
            $payableCode = $this->ledger->ensureVendorPayableAccount($purchase->vendor)->code;
        }

        $this->postPlan(
            $return,
            $return->merchant_id,
            $return->return_date,
            'Purchase return '.$return->return_no.($vendorName ? ' — '.$vendorName : ''),
            $this->purchaseReturnLinePlan(
                (float) $return->total_amount,
                $purchase ? $this->documentUsesBank($purchase) : false,
                $creditVendor,
                $vendorName,
                $payableCode,
            ),
            $return->created_by,
            $purchase?->vendor_id,
        );
    }

    public function forget(Model $source): void
    {
        $this->ledger->removeForSource($source);
    }

    public function isBankMethod(?string $method): bool
    {
        $method = strtolower(trim((string) $method));

        if ($method === '') {
            return false;
        }

        return str_contains($method, 'bank')
            || str_contains($method, 'transfer')
            || str_contains($method, 'online')
            || str_contains($method, 'card');
    }

    /**
     * @param  list<array{code: string, debit: float, credit: float, description: string}>  $plan
     */
    private function postPlan(
        Model $source,
        string $merchantId,
        mixed $date,
        string $narration,
        array $plan,
        ?string $createdBy,
        ?string $vendorId = null,
    ): void {
        $lines = [];

        foreach ($plan as $line) {
            $account = $this->ledger->accountByCode($merchantId, $line['code']);
            $lines[] = [
                'ledger_account_id' => $account->id,
                'debit' => $line['debit'],
                'credit' => $line['credit'],
                'description' => $line['description'],
            ];
        }

        $this->ledger->postOrReplaceForSource($source, $merchantId, $date, $narration, $lines, $createdBy, $vendorId);
    }

    protected function partyName(?string $name): ?string
    {
        $name = trim((string) $name);

        return $name !== '' ? $name : null;
    }

    private function documentUsesBank(Sale|Purchase $document): bool
    {
        foreach ($document->payments as $payment) {
            if ($this->isBankMethod($payment->method)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{code: string, debit: float, credit: float, description: string}>  $lines
     * @return list<array{code: string, debit: float, credit: float, description: string}>
     */
    private function compactLines(array $lines): array
    {
        return array_values(array_filter(
            $lines,
            fn (array $line): bool => round($line['debit'], 2) > 0 || round($line['credit'], 2) > 0
        ));
    }
}
