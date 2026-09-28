<?php

namespace App\Models;

use App\Enums\ChequeDirection;
use App\Enums\ChequeStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;

class BankCheque extends Model implements Auditable
{
    use HasUuids;
    use \OwenIt\Auditing\Auditable;
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'merchant_id',
        'direction',
        'bank_account_id',
        'cheque_book_id',
        'cheque_number',
        'cheque_date',
        'amount',
        'payee_name',
        'payer_name',
        'customer_id',
        'vendor_id',
        'counter_account_id',
        'status',
        'cleared_at',
        'bounced_at',
        'journal_voucher_id',
        'reversal_voucher_id',
        'notes',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'direction' => ChequeDirection::class,
            'status' => ChequeStatus::class,
            'cheque_date' => 'date',
            'amount' => 'decimal:2',
            'cleared_at' => 'datetime',
            'bounced_at' => 'datetime',
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

    public function chequeBook(): BelongsTo
    {
        return $this->belongsTo(ChequeBook::class);
    }

    public function counterAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'counter_account_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function journalVoucher(): BelongsTo
    {
        return $this->belongsTo(JournalVoucher::class);
    }

    public function reversalVoucher(): BelongsTo
    {
        return $this->belongsTo(JournalVoucher::class, 'reversal_voucher_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPending(): bool
    {
        return $this->status === ChequeStatus::Pending;
    }

    public function isCleared(): bool
    {
        return $this->status === ChequeStatus::Cleared;
    }

    public function isOutgoing(): bool
    {
        return $this->direction === ChequeDirection::Outgoing;
    }

    public function isIncoming(): bool
    {
        return $this->direction === ChequeDirection::Incoming;
    }
}
