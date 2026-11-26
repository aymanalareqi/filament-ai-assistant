<div
    class="fia-widget"
    data-filament-ai-assistant
    x-data="{
        open: false,
        sending: false,
        recordContext: null,
        listContext: null,
        includeRecord: true,
        syncPageContext() {
            const list = document.querySelector('[data-fia-list-context]')?.dataset;
            this.listContext = list?.token ? { token: list.token, label: list.resourceLabel } : null;
            const next = document.querySelector('[data-fia-record-context]')?.dataset;
            if ((this.recordContext?.token ?? null) === (next?.token ?? null)) return;
            this.recordContext = next?.token ? { token: next.token, label: next.recordLabel, resource: next.resourceLabel } : null;
            this.includeRecord = true;
        },
        showChat() {
            this.syncPageContext();
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
        async submitMessage() {
            if (this.sending) return;
            this.syncPageContext();
            this.sending = true;
            this.$nextTick(() => this.scrollToLatest());
            try {
                await this.$wire.send(this.includeRecord ? (this.recordContext?.token ?? null) : null, this.listContext?.token ?? null);
            } finally {
                this.sending = false;
            }
        },
    }"
    x-init="syncPageContext()"
    x-on:livewire:navigated.document="syncPageContext()"
    x-on:keydown.escape.stop="if (open) closeChat()"
    x-on:filament-ai-assistant-response.window="$nextTick(() => scrollToLatest())"
    x-on:filament-ai-assistant-selection.window="showChat(); $wire.refreshSelection()"
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

        <div class="fia-record-context" x-show="recordContext" x-cloak>
            <x-filament::icon icon="heroicon-o-document-text" class="fia-icon" aria-hidden="true" />
            <div class="fia-record-details" aria-live="polite">
                <small>{{ __('filament-ai-assistant::assistant.current_record') }}</small>
                <span x-show="includeRecord" x-text="recordContext ? recordContext.resource + ' · ' + recordContext.label : ''" dir="auto"></span>
                <span x-show="!includeRecord">{{ __('filament-ai-assistant::assistant.record_not_attached') }}</span>
            </div>
            <button type="button" class="fia-record-toggle" x-on:click="includeRecord = !includeRecord"
                x-text="includeRecord ? @js(__('filament-ai-assistant::assistant.detach_record')) : @js(__('filament-ai-assistant::assistant.attach_record'))"></button>
        </div>

        <div class="fia-record-context" x-show="listContext" x-cloak>
            <x-filament::icon icon="heroicon-o-list-bullet" class="fia-icon" aria-hidden="true" />
            <div class="fia-record-details" aria-live="polite">
                <small>{{ __('filament-ai-assistant::assistant.current_list') }}</small>
                <span x-text="listContext?.label ?? ''" dir="auto"></span>
            </div>
        </div>

        @if ($selection)
            <div class="fia-selection">
                <x-filament::icon icon="heroicon-o-check-circle" class="fia-icon" aria-hidden="true" />
                <span>{{ __('filament-ai-assistant::assistant.actions.selection', ['count' => count($selection['records']), 'resource' => $selection['label']]) }}</span>
                <button type="button" class="fia-tool" wire:click="clearSelection" wire:loading.attr="disabled"
                    title="{{ __('filament-ai-assistant::assistant.actions.clear_selection') }}" aria-label="{{ __('filament-ai-assistant::assistant.actions.clear_selection') }}">
                    <x-filament::icon icon="heroicon-m-x-mark" class="fia-icon" aria-hidden="true" />
                </button>
            </div>
        @endif

        <div class="fia-thread" x-ref="thread">
            @if (count($history))
                <div class="fia-messages" role="log" aria-live="polite">
                    @foreach ($messages as $entry)
                        @php($isUser = $entry['role'] === 'user')
                        <article @class(['fia-message', 'fia-message-user' => $isUser])>
                            @unless ($isUser)
                                <span class="fia-message-mark" aria-hidden="true">
                                    <x-filament::icon icon="heroicon-o-sparkles" class="fia-icon" />
                                </span>
                            @endunless
                            <div class="fia-message-body">
                                <p class="fia-message-author">{{ __('filament-ai-assistant::assistant.'.($isUser ? 'you' : 'title')) }}</p>
                                <div @class(['fia-bubble', 'fia-bubble-reply' => ! $isUser]) dir="auto">{{ $entry['text'] }}</div>
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
                    @if ($quickPrompts !== [])
                        <div class="fia-prompts">
                            @foreach ($quickPrompts as $quickPrompt)
                                <button type="button" class="fia-prompt"
                                    @if ($quickPrompt['requiresRecord'] ?? false) x-show="recordContext && includeRecord" x-cloak @endif
                                    x-on:click="usePrompt(@js($quickPrompt['prompt']))">
                                    <x-filament::icon :icon="$quickPrompt['icon'] ?? 'heroicon-o-chat-bubble-left-right'" class="fia-icon" aria-hidden="true" />
                                    <span>{{ $quickPrompt['label'] }}</span>
                                    <x-filament::icon icon="heroicon-o-arrow-up-right" class="fia-icon fia-directional" aria-hidden="true" />
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif

            @if ($recordChoice)
                <div class="fia-draft" wire:key="fia-choice-{{ $recordChoice['id'] }}" role="group"
                    aria-labelledby="fia-choice-title-{{ $this->getId() }}">
                    <div class="fia-draft-heading">
                        <span class="fia-draft-mark" aria-hidden="true">
                            <x-filament::icon icon="heroicon-o-magnifying-glass" class="fia-icon" />
                        </span>
                        <div>
                            <h3 id="fia-choice-title-{{ $this->getId() }}">{{ __('filament-ai-assistant::assistant.choice.title') }}</h3>
                        </div>
                    </div>
                    <div class="fia-choices">
                        @foreach ($recordChoice['candidates'] as $candidate)
                            <button type="button" class="fia-prompt" data-choice="{{ $recordChoice['id'] }}" data-record="{{ $candidate['id'] }}"
                                x-on:click="$wire.chooseRecord($el.dataset.choice, $el.dataset.record)" wire:loading.attr="disabled">
                                <span dir="auto">{{ $candidate['label'] }}</span>
                                <small dir="ltr">#{{ $candidate['id'] }}</small>
                            </button>
                        @endforeach
                    </div>
                    <button type="button" class="fia-record-toggle" data-choice="{{ $recordChoice['id'] }}"
                        x-on:click="$wire.cancelRecordChoice($el.dataset.choice)" wire:loading.attr="disabled">
                        {{ __('filament-ai-assistant::assistant.choice.cancel') }}
                    </button>
                </div>
            @endif

            @if ($draft)
                @php($isAction = ($draft['kind'] ?? null) === 'action')
                <div class="fia-draft">
                    <div class="fia-draft-heading">
                        <span class="fia-draft-mark" aria-hidden="true">
                            <x-filament::icon icon="heroicon-o-document-text" class="fia-icon" />
                        </span>
                        <div>
                            <p>{{ __('filament-ai-assistant::assistant.draft_status') }}</p>
                            <h3>
                                @if ($isAction)
                                    {{ __('filament-ai-assistant::assistant.actions.'.$draft['operation']) }}
                                    · {{ __('filament-ai-assistant::assistant.actions.selection', ['count' => count($draft['records']), 'resource' => $draft['label']]) }}
                                @else
                                    {{ __('filament-ai-assistant::assistant.draft_for', ['resource' => $draft['label']]) }}
                                @endif
                            </h3>
                        </div>
                    </div>
                    @if ($isAction)
                        <ul class="fia-action-records">
                            @foreach ($draft['records'] as $record)
                                <li><span dir="auto">{{ $record['label'] }}</span><small dir="ltr">#{{ $record['id'] }}</small></li>
                            @endforeach
                        </ul>
                        @if ($draft['warning'])
                            <p class="fia-action-warning">{{ $draft['warning'] }}</p>
                        @endif
                    @else
                        <dl class="fia-changes">
                            @foreach ($draft['fields'] as $field)
                                <div class="fia-change">
                                    <dt>{{ $field['label'] }}</dt>
                                    <dd><small>{{ __('filament-ai-assistant::assistant.before') }}</small><span dir="auto">{{ $field['current'] }}</span></dd>
                                    <dd class="fia-change-proposed"><small>{{ __('filament-ai-assistant::assistant.after') }}</small><span dir="auto">{{ $field['proposed'] }}</span></dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif
                    <a class="fia-review" href="{{ $draft['url'] }}">
                        {{ __('filament-ai-assistant::assistant.'.($isAction ? 'actions.review' : 'review')) }}
                        <x-filament::icon icon="heroicon-o-arrow-up-right" class="fia-icon fia-directional" aria-hidden="true" />
                    </a>
                </div>
            @endif

            <div class="fia-thinking" role="status" wire:loading.flex wire:target="send, chooseRecord">
                <span class="fia-dots" aria-hidden="true"><i></i><i></i><i></i></span>
                <span>{{ __('filament-ai-assistant::assistant.working') }}</span>
            </div>
        </div>

        <form class="fia-compose" x-on:submit.prevent="submitMessage()">
            <div class="fia-input">
                <label class="fia-sr-only" for="fia-message-{{ $this->getId() }}">{{ __('filament-ai-assistant::assistant.message') }}</label>
                <textarea id="fia-message-{{ $this->getId() }}" x-ref="message" wire:model="message"
                    wire:loading.attr="readonly" wire:target="send" rows="2" maxlength="2000" dir="auto"
                    @disabled($recordChoice !== null)
                    placeholder="{{ __('filament-ai-assistant::assistant.placeholder') }}" @error('message') aria-invalid="true" aria-describedby="fia-error-{{ $this->getId() }}" @enderror></textarea>
                <button type="submit" class="fia-send" wire:loading.attr="disabled"
                    x-bind:disabled="sending || Boolean($wire.recordChoice)" @disabled($recordChoice !== null)
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
