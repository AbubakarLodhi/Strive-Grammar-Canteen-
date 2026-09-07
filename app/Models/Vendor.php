<?php

namespace App\Models;

use OwenIt\Auditing\Contracts\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Vendor extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;
    use HasUuids;
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'merchant_id',
        'name',
        'phone',
        'email',
        'reference',
        'country_id',
        'city_id',
        'postal_code',
        'address',
        'occupation',
    ];

    protected static function booted(): void
    {
        static::saving(function (Vendor $vendor): void {
            if (! filled($vendor->postal_code)) {
                $vendor->postal_code = '54000';
            }
        });
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_id');
    }

    public function businesses(): BelongsToMany
    {
        return $this->belongsToMany(
            Business::class,
            'vendor_businesses',
        )->withTimestamps();
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(
            Branch::class,
            'vendor_branches',
        )
            ->withPivot('business_id')
            ->withTimestamps();
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'party');
    }

    public function cashFlows(): MorphMany
    {
        return $this->morphMany(CashFlow::class, 'party');
    }
}
