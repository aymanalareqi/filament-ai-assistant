<?php

namespace Alareqi\FilamentAiAssistant;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\Page;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ActionProposalStore
{
    public const MAX_RECORDS = 100;

    public function __construct(
        private ResourceRegistry $resources,
        private NativeResourceActions $native,
        private AssistantActivityRecorder $activity,
    ) {}

    /**
     * @param  array{resource: string, label: string, records: list<array{id: string, label: string}>}|null  $selection
     * @return list<array{id: string, label: string, surface: string, input: bool}>
     */
    public function describeActions(string $slug, ?string $recordId, ?array $selection = null): array
    {
        $resource = $this->resources->resource($slug);
        if (! FilamentAiAssistantPlugin::current()->hasActionDiscovery($resource)) {
            return [];
        }
        $record = $recordId === null ? null : $this->records($resource, [$recordId])->first();
        if ($record !== null && ! $this->canSee($resource, $record)) {
            $this->invalid('action_unavailable');
        }

        $ids = ($selection['resource'] ?? null) === $slug ? array_column($selection['records'], 'id') : [];
        $selectedRecords = $ids === [] ? collect() : $this->records($resource, $ids);
        if (! $selectedRecords->every(fn (Model $selected): bool => $this->canSee($resource, $selected))) {
            $this->invalid('selection_required');
        }

        return $this->native->describe($resource, $record, $ids, fn (Action $action, string $surface): bool => $this->canAct(
            $resource, $action, $surface === 'bulk' ? $selectedRecords : collect($record === null ? [] : [$record]),
        ));
    }

    /**
     * @param  list<string>  $ids
     * @return array{resource: string, label: string, records: list<array{id: string, label: string}>}
     */
    public function selectionFor(string $slug, array $ids): array
    {
        if ($ids === [] || count($ids) > self::MAX_RECORDS) {
            $this->invalid('selection_size', ['max' => self::MAX_RECORDS]);
        }

        $resource = $this->resources->resource($slug);
        $records = $this->records($resource, $ids);
        if (! $records->every(fn (Model $record): bool => $this->canSee($resource, $record))) {
            $this->invalid('selection_required');
        }

        return [
            'resource' => $slug,
            'label' => $resource::getPluralModelLabel(),
            'records' => $records->map(fn (Model $record): array => $this->recordPreview($resource, $record))->all(),
        ];
    }

    /**
     * @param  array{resource: string, label: string, records: list<array{id: string, label: string}>}|null  $selection
     * @return array<string, mixed>
     */
    public function prepare(string $slug, string $operation, ?string $recordId, ?array $selection = null): array
    {
        $resource = $this->resources->resource($slug);
        $key = $this->native->canonical($operation);
        $surface = $this->native->surface($key);
        if (! str_contains($key, ':') || ! FilamentAiAssistantPlugin::current()->hasActionDiscovery($resource)) {
            $this->invalid('action_unavailable');
        }

        $bulk = $surface === 'bulk';
        if ($bulk && ($selection === null || $selection['resource'] !== $slug || $selection['records'] === [])) {
            $this->invalid('selection_required');
        }

        if ($this->native->requiresRecord($surface) && blank($recordId)) {
            $this->invalid('select_record');
        }
        if (! $this->native->requiresRecord($surface) && $recordId !== null) {
            $this->invalid('action_unavailable');
        }

        $ids = $bulk ? array_column($selection['records'], 'id') : ($recordId === null ? [] : [$recordId]);
        $records = $this->records($resource, $ids);
        if (! $records->every(fn (Model $record): bool => $this->canSee($resource, $record))) {
            $this->invalid('action_unavailable');
        }

        $page = $this->native->page($resource, $surface, $records->first(), $bulk ? $ids : []);
        $action = $page === null ? null : $this->native->resolve($page, $key, $recordId);
        if ($action === null || ! $this->native->canReview($page, $key, $action)
            || ! $this->canAct($resource, $action, $records)) {
            $this->invalid('action_unavailable');
        }

        $id = (string) Str::uuid();
        $proposals = array_filter(session()->get('filament-ai-assistant.actions', []), fn (array $proposal): bool => $proposal['expires'] > now()->timestamp);
        $proposals[$id] = [
            'scope' => FilamentAiAssistantPlugin::current()->chatScope(),
            'resource' => $resource,
            'action' => $key,
            'ids' => $ids,
            'versions' => $this->versions($records),
            'expires' => now()->addMinutes(30)->timestamp,
        ];
        session()->put('filament-ai-assistant.actions', array_slice($proposals, -8, preserve_keys: true));

        $pageKey = $this->native->pageKey($surface);

        return [
            'id' => $id,
            'kind' => 'action',
            'operation' => $operation,
            'action_label' => $this->native->label($action),
            'url' => $resource::getUrl($pageKey, [
                ...(in_array($pageKey, ['view', 'edit'], true) ? ['record' => $records->first()] : []),
                'assistantAction' => $id,
            ]),
            'label' => $recordId === null ? $resource::getPluralModelLabel() : $resource::getModelLabel(),
            'records' => $records->map(fn (Model $record): array => $this->recordPreview($resource, $record))->all(),
            'warning' => $this->nativeDeleteDescription($action),
        ];
    }

    public function isPending(string $id): bool
    {
        return session()->get('filament-ai-assistant.actions.'.$id.'.expires', 0) > now()->timestamp
            && ! Cache::has($this->confirmationKey($id));
    }

    public function open(Page $page, string $id): void
    {
        if (session()->has($this->reviewKey($page))) {
            return;
        }

        $proposal = $this->validated($page, $id);
        $surface = $this->native->surface($proposal['action']);
        abort_if(filled($page->mountedActions) || filled($page->defaultAction ?? null) || filled($page->defaultTableAction ?? null), 409);

        if ($surface === 'bulk' && $page instanceof ListRecords) {
            $page->selectedTableRecords = $proposal['ids'];
            $page->deselectedTableRecords = [];
            $page->isTrackingDeselectedTableRecords = false;
            $this->verifySelection($page, $proposal['ids']);
        }

        $action = $this->native->resolve($page, $proposal['action'], $proposal['ids'][0] ?? null);
        $this->authorizeAction($page, $proposal, $action);

        $page->forceRender();
        $page->mountAction($action->getName(), context: $this->actionContext($id, $proposal));
        abort_unless($page->getMountedAction()?->getName() === $action->getName()
            && ($page->mountedActions[0]['context'] ?? null) === $this->actionContext($id, $proposal), 403);
        session()->put($this->reviewKey($page), $id);
        request()->attributes->set('filament-ai-assistant.action-component', $page->getId());
    }

    /** @return array<string,mixed>|null */
    public function beforeConfirm(Page $page, array $parameters): ?array
    {
        $id = session()->get($this->reviewKey($page));
        $mounted = $page->mountedActions[0] ?? [];

        if ($id === null) {
            abort_if(isset($mounted['context']['assistantAction']), 403);

            return null;
        }

        $proposal = $this->validated($page, $id);
        $surface = $this->native->surface($proposal['action']);
        abort_unless(count($page->mountedActions ?? []) === 1 && ($mounted['name'] ?? null) === Str::after($proposal['action'], ':')
            && ($mounted['context'] ?? null) === $this->actionContext($id, $proposal)
            && empty($mounted['arguments'] ?? []) && empty($parameters[0] ?? []), 409);

        if ($surface === 'bulk' && $page instanceof ListRecords) {
            $this->verifySelection($page, $proposal['ids']);
        }

        $this->authorizeAction($page, $proposal, $page->getMountedAction());

        return ['id' => $id, ...$proposal];
    }

    public function claimConfirmation(Action $action): void
    {
        $page = $action->getLivewire();
        $id = $page instanceof Page ? ($page->mountedActions[0]['context']['assistantAction'] ?? null) : null;
        if ($id === null) {
            return;
        }

        abort_unless(is_string($id) && Str::isUuid($id) && session()->get($this->reviewKey($page)) === $id, 403);
        $proposal = $this->validated($page, $id);
        abort_unless(count($page->mountedActions) === 1 && $action->getName() === Str::after($proposal['action'], ':')
            && ($page->mountedActions[0]['context'] ?? null) === $this->actionContext($id, $proposal), 409);
        abort_unless(Cache::add($this->confirmationKey($id), true, max(1, $proposal['expires'] - now()->timestamp)), 409);
        $this->activity->watch($page, $id, $proposal['action'], $this->native->label($action), $proposal['ids']);
    }

    /** @param array<string,mixed> $proposal */
    public function afterConfirm(Page $page, array $proposal): void
    {
        if (! Cache::has($this->confirmationKey($proposal['id']))) {
            return;
        }

        session()->forget('filament-ai-assistant.actions.'.$proposal['id']);
        $this->cancel($page);
        $this->activity->actionFinished($page);

        Log::info('Filament AI assistant action confirmed.', [
            'actor_id' => Filament::auth()->id(),
            'operation' => $proposal['action'],
            'resource' => $proposal['resource'],
            'record_ids' => $proposal['ids'],
        ]);
    }

    public function cancel(Page $page): void
    {
        session()->forget($this->reviewKey($page));
    }

    /** @return array<string,mixed> */
    private function validated(Page $page, string $id): array
    {
        $plugin = FilamentAiAssistantPlugin::current();
        $proposal = session()->get('filament-ai-assistant.actions.'.$id);
        $resource = $page::getResource();
        abort_unless($plugin?->canUse() && $plugin->allowsResource($resource) && $resource::canViewAny()
            && $proposal !== null && $proposal['scope'] === $plugin->chatScope()
            && $proposal['resource'] === $resource, 403);
        $proposal['action'] ??= $this->native->canonical($proposal['operation'] ?? '');
        abort_unless($proposal['expires'] > now()->timestamp, 410, __('filament-ai-assistant::assistant.errors.expired'));
        abort_if(Cache::has($this->confirmationKey($id)), 409);
        $surface = $this->native->surface($proposal['action']);
        abort_unless($this->native->pageClass($resource, $surface) === $page::class, 403);
        if ($page instanceof EditRecord || $page instanceof ViewRecord) {
            abort_unless($proposal['ids'] === [(string) $page->getRecord()->getKey()], 403);
        }

        try {
            $records = $this->records($resource, $proposal['ids']);
        } catch (ValidationException) {
            abort(409, __('filament-ai-assistant::assistant.errors.changed'));
        }

        abort_unless($records->every(fn (Model $record): bool => $this->canSee($resource, $record)), 403);
        abort_unless($this->versions($records) === $proposal['versions'], 409, __('filament-ai-assistant::assistant.errors.changed'));

        return $proposal;
    }

    /** @param array<string,mixed> $proposal */
    private function authorizeAction(Page $page, array $proposal, ?Action $action): void
    {
        $resource = $page::getResource();
        abort_unless($action !== null && $this->native->canReview($page, $proposal['action'], $action)
            && $this->canAct($resource, $action, $this->records($resource, $proposal['ids'])), 403);
    }

    /** @param list<string> $ids */
    private function verifySelection(ListRecords $page, array $ids): void
    {
        $selected = array_map('strval', $page->selectedTableRecords);
        abort_unless(! $page->isTrackingDeselectedTableRecords && array_values($selected) === $ids, 409);

        $actual = $page->getSelectedTableRecords()->collect()->map(fn (Model $record): string => (string) $record->getKey())->all();
        abort_unless(count($actual) === count($ids) && array_diff($actual, $ids) === [], 409);
    }

    /** @param list<string> $ids */
    private function records(string $resource, array $ids): Collection
    {
        $byId = $resource::getEloquentQuery()->whereKey($ids)->get()->keyBy(fn (Model $record): string => (string) $record->getKey());
        if ($byId->count() !== count($ids) || collect($ids)->contains(fn (string $id): bool => ! $byId->has($id))) {
            $this->invalid('record_missing');
        }

        return collect($ids)->map(fn (string $id): Model => $byId->get($id));
    }

    /** @return array<string, string> */
    private function versions(Collection $records): array
    {
        return $records->mapWithKeys(function (Model $record): array {
            $attributes = $record->getRawOriginal();
            ksort($attributes);

            return [(string) $record->getKey() => hash('sha256', serialize($attributes))];
        })->all();
    }

    /** @return array{id: string, label: string} */
    private function recordPreview(string $resource, Model $record): array
    {
        return [
            'id' => (string) $record->getKey(),
            'label' => Str::limit(strip_tags((string) $resource::getRecordTitle($record)), 120),
        ];
    }

    private function canSee(string $resource, Model $record): bool
    {
        return $resource::canView($record) || $resource::canEdit($record);
    }

    private function canAct(string $resource, Action $action, Collection $records): bool
    {
        return match (true) {
            $action instanceof DeleteAction => $records->every(fn (Model $record): bool => $resource::canDelete($record)),
            $action instanceof DeleteBulkAction => $resource::canDeleteAny() && $records->every(fn (Model $record): bool => $resource::canDelete($record)),
            default => true,
        };
    }

    private function nativeDeleteDescription(Action $action): ?string
    {
        if (! ($action instanceof DeleteAction || $action instanceof DeleteBulkAction)) {
            return null;
        }

        return trim(html_entity_decode(strip_tags((string) $action->getModalDescription()), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: null;
    }

    private function reviewKey(Page $page): string
    {
        return 'filament-ai-assistant.action-reviews.'.$page->getId();
    }

    private function confirmationKey(string $id): string
    {
        return 'filament-ai-assistant:action-confirmation:'.$id;
    }

    /** @return array<string, bool|string> */
    private function actionContext(string $id, array $proposal): array
    {
        return ['mountedFromUrl' => true, 'assistantAction' => $id, ...$this->native->context($proposal['action'], $proposal['ids'][0] ?? null)];
    }

    private function invalid(string $key, array $replace = []): never
    {
        throw ValidationException::withMessages(['assistant' => __('filament-ai-assistant::assistant.errors.'.$key, $replace)]);
    }
}
