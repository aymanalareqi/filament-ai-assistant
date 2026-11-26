@persist('filament-ai-assistant-'.$chatScope)
    @livewire('filament-ai-assistant.chat')
@endpersist

@if ($actionComponent = request()->attributes->get('filament-ai-assistant.action-component'))
    <span hidden data-fia-action-modal x-data
        x-init="$nextTick(() => $dispatch('sync-action-modals', { id: @js($actionComponent), newActionNestingIndex: 0 }))"></span>
@endif
