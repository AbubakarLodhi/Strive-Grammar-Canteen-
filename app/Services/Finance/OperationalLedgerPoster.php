<?php

namespace App\Services\Finance;

use App\Models\Expense;
use App\Models\Payroll;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleReturn;
use Illuminate\Database\Eloquent\Model;

class OperationalLedgerPoster
{
    public function __construct(private FinanceLedger $ledger) {}

    /**
     * @return list<array{code: string, debit: float, credit: float, description: string}>
     */
    public function saleLinePlan(float $total, float $paid, float $due, bool $paidToBank = false): array
    {
        $total = round(max(0, $total), 2);
        $paid = round(max(0, $paid), 2);
        $due = round(max(0, $due), 2);

        if (round($paid + $due, 2) !== $total) {
            $due = round(max(0, $total - $paid), 2);
            $paid = round(max(0, $total - $due), 2);
        }

        return $this->compactLines([
            ['code' => $paidToBank ? '1010' : '1000', 'debit' => $paid, 'credit' => 0, 'description' => 'Amount received'],
            ['code' => '1100', 'debit' => $due, 'credit' => 0, 'description' => 'Amount receivable'],
            ['code' => '4000', 'debit' => 0, 'credit' => $total, 'description' => 'Sales'],
        ]);
    }

    /**
     * @return list<array{code: string, debit: float, credit: float, description: string}>
     */
    public function purchaseLinePlan(float $total, float $paid, float $due, bool $paidFromBank = false): array
    {
        $total = round(max(0, $total), 2);
        $paid = round(max(0, $paid), 2);
        $due = round(max(0, $due), 2);

        if (round($paid + $due, 2) !== $total) {
            $due = round(max(0, $total - $paid), 2);
            $paid = round(max(0, $total - $due), 2);
        }

        return $this->compactLines([
            ['code' => '5000', 'debit' => $total, 'credit' => 0, 'description' => 'Purchases'],
            ['code' => $paidFromBank ? '1010' : '1000', 'debit' => 0, 'credit' => $paid, 'description' => 'Amount paid'],
            ['code' => '2000', 'debit' => 0, 'credit' => $due, 'description' => 'Amount payable'],
        ]);
    }

    /**
     * @return list<array{code: string, debit: float, credit: float, description: string}>
     */
    public function expenseLinePlan(float $total, bool $paidFromBank = false): array
    {
        $total = round(max(0, $total), 2);

        return $this->compactLines([
            ['code' => '5100', 'debit' => $total, 'credit' => 0, 'description' => 'Operating expense'],
            ['code' => $paidFromBank ? '1010' : '1000', 'debit' => 0, 'credit' => $total, 'description' => 'Expense paid'],
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
    public function purchaseReturnLinePlan(float $total, bool $refundFromBank = false, bool $creditVendor = false): array
    {
        $total = round(max(0, $total), 2);
        $settlementCode = $creditVendor ? '2000' : ($refundFromBank ? '1010' : '1000');

        return $this->compactLines([
            ['code' => $settlementCode, 'debit' => $total, 'credit' => 0, 'description' => 'Return settlement'],
            ['code' => '5000', 'debit' => 0, 'credit' => $total, 'description' => 'Purchase return'],
        ]);
    }

    public function syncSale(Sale $sale): void
    {
        $sale->loadMissing('payments');

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
            ),
            $sale->created_by,
        );
    }

    public function syncPurchase(Purchase $purchase): void
    {
        $purchase->loadMissing('payments');

        $this->postPlan(
            $purchase,
            $purchase->merchant_id,
            $purchase->purchase_date,
            'Purchase '.$purchase->purchase_no,
            $this->purchaseLinePlan(
                (float) $purchase->total_amount,
                (float) $purchase->paid_amount,
                (float) $purchase->due_amount,
                $this->documentUsesBank($purchase),
            ),
            $purchase->created_by,
        );
    }

    public function syncExpense(Expense $expense): void
    {
        $this->postPlan(
            $expense,
            $expense->merchant_id,
            $expense->expense_date,
            'Expense '.$expense->expense_no,
            $this->expenseLinePlan((float) $expense->total_amount),
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
        $return->loadMissing('purchase.payments');
        $purchase = $return->purchase;
        $creditVendor = $purchase?->payment_type === 'credit' && (float) ($purchase->due_amount ?? 0) > 0;

        $this->postPlan(
            $return,
            $return->merchant_id,
            $return->return_date,
            'Purchase return '.$return->return_no,
            $this->purchaseReturnLinePlan(
                (float) $return->total_amount,
                $purchase ? $this->documentUsesBank($purchase) : false,
                $creditVendor,
            ),
            $return->created_by,
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

        $this->ledger->postOrReplaceForSource($source, $merchantId, $date, $narration, $lines, $createdBy);
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
