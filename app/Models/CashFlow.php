<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CashFlow extends Model
{
    use HasUuids;
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'merchant_id',
        'business_id',
        'branch_id',
        'party_type',
        'party_id',
        'settlement_for_id',
        'flow_type',
        'direction',
        'amount',
        'flow_date',
        'method',
        'ledger_account_id',
        'reference_no',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'flow_date' => 'date',
    ];

    protected static function booted(): void
    {
        static::deleting(function (self $cashFlow): void {
            if ($cashFlow->settlement_for_id !== null) {
                return;
            }

            $settlements = $cashFlow->settlements();

            if ($cashFlow->isForceDeleting()) {
                $settlements->withTrashed()->get()->each->forceDelete();

                return;
            }

            $settlements->withoutTrashed()->get()->each->delete();
        });

        static::restoring(function (self $cashFlow): void {
            if ($cashFlow->settlement_for_id !== null) {
                return;
            }

            $cashFlow->settlements()->withTrashed()->restore();
        });
    }

    public function scopeActiveLedger(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->whereNull('settlement_for_id')
                ->orWhereHas('settlementFor');
        });
    }

    public static function methodLabels(): array
    {
        return [
            'Cash' => 'Cash',
            'Bank' => 'Bank',
        ];
    }

    public static function flowTypeLabels(): array
    {
        return [
            'advance' => 'Account Payable',
            'loan' => 'Account Receivable',
        ];
    }

    public static function flowTypeLabel(?string $flowType, string $default = '-'): string
    {
        return self::flowTypeLabels()[$flowType] ?? $default;
    }

    public static function primaryDirectionForFlowType(?string $flowType): string
    {
        return $flowType === 'loan' ? 'out' : 'in';
    }

    public static function settlementDirectionForFlowType(?string $flowType): string
    {
        return self::primaryDirectionForFlowType($flowType) === 'in' ? 'out' : 'in';
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    public function party(): MorphTo
    {
        return $this->morphTo();
    }

    public function settlementFor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'settlement_for_id');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(self::class, 'settlement_for_id');
    }

    public function expectedPrimaryDirection(): string
    {
        return self::primaryDirectionForFlowType($this->flow_type);
    }

    public function isPrimaryTransaction(): bool
    {
        return $this->settlement_for_id === null && $this->direction === $this->expectedPrimaryDirection();
    }
}
