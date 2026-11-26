<div
    class="fia-widget"
    data-filament-ai-assistant
    x-data="{
        open: false,
        showChat() {
            this.open = true;
            this.$nextTick(() => {
                this.scrollToLatest();
                this.$refs.dialog.focus({ preventScroll: true });
            });
        },
        closeChat() {
            this.open = false;
            this.$refs.launcher.focus({ preventScroll: true });
        },
        scrollToLatest() {
            this.$refs.thread.scrollTop = this.$refs.thread.scrollHeight;
        },
    }"
    x-on:keydown.escape.stop="if (open) closeChat()"
    x-on:filament-ai-assistant-response.window="$nextTick(() => scrollToLatest())"
>
    <section
        id="fia-chat-{{ $this->getId() }}"
        class="fia-popover"
        role="dialog"
        aria-labelledby="fia-title-{{ $this->getId() }}"
        tabindex="-1"
        x-ref="dialog"
        x-show="open"
        x-cloak
    >
        <header class="fia-header">
            <h2 id="fia-title-{{ $this->getId() }}">{{ __('filament-ai-assistant::assistant.title') }}</h2>
            <button type="button" class="fia-close" x-on:click="closeChat()" aria-label="{{ __('filament-ai-assistant::assistant.close') }}">
                <x-filament::icon icon="heroicon-m-x-mark" class="fia-icon" aria-hidden="true" />
            </button>
        </header>

        <div class="fia-thread" x-ref="thread">
            @if (count($history))
                <div class="fia-messages" role="log" aria-live="polite">
                    @foreach ($history as $entry)
                        <div @class(['fia-message', 'fia-message-user' => $entry['role'] === 'user']) dir="auto">
                            <strong>{{ __('filament-ai-assistant::assistant.'.($entry['role'] === 'user' ? 'you' : 'title')) }}</strong>
                            <div>{{ $entry['text'] }}</div>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="fia-intro">{{ __('filament-ai-assistant::assistant.intro') }}</p>
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
        </div>

        <form class="fia-compose" wire:submit="send">
            <label for="fia-message-{{ $this->getId() }}">{{ __('filament-ai-assistant::assistant.message') }}</label>
            <textarea id="fia-message-{{ $this->getId() }}" wire:model="message" rows="2" maxlength="2000" dir="auto"
                placeholder="{{ __('filament-ai-assistant::assistant.placeholder') }}"></textarea>
            @error('message')
                <p role="alert" class="fia-error">{{ $message }}</p>
            @enderror
            <div class="fia-actions">
                <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="send">
                    {{ __('filament-ai-assistant::assistant.send') }}
                </x-filament::button>
                @if (count($history) || $draft)
                    <x-filament::button type="button" color="gray" wire:click="clearChat" wire:loading.attr="disabled">
                        {{ __('filament-ai-assistant::assistant.clear') }}
                    </x-filament::button>
                @endif
                <span role="status" wire:loading wire:target="send">{{ __('filament-ai-assistant::assistant.working') }}</span>
            </div>
        </form>
    </section>

    <button
        type="button"
        class="fia-launcher"
        x-ref="launcher"
        x-on:click="open ? closeChat() : showChat()"
        aria-label="{{ __('filament-ai-assistant::assistant.open') }}"
        x-bind:aria-label="open ? @js(__('filament-ai-assistant::assistant.close')) : @js(__('filament-ai-assistant::assistant.open'))"
        aria-expanded="false"
        x-bind:aria-expanded="open.toString()"
        aria-controls="fia-chat-{{ $this->getId() }}"
    >
        <x-filament::icon icon="heroicon-o-sparkles" class="fia-icon" x-show="!open" aria-hidden="true" />
        <x-filament::icon icon="heroicon-m-x-mark" class="fia-icon" x-show="open" x-cloak aria-hidden="true" />
    </button>
</div>
