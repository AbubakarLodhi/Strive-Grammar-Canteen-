<?php

namespace App\Filament\Resources\Activities\Support;

use App\Models\Merchant;
use App\Models\User;

class ActivityPerformer
{
    public static function resolve(object $record): string
    {
        $staffFromPayload = self::resolveStaffFromPayload($record);
        if ($staffFromPayload) {
            return $staffFromPayload;
        }

        if (($record->user_type ?? null) === User::class && $record->user?->name) {
            return (string) $record->user->name;
        }

        if ($record->user?->name && ($record->user_type ?? null) !== Merchant::class) {
            return (string) $record->user->name;
        }

        if ($record->user?->name) {
            return (string) $record->user->name;
        }

        return 'System';
    }

    protected static function resolveStaffFromPayload(object $record): ?string
    {
        $staffId = self::extractStaffIdFromAuditPayload($record);

        if (! $staffId) {
            return null;
        }

        $staff = User::query()->find($staffId);

        return $staff?->name ? (string) $staff->name : null;
    }

    protected static function extractStaffIdFromAuditPayload(object $record): ?string
    {
        $keys = ['updated_by', 'created_by', 'deleted_by', 'user_id'];

        $newValues = self::normalizeArray($record->new_values ?? null);
        $oldValues = self::normalizeArray($record->old_values ?? null);

        foreach ($keys as $key) {
            $candidate = $newValues[$key] ?? $oldValues[$key] ?? null;

            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    protected static function normalizeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
