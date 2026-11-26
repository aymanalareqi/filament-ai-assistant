@persist('filament-ai-assistant-'.$chatScope)
    @livewire('filament-ai-assistant.chat')
@endpersist

@if ($recordContext = request()->attributes->get('filament-ai-assistant.current-record'))
    <span hidden data-fia-record-context data-token="{{ $recordContext['token'] }}"
        data-record-label="{{ $recordContext['record_label'] }}" data-resource-label="{{ $recordContext['resource_label'] }}"></span>
@endif

@if ($listContext = request()->attributes->get('filament-ai-assistant.current-list'))
    <span hidden data-fia-list-context data-token="{{ $listContext['token'] }}"
        data-resource-label="{{ $listContext['label'] }}"></span>
@endif

@if ($actionComponent = request()->attributes->get('filament-ai-assistant.action-component'))
    <span hidden data-fia-action-modal x-data
        x-init="$nextTick(() => $dispatch('sync-action-modals', { id: @js($actionComponent), newActionNestingIndex: 0 }))"></span>
@endif
