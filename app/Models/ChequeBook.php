<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;

class ChequeBook extends Model implements Auditable
{
    use HasUuids;
    use \OwenIt\Auditing\Auditable;
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'merchant_id',
        'bank_account_id',
        'book_no',
        'start_number',
        'end_number',
        'next_number',
        'is_active',
        'notes',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'start_number' => 'integer',
            'end_number' => 'integer',
            'next_number' => 'integer',
            'is_active' => 'boolean',
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

    public function cheques(): HasMany
    {
        return $this->hasMany(BankCheque::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function hasAvailableLeaves(): bool
    {
        return $this->next_number <= $this->end_number;
    }

    public function label(): string
    {
        $bank = $this->bankAccount?->bankLabel() ?? 'Bank';

        return $this->book_no.' ('.$bank.') '.$this->next_number.'–'.$this->end_number;
    }
}
