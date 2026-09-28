<?php

namespace App\Models;

use App\Models\SaleReturn;
use App\Services\CreditReminderScheduler;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;

class Sale extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;
    use HasUuids;
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    /** @var bool $incrementing */
    public $incrementing = false;

    /** @var list<string> $fillable */
    protected $fillable = [
        'merchant_id',
        'customer_id',
        'sale_no',
        'sale_date',
        'subtotal',
        'total_amount',
        'paid_amount',
        'due_amount',
        'notes',
        'created_by',
        'payment_type',
        'due_date',
        'status',
        'posted_at',
        'posted_by',
    ];

    /** @var string $keyType */
    protected $keyType = 'string';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sale_date' => 'date',
            'due_date' => 'date',
            'posted_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'due_amount' => 'decimal:2',
            'payment_type' => 'string',
            'status' => 'string',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function activeCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class);
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'paymentable');
    }

    public function creditReminders(): HasMany
    {
        return $this->hasMany(CreditReminder::class);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function isCreditWithBalance(): bool
    {
        return $this->payment_type === 'credit' && (float) $this->due_amount > 0;
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where($query->getModel()->getTable().'.status', self::STATUS_POSTED);
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where($query->getModel()->getTable().'.status', self::STATUS_DRAFT);
    }

    protected static function booted(): void
    {
        static::saved(function (self $sale): void {
            if ($sale->isDraft()) {
                app(CreditReminderScheduler::class)->deactivateSaleReminders($sale);

                return;
            }

            $scheduler = app(CreditReminderScheduler::class);

            if ($sale->payment_type === 'credit') {
                $scheduler->syncSaleReminders($sale);
            } else {
                $scheduler->deactivateSaleReminders($sale);
            }
        });

        static::deleting(function (self $sale): void {
            if ($sale->isForceDeleting()) {
                $sale->returns()
                    ->withTrashed()
                    ->with('items.variants')
                    ->get()
                    ->each(fn (SaleReturn $return): bool|null => $return->forceDelete());

                return;
            }

            $sale->returns()->get()->each->delete();
        });

        static::restoring(function (self $sale): void {
            $sale->returns()->onlyTrashed()->get()->each->restore();
        });
    }
}
