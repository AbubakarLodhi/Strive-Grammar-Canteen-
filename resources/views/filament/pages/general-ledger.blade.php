<x-filament-panels::page>
    <div class="mb-4 grid grid-cols-1 gap-4 md:grid-cols-2">
        <div>
            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">From</label>
            <input
                type="date"
                wire:model.live="dateFrom"
                class="fi-input block w-full rounded-lg border-gray-300 shadow-sm dark:border-gray-600 dark:bg-gray-900"
            />
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">To</label>
            <input
                type="date"
                wire:model.live="dateTo"
                class="fi-input block w-full rounded-lg border-gray-300 shadow-sm dark:border-gray-600 dark:bg-gray-900"
            />
        </div>
    </div>

    @if ($account = $this->getSelectedAccount())
        <div class="mb-4 flex items-center justify-between gap-4">
            <div>
                <div class="text-sm text-gray-500">Account ledger</div>
                <div class="text-lg font-semibold">{{ $account->code }} — {{ $account->name }}</div>
                <div class="text-xs text-gray-500">
                    Showing posted lines
                    @if ($dateFrom || $dateTo)
                        ({{ $dateFrom ?: '…' }} → {{ $dateTo ?: '…' }})
                    @endif
                </div>
            </div>
            <x-filament::button color="gray" wire:click="clearAccount">
                Back to accounts
            </x-filament::button>
        </div>
    @else
        <p class="mb-4 text-sm text-gray-500">Click an account row (or Open) to view posted transactions for the selected dates.</p>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
