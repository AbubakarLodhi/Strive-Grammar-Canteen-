<?php

namespace App\Models;

use App\Enums\FinanceDocumentStatus;
use Database\Factories\BankDepositFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;

class BankDeposit extends Model implements Auditable
{
    /** @use HasFactory<BankDepositFactory> */
    use HasFactory;

    use HasUuids;
    use \OwenIt\Auditing\Auditable;
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'merchant_id',
        'deposit_no',
        'deposit_date',
        'bank_account_id',
        'source_account_id',
        'amount',
        'reference_no',
        'notes',
        'status',
        'journal_voucher_id',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'deposit_date' => 'date',
            'amount' => 'decimal:2',
            'status' => FinanceDocumentStatus::class,
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'bank_account_id');
    }

    public function sourceAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'source_account_id');
    }

    public function journalVoucher(): BelongsTo
    {
        return $this->belongsTo(JournalVoucher::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPosted(): bool
    {
        return $this->status === FinanceDocumentStatus::Posted;
    }
}
