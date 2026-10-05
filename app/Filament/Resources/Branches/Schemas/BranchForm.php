<?php

namespace App\Filament\Resources\Branches\Schemas;

use App\Models\Branch;
use App\Models\Merchant;
use App\Models\User;
use App\Support\GeoFormFields;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

class BranchForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->live()
                ->rules([
                    fn ($get) => Rule::unique('branches', 'name')
                        ->where(fn ($query) => $query->where('business_id', $get('business_id'))
                            ->whereNull('deleted_at')
                        )
                        ->ignore($get('id')),
                ])
                ->afterStateUpdated(function ($state, callable $set, $livewire) {
                    $livewire->resetValidation('data.name');
                    $livewire->resetErrorBag('data.name');
                }),
            Textarea::make('address')->columnSpanFull()->maxLength(400),

            TextInput::make('phone')
                ->label('Phone')
                ->tel()
                ->nullable()
                ->helperText('Optional')
                ->rules(['nullable', 'string', 'min:11', 'max:15'])
                ->dehydrateStateUsing(fn ($state) => filled($state) ? $state : null)
                ->maxLength(15),

            Select::make('status')
                ->options([
                    Branch::STATUS_PENDING => 'Pending',
                    Branch::STATUS_VERIFIED => 'Verified',
                    Branch::STATUS_REJECTED => 'Rejected',
                ])
                ->required()
               // ->default(Branch::STATUS_PENDING)
                ->live()
                ->afterStateUpdated(function (callable $set, $state) {
                    if ($state === Branch::STATUS_VERIFIED) {
                        $set('is_active', true);
                    } else {
                        $set('is_active', false);
                    }
                }),

            Select::make('countries')
                ->label('Countries')
                ->relationship('countries', 'name')
                ->multiple()
                ->options(fn (): array => GeoFormFields::countryOptions())
                ->default(fn (): array => GeoFormFields::defaultCountryIds())
                ->searchable()
                ->preload()
                ->required()
                ->live()
                ->afterStateUpdated(function (callable $set, $state, $old, $livewire) {
                    $set('cities', []);

                    $livewire->resetValidation('data.countries');
                    $livewire->resetErrorBag('data.countries');
                    $livewire->resetValidation('data.cities');
                    $livewire->resetErrorBag('data.cities');
                }),

            Select::make('cities')
                ->label('Cities')
                ->relationship(
                    'cities',
                    'name',
                    fn ($query, callable $get) => $query->whereIn('country_id', $get('countries') ?? [])
                )
                ->multiple()
                ->createOptionForm([
                    Select::make('country_id')
                        ->label('Country')
                        ->options(fn (): array => GeoFormFields::countryOptions())
                        ->default(fn (callable $get) => ($get('../../countries') ?? [])[0] ?? GeoFormFields::defaultCountryId())
                        ->searchable()
                        ->preload()
                        ->required(),
                    TextInput::make('name')
                        ->label('City Name')
                        ->required()
                        ->maxLength(255),
                ])
                ->createOptionUsing(fn (array $data): string => GeoFormFields::createCity($data))
                ->searchable()
                ->preload()
                ->live()
                ->afterStateUpdated(function ($state, callable $set, callable $get, $livewire) {
                    $livewire->resetValidation('data.cities');
                    $livewire->resetErrorBag('data.cities');
                }),

            TextInput::make('postal_code')
                ->label('Postal Code')
                ->placeholder('e.g. 54000')
                ->regex('/^\d{1,12}$/')
                ->minLength(5)
                ->maxLength(12)
                ->nullable()
                ->live()
                ->afterStateUpdated(function ($state, callable $set, $livewire) {
                    $livewire->resetValidation('data.postal_code');
                    $livewire->resetErrorBag('data.postal_code');
                }),

            // ✅ Business scoped for Admin / Merchant / Staff
            Select::make('business_id')
                ->label('Business')
                ->relationship(
                    name: 'business',
                    titleAttribute: 'name',
                    modifyQueryUsing: function (Builder $query) {
                        $user = Filament::auth()->user();
                        $query->where('status', true);
                        // Merchant => only their businesses
                        if ($user instanceof Merchant) {
                            $query->where('merchant_id', $user->id);

                            return;
                        }

                        // Staff => only businesses assigned via pivot
                        if ($user instanceof User) {
                            $allowedBusinessIds = $user->businesses()->pluck('businesses.id')->toArray();

                            // No businesses assigned => show none
                            $query->whereIn('id', $allowedBusinessIds ?: ['00000000-0000-0000-0000-000000000000']);
                        }
                    }
                )
                ->searchable()
                ->preload()
                ->required()
                ->live()
                ->afterStateUpdated(function ($state, callable $set, callable $get, $livewire) {
                    $livewire->resetValidation('data.business_id');
                    $livewire->resetErrorBag('data.business_id');
                }),

            Toggle::make('is_active')
                ->label('Active')
                ->default(false)
                ->disabled(fn (callable $get) => $get('status') !== Branch::STATUS_VERIFIED)
                ->dehydrated(true), // 🔑 FORCE saving even when disabled

        ]);
    }
}
