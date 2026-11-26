<?php

namespace Alareqi\FilamentAiAssistant;

use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

final class AssistantChat extends Component
{
    public string $message = '';

    #[Locked]
    public string $scope;

    /** @var list<array{role: string, text: string}> */
    #[Locked]
    public array $history = [];

    /** @var array<string, mixed>|null */
    #[Locked]
    public ?array $draft = null;

    /** @var array<string, mixed>|null */
    #[Locked]
    public ?array $selection = null;

    /** @var array{id: string, resource: string, candidates: list<array{id: string, label: string}>}|null */
    #[Locked]
    public ?array $recordChoice = null;

    public static function canAccess(): bool
    {
        return FilamentAiAssistantPlugin::current()?->canUse() ?? false;
    }

    public function render(): View
    {
        $panel = Filament::getCurrentPanel();
        $resourceSlugs = array_map(fn (string $resource): string => $resource::getSlug($panel), $panel?->getResources() ?? []);
        $messages = array_map(fn (array $entry): array => [
            'role' => $entry['role'],
            'text' => $entry['role'] === 'assistant' ? $this->formatReply($entry['text'], $resourceSlugs) : $entry['text'],
        ], $this->history);

        return view('filament-ai-assistant::assistant', [
            'messages' => $messages,
            'quickPrompts' => FilamentAiAssistantPlugin::current()?->getQuickPrompts() ?? [],
        ]);
    }

    public function mount(DraftStore $drafts): void
    {
        abort_unless(self::canAccess(), 403);

        $this->scope = FilamentAiAssistantPlugin::current()->chatScope();
        $this->restoreConversation($drafts);
    }

    public function hydrate(DraftStore $drafts): void
    {
        $this->authorizeConversation();
        $this->restoreConversation($drafts);
    }

    public function refreshSelection(ActionProposalStore $actions): void
    {
        $this->authorizeConversation();
        $this->selection = $actions->selection();
    }

    public function clearSelection(ActionProposalStore $actions): void
    {
        $this->authorizeConversation();
        $actions->clearSelection();
        $this->selection = null;
    }

    public function send(ResourceRegistry $resources, DraftStore $drafts, CurrentRecordContext $context, ?string $recordContextToken = null): void
    {
        $this->authorizeConversation();
        $this->resetErrorBag('message');

        if ($this->recordChoice !== null) {
            $this->addError('message', __('filament-ai-assistant::assistant.errors.choice_pending'));

            return;
        }

        $this->message = trim($this->message);
        $this->validate(['message' => 'required|string|max:2000'], [
            'message.required' => __('filament-ai-assistant::assistant.errors.message_required'),
            'message.string' => __('filament-ai-assistant::assistant.errors.message_invalid'),
            'message.max' => __('filament-ai-assistant::assistant.errors.message_too_long'),
        ]);

        $currentRecord = $recordContextToken === null ? null : $context->resolve($recordContextToken);
        if ($recordContextToken !== null && $currentRecord === null) {
            $this->addError('message', __('filament-ai-assistant::assistant.errors.current_record_unavailable'));

            return;
        }

        $this->answer($resources, $drafts, $this->message, $currentRecord);
    }

    public function chooseRecord(ResourceRegistry $resources, DraftStore $drafts, CurrentRecordContext $context, string $choiceId, string $recordId): void
    {
        $this->authorizeConversation();
        $this->resetErrorBag('message');

        if ($this->recordChoice === null || $this->recordChoice['id'] !== $choiceId
            || ! in_array($recordId, array_column($this->recordChoice['candidates'], 'id'), true)) {
            $this->addError('message', __('filament-ai-assistant::assistant.errors.choose_listed_record'));

            return;
        }

        $record = $context->forRecord($this->recordChoice['resource'], $recordId);
        $originalMessage = session()->get($this->conversationKey().'.choice_message');

        if ($record === null || ! is_string($originalMessage)) {
            $this->addError('message', __('filament-ai-assistant::assistant.errors.choice_unavailable'));

            return;
        }

        $this->answer($resources, $drafts, $originalMessage, $record, recordExplicitlyChosen: true);
    }

    public function cancelRecordChoice(string $choiceId): void
    {
        $this->authorizeConversation();

        if ($this->recordChoice === null || $this->recordChoice['id'] !== $choiceId) {
            return;
        }

        $this->recordChoice = null;
        $this->resetErrorBag('message');
        $this->appendTurn(__('filament-ai-assistant::assistant.choice.cancelled_user'), __('filament-ai-assistant::assistant.choice.cancelled'));
        $this->saveConversation();
        $this->dispatch('filament-ai-assistant-response');
    }

    /** @param array{resource: string, record_id: string, resource_label: string, record_label: string, can_edit: bool}|null $currentRecord */
    private function answer(ResourceRegistry $resources, DraftStore $drafts, string $originalMessage, ?array $currentRecord, bool $recordExplicitlyChosen = false): void
    {
        $key = 'filament-ai-assistant:'.Filament::getCurrentPanel()->getId().':'.Filament::auth()->id();

        if (RateLimiter::tooManyAttempts($key, 8)) {
            $this->addError('message', __('filament-ai-assistant::assistant.errors.rate_limit'));

            return;
        }

        RateLimiter::hit($key);
        $agentHistory = $recordExplicitlyChosen ? array_slice($this->history, 0, -2) : $this->history;
        $agent = new AssistantAgent($resources, $drafts, array_slice($agentHistory, -10), $currentRecord, $recordExplicitlyChosen);

        try {
            $response = $agent->prompt(
                $originalMessage,
                provider: config('filament-ai-assistant.provider'),
                model: config('filament-ai-assistant.model'),
                timeout: 20,
            );
        } catch (Throwable $exception) {
            Log::warning('Filament AI assistant could not complete a request.', ['exception_type' => $exception::class]);
            $this->addError('message', __('filament-ai-assistant::assistant.errors.provider'));

            return;
        }

        $this->draft = $agent->draft();
        $choice = $agent->recordChoice();
        $this->recordChoice = $choice === null ? null : ['id' => (string) Str::uuid(), ...$choice];

        $text = match (true) {
            $choice !== null => __('filament-ai-assistant::assistant.choice.prompt'),
            $this->draft !== null => __('filament-ai-assistant::assistant.'.(($this->draft['kind'] ?? null) === 'action' ? 'actions.prepared' : 'prepared')),
            default => Str::limit($response->text, 4000),
        };
        $userText = $recordExplicitlyChosen
            ? __('filament-ai-assistant::assistant.choice.selected', ['record' => $currentRecord['record_label'], 'id' => $currentRecord['record_id']])
            : $originalMessage;

        $this->appendTurn($userText, $text);
        $this->saveConversation($choice === null ? null : $originalMessage);
        $this->reset('message');
        $this->dispatch('filament-ai-assistant-response');
    }

    private function appendTurn(string $userText, string $assistantText): void
    {
        $this->history = array_slice([
            ...$this->history,
            ['role' => 'user', 'text' => $userText],
            ['role' => 'assistant', 'text' => $assistantText],
        ], -20);
    }

    private function saveConversation(?string $choiceMessage = null): void
    {
        session()->put($this->conversationKey(), [
            'history' => $this->history,
            'draft' => $this->draft,
            'record_choice' => $this->recordChoice,
            'choice_message' => $choiceMessage,
        ]);
    }

    public function clearChat(): void
    {
        $this->authorizeConversation();

        session()->forget($this->conversationKey());
        $this->reset('message', 'history', 'draft', 'recordChoice');
        $this->resetErrorBag();
    }

    private function authorizeConversation(): void
    {
        abort_unless(self::canAccess() && $this->scope === FilamentAiAssistantPlugin::current()->chatScope(), 403);
    }

    /** @param array<string> $resourceSlugs */
    private function formatReply(string $text, array $resourceSlugs): HtmlString
    {
        $text = preg_replace_callback(
            '~\h+\(\h*([\x60"\']?)([\p{L}\p{N}._/-]+)\1\h*\)~u',
            fn (array $match): string => in_array($match[2], $resourceSlugs, true) ? '' : $match[0],
            $text,
        ) ?? $text;

        $html = Str::markdown($text, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'renderer' => ['soft_break' => "<br>\n"],
        ]);

        return new HtmlString(trim(strip_tags($html, [
            'p', 'br', 'strong', 'em', 'del', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
        ])));
    }

    private function restoreConversation(DraftStore $drafts): void
    {
        $conversation = session()->get($this->conversationKey(), []);
        $this->history = $conversation['history'] ?? [];
        $this->recordChoice = $conversation['record_choice'] ?? null;
        $draft = $conversation['draft'] ?? null;
        $actions = app(ActionProposalStore::class);
        $store = ($draft['kind'] ?? null) === 'action' ? $actions : $drafts;
        $this->draft = $draft !== null && $store->isPending($draft['id']) ? $draft : null;
        $this->selection = $actions->selection();
    }

    private function conversationKey(): string
    {
        return 'filament-ai-assistant.conversations.'.$this->scope;
    }
}
