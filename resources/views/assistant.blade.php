<x-filament-panels::page>
    <div class="fia-chat">
        <x-filament::section>
            <x-slot name="heading">{{ __('filament-ai-assistant::assistant.title') }}</x-slot>
            <x-slot name="description">{{ __('filament-ai-assistant::assistant.intro') }}</x-slot>

            @if (count($history))
                <div class="fia-messages" role="log" aria-live="polite">
                    @foreach ($history as $entry)
                        <div @class(['fia-message', 'fia-message-user' => $entry['role'] === 'user']) dir="auto">
                            <strong>{{ __('filament-ai-assistant::assistant.'.($entry['role'] === 'user' ? 'you' : 'title')) }}</strong>
                            <div>{{ $entry['text'] }}</div>
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($draft)
                <div class="fia-draft">
                    <strong>{{ __('filament-ai-assistant::assistant.draft_for', ['resource' => $draft['label']]) }}</strong>
                    <dl>
                        @foreach ($draft['fields'] as $field)
                            <div class="fia-change">
                                <dt>{{ $field['label'] }}</dt>
                                <dd><small>{{ __('filament-ai-assistant::assistant.before') }}</small><span dir="auto">{{ $field['current'] }}</span></dd>
                                <dd><small>{{ __('filament-ai-assistant::assistant.after') }}</small><span dir="auto">{{ $field['proposed'] }}</span></dd>
                            </div>
                        @endforeach
                    </dl>
                    <x-filament::button tag="a" :href="$draft['url']">
                        {{ __('filament-ai-assistant::assistant.review') }}
                    </x-filament::button>
                </div>
            @endif

            <form class="fia-compose" wire:submit="send">
                <label for="fia-message">{{ __('filament-ai-assistant::assistant.message') }}</label>
                <textarea id="fia-message" wire:model="message" rows="3" maxlength="2000" dir="auto"
                    placeholder="{{ __('filament-ai-assistant::assistant.placeholder') }}"></textarea>
                @error('message')
                    <p role="alert" class="fia-error">{{ $message }}</p>
                @enderror
                <div class="fia-actions">
                    <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="send">
                        {{ __('filament-ai-assistant::assistant.send') }}
                    </x-filament::button>
                    <x-filament::button type="button" color="gray" wire:click="clearChat" wire:loading.attr="disabled">
                        {{ __('filament-ai-assistant::assistant.clear') }}
                    </x-filament::button>
                    <span role="status" wire:loading wire:target="send">{{ __('filament-ai-assistant::assistant.working') }}</span>
                </div>
            </form>
        </x-filament::section>
    </div>
</x-filament-panels::page>
