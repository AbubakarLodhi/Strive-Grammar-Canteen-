<?php

namespace App\Support;

use Closure;
use Filament\Forms\Components\TextInput;
use Illuminate\Contracts\Validation\ValidationRule;

class PhoneFormFields
{
    public static function isBlank(?string $value): bool
    {
        $value = trim((string) $value);

        return $value === '' || $value === '+92';
    }

    public static function normalize(?string $value): ?string
    {
        $value = trim((string) $value);

        return self::isBlank($value) ? null : $value;
    }

    public static function optionalPakistanPhone(string $name = 'phone'): TextInput
    {
        return TextInput::make($name)
            ->label('Phone')
            ->tel()
            ->default('+92')
            ->placeholder('+923001234567')
            ->helperText('Optional. Enter number with country code, e.g. +923001234567')
            ->maxLength(15)
            ->nullable()
            ->rules([self::optionalPakistanPhoneRule()])
            ->dehydrateStateUsing(fn ($state) => self::normalize(is_string($state) ? $state : null))
            ->live(onBlur: true)
            ->afterStateUpdated(function ($state, callable $set, callable $get, $livewire) use ($name): void {
                $livewire->resetValidation('data.'.$name);
                $livewire->resetErrorBag('data.'.$name);
            });
    }

    public static function optionalPakistanPhoneRule(): ValidationRule
    {
        return new class implements ValidationRule
        {
            public function validate(string $attribute, mixed $value, Closure $fail): void
            {
                if (PhoneFormFields::isBlank(is_string($value) ? $value : null)) {
                    return;
                }

                if (! preg_match('/^\+92\d{10}$/', (string) $value)) {
                    $fail('Enter a valid phone with country code, e.g. +923001234567.');
                }
            }
        };
    }
}
