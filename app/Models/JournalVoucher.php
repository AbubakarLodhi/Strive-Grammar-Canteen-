<?php

namespace App\Models;

use App\Enums\FinanceDocumentStatus;
use Database\Factories\JournalVoucherFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;

class JournalVoucher extends Model implements Auditable
{
    /** @use HasFactory<JournalVoucherFactory> */
    use HasFactory;

    use HasUuids;
    use \OwenIt\Auditing\Auditable;
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'merchant_id',
        'voucher_no',
        'voucher_date',
        'narration',
        'status',
        'posted_at',
        'created_by',
        'vendor_id',
        'source_type',
        'source_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'voucher_date' => 'date',
            'status' => FinanceDocumentStatus::class,
            'posted_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalVoucherLine::class)->orderBy('sort_order');
    }

    public function isPosted(): bool
    {
        return $this->status === FinanceDocumentStatus::Posted;
    }

    public function totalDebit(): string
    {
        return number_format((float) $this->lines->sum('debit'), 2, '.', '');
    }

    public function totalCredit(): string
    {
        return number_format((float) $this->lines->sum('credit'), 2, '.', '');
    }

    public function isBalanced(): bool
    {
        return $this->totalDebit() === $this->totalCredit()
            && (float) $this->totalDebit() > 0;
    }
}
