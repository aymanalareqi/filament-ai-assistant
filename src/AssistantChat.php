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

        return view('filament-ai-assistant::assistant', ['messages' => $messages]);
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

    public function send(ResourceRegistry $resources, DraftStore $drafts): void
    {
        $this->authorizeConversation();
        $this->message = trim($this->message);
        $this->validate(['message' => 'required|string|max:2000'], [
            'message.required' => __('filament-ai-assistant::assistant.errors.message_required'),
            'message.string' => __('filament-ai-assistant::assistant.errors.message_invalid'),
            'message.max' => __('filament-ai-assistant::assistant.errors.message_too_long'),
        ]);

        $key = 'filament-ai-assistant:'.Filament::getCurrentPanel()->getId().':'.Filament::auth()->id();

        if (RateLimiter::tooManyAttempts($key, 8)) {
            $this->addError('message', __('filament-ai-assistant::assistant.errors.rate_limit'));

            return;
        }

        RateLimiter::hit($key);
        $agent = new AssistantAgent($resources, $drafts, array_slice($this->history, -10));

        try {
            $response = $agent->prompt(
                $this->message,
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
        $text = $this->draft === null
            ? Str::limit($response->text, 4000)
            : __('filament-ai-assistant::assistant.'.(($this->draft['kind'] ?? null) === 'action' ? 'actions.prepared' : 'prepared'));
        $this->history = array_slice([
            ...$this->history,
            ['role' => 'user', 'text' => $this->message],
            ['role' => 'assistant', 'text' => $text],
        ], -20);
        session()->put($this->conversationKey(), ['history' => $this->history, 'draft' => $this->draft]);

        $this->reset('message');
        $this->dispatch('filament-ai-assistant-response');
    }

    public function clearChat(): void
    {
        $this->authorizeConversation();

        session()->forget($this->conversationKey());
        $this->reset('message', 'history', 'draft');
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
