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
        usePrompt(prompt) {
            this.$wire.$set('message', prompt, false);
            this.$refs.message.focus({ preventScroll: true });
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
        x-transition:enter="fia-transition"
        x-transition:enter-start="fia-transition-offset"
        x-transition:leave="fia-transition"
        x-transition:leave-end="fia-transition-offset"
        x-cloak
    >
        <header class="fia-header">
            <div class="fia-brand">
                <span class="fia-brand-mark" aria-hidden="true">
                    <x-filament::icon icon="heroicon-o-sparkles" class="fia-icon" />
                </span>
                <div>
                    <h2 id="fia-title-{{ $this->getId() }}">{{ __('filament-ai-assistant::assistant.title') }}</h2>
                    <p>{{ __('filament-ai-assistant::assistant.subtitle') }}</p>
                </div>
            </div>

            <div class="fia-tools">
                @if (count($history) || $draft)
                    <button type="button" class="fia-tool" wire:click="clearChat" wire:loading.attr="disabled"
                        title="{{ __('filament-ai-assistant::assistant.clear') }}" aria-label="{{ __('filament-ai-assistant::assistant.clear') }}">
                        <x-filament::icon icon="heroicon-o-arrow-path" class="fia-icon" aria-hidden="true" />
                    </button>
                @endif

                <button type="button" class="fia-tool" x-on:click="closeChat()" aria-label="{{ __('filament-ai-assistant::assistant.close') }}">
                    <x-filament::icon icon="heroicon-m-x-mark" class="fia-icon" aria-hidden="true" />
                </button>
            </div>
        </header>

        <div class="fia-thread" x-ref="thread">
            @if (count($history))
                <div class="fia-messages" role="log" aria-live="polite">
                    @foreach ($history as $entry)
                        @php($isUser = $entry['role'] === 'user')
                        <article @class(['fia-message', 'fia-message-user' => $isUser])>
                            @unless ($isUser)
                                <span class="fia-message-mark" aria-hidden="true">
                                    <x-filament::icon icon="heroicon-o-sparkles" class="fia-icon" />
                                </span>
                            @endunless
                            <div class="fia-message-body">
                                <p class="fia-message-author">{{ __('filament-ai-assistant::assistant.'.($isUser ? 'you' : 'title')) }}</p>
                                <div class="fia-bubble" dir="auto">{{ $entry['text'] }}</div>
                            </div>
                        </article>
                    @endforeach
                </div>
            @else
                <div class="fia-empty" wire:loading.remove wire:target="send">
                    <div class="fia-welcome-mark" aria-hidden="true">
                        <x-filament::icon icon="heroicon-o-sparkles" class="fia-icon" />
                    </div>
                    <div class="fia-welcome-copy">
                        <h3>{{ __('filament-ai-assistant::assistant.welcome') }}</h3>
                        <p>{{ __('filament-ai-assistant::assistant.intro') }}</p>
                    </div>
                    <div class="fia-prompts">
                        @foreach (['manage' => 'heroicon-o-chat-bubble-left-right', 'find' => 'heroicon-o-magnifying-glass'] as $example => $icon)
                            <button type="button" class="fia-prompt"
                                x-on:click="usePrompt(@js(__('filament-ai-assistant::assistant.examples.'.$example)))">
                                <x-filament::icon :icon="$icon" class="fia-icon" aria-hidden="true" />
                                <span>{{ __('filament-ai-assistant::assistant.examples.'.$example) }}</span>
                                <x-filament::icon icon="heroicon-o-arrow-up-right" class="fia-icon fia-directional" aria-hidden="true" />
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($draft)
                <div class="fia-draft">
                    <div class="fia-draft-heading">
                        <span class="fia-draft-mark" aria-hidden="true">
                            <x-filament::icon icon="heroicon-o-document-text" class="fia-icon" />
                        </span>
                        <div>
                            <p>{{ __('filament-ai-assistant::assistant.draft_status') }}</p>
                            <h3>{{ __('filament-ai-assistant::assistant.draft_for', ['resource' => $draft['label']]) }}</h3>
                        </div>
                    </div>
                    <dl class="fia-changes">
                        @foreach ($draft['fields'] as $field)
                            <div class="fia-change">
                                <dt>{{ $field['label'] }}</dt>
                                <dd><small>{{ __('filament-ai-assistant::assistant.before') }}</small><span dir="auto">{{ $field['current'] }}</span></dd>
                                <dd class="fia-change-proposed"><small>{{ __('filament-ai-assistant::assistant.after') }}</small><span dir="auto">{{ $field['proposed'] }}</span></dd>
                            </div>
                        @endforeach
                    </dl>
                    <a class="fia-review" href="{{ $draft['url'] }}">
                        {{ __('filament-ai-assistant::assistant.review') }}
                        <x-filament::icon icon="heroicon-o-arrow-up-right" class="fia-icon fia-directional" aria-hidden="true" />
                    </a>
                </div>
            @endif

            <div class="fia-thinking" role="status" wire:loading.flex wire:target="send">
                <span class="fia-dots" aria-hidden="true"><i></i><i></i><i></i></span>
                <span>{{ __('filament-ai-assistant::assistant.working') }}</span>
            </div>
        </div>

        <form class="fia-compose" wire:submit="send" x-on:submit="$nextTick(() => scrollToLatest())">
            <div class="fia-input">
                <label class="fia-sr-only" for="fia-message-{{ $this->getId() }}">{{ __('filament-ai-assistant::assistant.message') }}</label>
                <textarea id="fia-message-{{ $this->getId() }}" x-ref="message" wire:model="message" rows="2" maxlength="2000" dir="auto"
                    placeholder="{{ __('filament-ai-assistant::assistant.placeholder') }}" @error('message') aria-invalid="true" aria-describedby="fia-error-{{ $this->getId() }}" @enderror></textarea>
                <button type="submit" class="fia-send" wire:loading.attr="disabled"
                    title="{{ __('filament-ai-assistant::assistant.send') }}" aria-label="{{ __('filament-ai-assistant::assistant.send') }}">
                    <x-filament::icon icon="heroicon-o-arrow-up" class="fia-icon" aria-hidden="true" />
                </button>
            </div>
            @error('message')
                <p id="fia-error-{{ $this->getId() }}" role="alert" class="fia-error">{{ $message }}</p>
            @enderror
            <p class="fia-review-note">
                <x-filament::icon icon="heroicon-o-shield-check" class="fia-icon" aria-hidden="true" />
                <span>{{ __('filament-ai-assistant::assistant.review_note') }}</span>
            </p>
        </form>
    </section>

    <button
        type="button"
        class="fia-launcher"
        x-ref="launcher"
        x-on:click="open ? closeChat() : showChat()"
        aria-label="{{ __('filament-ai-assistant::assistant.open') }}"
        title="{{ __('filament-ai-assistant::assistant.open') }}"
        x-bind:aria-label="open ? @js(__('filament-ai-assistant::assistant.close')) : @js(__('filament-ai-assistant::assistant.open'))"
        x-bind:title="open ? @js(__('filament-ai-assistant::assistant.close')) : @js(__('filament-ai-assistant::assistant.open'))"
        aria-expanded="false"
        x-bind:aria-expanded="open.toString()"
        aria-controls="fia-chat-{{ $this->getId() }}"
    >
        <x-filament::icon icon="heroicon-o-sparkles" class="fia-icon" x-show="!open" aria-hidden="true" />
        <x-filament::icon icon="heroicon-m-x-mark" class="fia-icon" x-show="open" x-cloak aria-hidden="true" />
    </button>
</div>
