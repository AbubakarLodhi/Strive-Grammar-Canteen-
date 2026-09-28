<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

class Expense extends Model implements Auditable
{
    use HasUuids;
    use \OwenIt\Auditing\Auditable;

    /** @var bool */
    public $incrementing = false;

    /** @var string[] */
    protected $fillable = [
        'merchant_id', 'business_id', 'branch_id', 'expense_account_id', 'paid_from_account_id',
        'expense_no', 'expense_date', 'subtotal', 'discount',
        'tax', 'total_amount', 'notes', 'created_by',
    ];

    /** @var string */
    protected $keyType = 'string';

    /** @var string[] */
    protected $casts = [
        'expense_date' => 'date',
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'tax' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'expense_account_id');
    }

    public function paidFromAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'paid_from_account_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ExpenseItem::class);
    }
}
