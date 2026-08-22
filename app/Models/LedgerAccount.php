<?php

namespace App\Models;

use App\Enums\FinanceDocumentStatus;
use App\Enums\LedgerAccountType;
use Database\Factories\LedgerAccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;

class LedgerAccount extends Model implements Auditable
{
    /** @use HasFactory<LedgerAccountFactory> */
    use HasFactory;

    use HasUuids;
    use \OwenIt\Auditing\Auditable;
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'merchant_id',
        'code',
        'name',
        'account_number',
        'type',
        'is_bank',
        'is_system',
        'is_active',
        'opening_balance',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => LedgerAccountType::class,
            'is_bank' => 'boolean',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'opening_balance' => 'decimal:2',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalVoucherLine::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeBanks(Builder $query): Builder
    {
        return $query->where('is_bank', true)->where('is_active', true);
    }

    public function bankLabel(): string
    {
        if (filled($this->account_number)) {
            return $this->name.' · '.$this->account_number;
        }

        return $this->name;
    }

    public function postedBalance(): string
    {
        $totals = $this->journalLines()
            ->whereHas('journalVoucher', function (Builder $query): void {
                $query->where('status', FinanceDocumentStatus::Posted->value);
            })
            ->selectRaw('COALESCE(SUM(debit), 0) as debit_total, COALESCE(SUM(credit), 0) as credit_total')
            ->first();

        $debit = (float) ($totals?->debit_total ?? 0);
        $credit = (float) ($totals?->credit_total ?? 0);
        $opening = (float) $this->opening_balance;

        $net = match ($this->type) {
            LedgerAccountType::Asset, LedgerAccountType::Expense => $opening + $debit - $credit,
            default => $opening + $credit - $debit,
        };

        return number_format($net, 2, '.', '');
    }
}
