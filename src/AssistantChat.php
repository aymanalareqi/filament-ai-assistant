<?php

namespace Alareqi\FilamentAiAssistant;

use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
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

    /** @var array{id: string, url: string, label: string, fields: array}|null */
    #[Locked]
    public ?array $draft = null;

    public static function canAccess(): bool
    {
        return FilamentAiAssistantPlugin::current()?->canUse() ?? false;
    }

    public function render(): View
    {
        return view('filament-ai-assistant::assistant');
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
            : __('filament-ai-assistant::assistant.prepared');
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

    private function restoreConversation(DraftStore $drafts): void
    {
        $conversation = session()->get($this->conversationKey(), []);
        $this->history = $conversation['history'] ?? [];
        $draft = $conversation['draft'] ?? null;
        $this->draft = $draft !== null && $drafts->isPending($draft['id']) ? $draft : null;
    }

    private function conversationKey(): string
    {
        return 'filament-ai-assistant.conversations.'.$this->scope;
    }
}
