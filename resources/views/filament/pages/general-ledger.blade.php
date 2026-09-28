<x-filament-panels::page>
    @if ($account = $this->getSelectedAccount())
        <div class="mb-4 flex items-center justify-between gap-4">
            <div>
                <div class="text-sm text-gray-500">Account ledger</div>
                <div class="text-lg font-semibold">{{ $account->code }} — {{ $account->name }}</div>
            </div>
            <x-filament::button color="gray" wire:click="clearAccount">
                Back to accounts
            </x-filament::button>
        </div>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
