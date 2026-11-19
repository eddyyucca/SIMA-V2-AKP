<x-filament-panels::page>
    <x-filament::tabs>
        @foreach($this->tabs() as $key => [$label, $icon])
            <x-filament::tabs.item :active="$activeTab === $key" :icon="$icon" wire:click="$set('activeTab', '{{ $key }}')">{{ $label }}</x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>

    {{ $this->table }}
</x-filament-panels::page>
