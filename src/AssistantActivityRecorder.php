<?php

namespace Alareqi\FilamentAiAssistant;

use Filament\Actions\Action;
use Filament\Actions\Enums\ActionStatus;
use Filament\Facades\Filament;
use Filament\Models\Contracts\HasName;
use Filament\Resources\Pages\Page;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class AssistantActivityRecorder
{
    public static function scope(): string
    {
        $panel = Filament::getCurrentPanel();
        $tenant = Filament::getTenant();

        return hash('sha256', serialize([
            $panel->getId(), $panel->getAuthGuard(),
            $tenant === null ? null : [$tenant::class, (string) $tenant->getKey()],
        ]));
    }

    /** @param list<string> $recordIds */
    public function watch(Page $page, string $reviewId, string $operation, string $label, array $recordIds): void
    {
        if (! FilamentAiAssistantPlugin::current()?->recordsActivity()) {
            return;
        }

        $panel = Filament::getCurrentPanel();
        $actor = $panel->auth()->user();
        if ($actor === null) {
            return;
        }

        $resource = $page::getResource();
        $actorName = $actor instanceof HasName ? Filament::getUserName($actor)
            : ($actor instanceof Model ? $actor->getAttribute('name') : null);

        request()->attributes->set($this->key($page), [
            'review_id' => $reviewId,
            'scope' => self::scope(),
            'actor_id' => (string) $actor->getAuthIdentifier(),
            'actor_name' => is_string($actorName) && filled($actorName)
                ? Str::limit(strip_tags($actorName), 120, '') : '#'.$actor->getAuthIdentifier(),
            'resource' => $resource::getSlug($panel),
            'resource_label' => Str::limit(strip_tags($resource::getPluralModelLabel()), 120),
            'operation' => $operation,
            'action_label' => $label,
            'record_ids' => $recordIds,
            'created_at' => now(),
        ]);
    }

    public function actionReturned(Action $action): void
    {
        $page = $action->getLivewire();
        if (! $page instanceof Page || ! request()->attributes->has($this->key($page))) {
            return;
        }

        $entry = request()->attributes->get($this->key($page));
        $entry['outcome'] = $action->getStatus() === ActionStatus::Success
            ? AssistantActivity::SUCCEEDED : AssistantActivity::FAILED;
        request()->attributes->set($this->key($page), $entry);
    }

    public function rememberSavedRecord(Page $page, Model $record): void
    {
        if (request()->attributes->has($this->key($page))) {
            request()->attributes->set($this->key($page).'.record', $record);
        }
    }

    public function savedRecord(Page $page): ?Model
    {
        return request()->attributes->get($this->key($page).'.record');
    }

    public function draftSaved(Page $page, Model $record): void
    {
        $this->write($page, AssistantActivity::SUCCEEDED, [(string) $record->getKey()]);
    }

    public function actionFinished(Page $page): void
    {
        $this->write($page);
    }

    public function failed(Page $page, Throwable $exception): void
    {
        $entry = request()->attributes->get($this->key($page));
        if ($entry === null || (str_starts_with($entry['operation'], 'draft:') && $exception instanceof ValidationException)) {
            return;
        }

        $this->write($page, AssistantActivity::FAILED);
    }

    /** @param list<string>|null $recordIds */
    private function write(Page $page, ?string $outcome = null, ?array $recordIds = null): void
    {
        $entry = request()->attributes->get($this->key($page));
        if ($entry === null) {
            return;
        }

        request()->attributes->remove($this->key($page));
        request()->attributes->remove($this->key($page).'.record');

        try {
            (new AssistantActivity)->forceFill([
                ...$entry,
                'record_ids' => $recordIds ?? $entry['record_ids'],
                'outcome' => $outcome ?? $entry['outcome'] ?? AssistantActivity::INTERRUPTED,
            ])->save();
        } catch (Throwable $exception) {
            Log::warning('Filament AI assistant activity could not be saved.', ['exception_type' => $exception::class]);
        }
    }

    private function key(Page $page): string
    {
        return 'filament-ai-assistant.activity.'.$page->getId();
    }
}
