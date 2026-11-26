<?php

namespace Alareqi\FilamentAiAssistant;

use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ActionProposalStore
{
    public const OPERATIONS = ['publish', 'unpublish', 'delete', 'bulk_delete'];

    public const MAX_RECORDS = 100;

    public function __construct(private ResourceRegistry $resources) {}

    /** @return list<string> */
    public function available(string $resource): array
    {
        $plugin = FilamentAiAssistantPlugin::current();

        if (! $plugin?->canUse() || ! $plugin->allowsResource($resource) || ! $resource::canViewAny()) {
            return [];
        }

        return array_values(array_filter($plugin->getResourceActions($resource), fn (string $operation): bool => match ($operation) {
            'delete' => $this->supportsPage($resource, 'edit', EditRecord::class),
            'bulk_delete' => $this->supportsPage($resource, 'index', ListRecords::class) && $resource::canDeleteAny(),
            default => $this->supportsPage($resource, 'index', ListRecords::class),
        }));
    }

    /**
     * Capture an explicit selection from the native table, not from model arguments.
     *
     * @return array{resource: string, label: string, records: list<array{id: string, label: string}>}
     */
    public function captureSelection(ListRecords $page): array
    {
        $resource = $page::getResource();
        $operations = array_diff($this->available($resource), ['delete']);
        abort_if($operations === [], 403);

        if ($page->isTrackingDeselectedTableRecords || count($page->selectedTableRecords) > self::MAX_RECORDS) {
            $this->invalid('selection_size', ['max' => self::MAX_RECORDS]);
        }

        $records = $page->getSelectedTableRecords()->collect()->values();
        if ($records->isEmpty() || $records->count() !== count($page->selectedTableRecords)
            || ! $records->every(fn (mixed $record): bool => $record instanceof Model && $this->canSee($resource, $record))) {
            $this->invalid('selection_required');
        }

        $selection = [
            'resource' => $resource::getSlug(Filament::getCurrentPanel()),
            'label' => $resource::getPluralModelLabel(),
            'records' => $records->map(fn (Model $record): array => $this->recordPreview($resource, $record))->all(),
        ];
        session()->put($this->selectionKey(), ['selection' => $selection, 'expires' => now()->addMinutes(30)->timestamp]);

        return $selection;
    }

    /** @return array{resource: string, label: string, records: list<array{id: string, label: string}>}|null */
    public function selection(): ?array
    {
        $plugin = FilamentAiAssistantPlugin::current();
        if (! $plugin?->canUse()) {
            return null;
        }

        $entry = session()->get($this->selectionKey());

        if (! $entry || $entry['expires'] <= now()->timestamp) {
            return null;
        }

        $selection = $entry['selection'];

        try {
            $resource = $this->resources->resource($selection['resource']);
            $records = $this->records($resource, array_column($selection['records'], 'id'));
        } catch (ValidationException) {
            return null;
        }

        if (array_diff($this->available($resource), ['delete']) === []
            || ! $records->every(fn (Model $record): bool => $this->canSee($resource, $record))) {
            return null;
        }

        return [
            ...$selection,
            'records' => $records->map(fn (Model $record): array => $this->recordPreview($resource, $record))->all(),
        ];
    }

    public function clearSelection(): void
    {
        session()->forget($this->selectionKey());
    }

    /** @return array<string, mixed> */
    public function prepare(string $slug, string $operation, ?string $recordId): array
    {
        $resource = $this->resources->resource($slug);
        if (! in_array($operation, $this->available($resource), true)) {
            $this->invalid('action_unavailable');
        }

        $bulk = $operation !== 'delete';
        $selection = $bulk ? $this->selection() : null;
        if ($bulk && ($selection === null || $selection['resource'] !== $slug)) {
            $this->invalid('selection_required');
        }

        if (! $bulk && blank($recordId)) {
            $this->invalid('select_record');
        }

        $ids = $bulk ? array_column($selection['records'], 'id') : [$recordId];
        $records = $this->records($resource, $ids);
        if (! $records->every(fn (Model $record): bool => $this->canAct($resource, $operation, $record))) {
            $this->invalid('action_unavailable');
        }

        $id = (string) Str::uuid();
        $proposals = array_filter(session()->get('filament-ai-assistant.actions', []), fn (array $proposal): bool => $proposal['expires'] > now()->timestamp);
        $proposals[$id] = [
            'scope' => FilamentAiAssistantPlugin::current()->chatScope(),
            'resource' => $resource,
            'operation' => $operation,
            'ids' => $ids,
            'versions' => $this->versions($records),
            'expires' => now()->addMinutes(30)->timestamp,
        ];
        session()->put('filament-ai-assistant.actions', array_slice($proposals, -8, preserve_keys: true));

        return [
            'id' => $id,
            'kind' => 'action',
            'operation' => $operation,
            'url' => $resource::getUrl($bulk ? 'index' : 'edit', [
                ...($bulk ? [] : ['record' => $records->first()]),
                'assistantAction' => $id,
            ]),
            'label' => $bulk ? $resource::getPluralModelLabel() : $resource::getModelLabel(),
            'records' => $records->map(fn (Model $record): array => $this->recordPreview($resource, $record))->all(),
            'warning' => $this->deleteWarning($resource, $operation, $records->first()),
        ];
    }

    public function isPending(string $id): bool
    {
        return session()->get('filament-ai-assistant.actions.'.$id.'.expires', 0) > now()->timestamp
            && ! Cache::has($this->confirmationKey($id));
    }

    public function open(ListRecords|EditRecord $page, string $id): void
    {
        if (session()->has($this->reviewKey($page))) {
            return;
        }

        $proposal = $this->validated($page, $id);
        $operation = $proposal['operation'];
        $bulk = $operation !== 'delete';
        $actionName = $operation === 'bulk_delete' ? 'delete' : $operation;
        abort_if(filled($page->mountedActions) || filled($page->defaultAction) || filled($page->defaultTableAction), 409);

        if ($bulk) {
            $page->selectedTableRecords = $proposal['ids'];
            $page->deselectedTableRecords = [];
            $page->isTrackingDeselectedTableRecords = false;
            $this->verifySelection($page, $proposal['ids']);
            $action = $page->getTable()->getBulkAction($actionName);
            abort_unless($action instanceof BulkAction && $action->shouldFetchSelectedRecords(), 403);
            abort_if($operation === 'bulk_delete' && ! $action instanceof DeleteBulkAction, 403);
        } else {
            $action = $page->getAction($actionName, isMounting: false);
            abort_unless($action instanceof DeleteAction, 403);
        }

        abort_unless($action->isVisible() && ! $action->isDisabled() && $action->isAuthorized()
            && $action->isConfirmationRequired() && $action->shouldOpenModal(), 403);

        $details = $bulk
            ? __('filament-ai-assistant::assistant.actions.confirm_many', ['count' => count($proposal['ids'])])
            : __('filament-ai-assistant::assistant.actions.confirm_one', ['record' => $this->recordPreview($page::getResource(), $page->getRecord())['label']]);
        if ($warning = $this->deleteWarning($page::getResource(), $operation,
            $bulk ? $page->getSelectedTableRecords()->first() : $page->getRecord())) {
            $details .= ' '.$warning;
        }
        $action->modalDescription($details);

        $page->forceRender();
        $page->mountAction($actionName, context: $this->actionContext($id, $bulk));
        abort_unless($page->getMountedAction()?->getName() === $actionName, 403);
        session()->put($this->reviewKey($page), $id);
        request()->attributes->set('filament-ai-assistant.action-component', $page->getId());
    }

    /** @return array<string,mixed>|null */
    public function beforeConfirm(ListRecords|EditRecord $page, array $parameters): ?array
    {
        $id = session()->get($this->reviewKey($page));
        $mounted = $page->mountedActions[0] ?? [];

        if ($id === null) {
            abort_if(isset($mounted['context']['assistantAction']), 403);

            return null;
        }

        $proposal = $this->validated($page, $id);
        $operation = $proposal['operation'];
        $bulk = $operation !== 'delete';
        $actionName = $operation === 'bulk_delete' ? 'delete' : $operation;
        abort_unless(count($page->mountedActions ?? []) === 1 && ($mounted['name'] ?? null) === $actionName
            && ($mounted['context'] ?? null) === $this->actionContext($id, $bulk)
            && empty($mounted['arguments'] ?? []) && empty($parameters[0] ?? []), 409);

        if ($bulk) {
            $this->verifySelection($page, $proposal['ids']);
        }

        $action = $page->getMountedAction();
        abort_unless($action?->isAuthorized() && ! $action->isDisabled()
            && $action->isConfirmationRequired() && $action->shouldOpenModal(), 403);
        abort_if($bulk && (! $action instanceof BulkAction || ! $action->shouldFetchSelectedRecords()
            || ($operation === 'bulk_delete' && ! $action instanceof DeleteBulkAction)), 403);
        abort_if(! $bulk && ! $action instanceof DeleteAction, 403);
        abort_unless(Cache::add($this->confirmationKey($id), true, max(1, $proposal['expires'] - now()->timestamp)), 409);

        if ($bulk) {
            $action->successNotificationTitle(fn (): string => __('filament-ai-assistant::assistant.actions.completed', ['count' => count($proposal['ids'])]));
            $action->failureNotificationTitle(fn (int $successCount, int $totalCount): string => __('filament-ai-assistant::assistant.actions.incomplete', [
                'success' => $successCount, 'total' => $totalCount,
            ]));
        }

        return ['id' => $id, ...$proposal];
    }

    /** @param array<string,mixed> $proposal */
    public function afterConfirm(ListRecords|EditRecord $page, array $proposal): void
    {
        session()->forget('filament-ai-assistant.actions.'.$proposal['id']);
        $this->cancel($page);

        Log::info('Filament AI assistant action confirmed.', [
            'actor_id' => Filament::auth()->id(),
            'operation' => $proposal['operation'],
            'resource' => $proposal['resource'],
            'record_ids' => $proposal['ids'],
        ]);
    }

    public function cancel(ListRecords|EditRecord $page): void
    {
        session()->forget($this->reviewKey($page));
    }

    /** @return array<string,mixed> */
    private function validated(ListRecords|EditRecord $page, string $id): array
    {
        $plugin = FilamentAiAssistantPlugin::current();
        $proposal = session()->get('filament-ai-assistant.actions.'.$id);
        $resource = $page::getResource();
        abort_unless($plugin?->canUse() && $proposal !== null && $proposal['scope'] === $plugin->chatScope()
            && $proposal['resource'] === $resource && in_array($proposal['operation'], $this->available($resource), true), 403);
        abort_unless($proposal['expires'] > now()->timestamp, 410, __('filament-ai-assistant::assistant.errors.expired'));
        abort_if(Cache::has($this->confirmationKey($id)), 409);
        $pageName = $proposal['operation'] === 'delete' ? 'edit' : 'index';
        abort_unless(($resource::getPages()[$pageName] ?? null)?->getPage() === $page::class, 403);
        abort_unless($page instanceof EditRecord
            ? $proposal['operation'] === 'delete' && $proposal['ids'] === [(string) $page->getRecord()->getKey()]
            : $proposal['operation'] !== 'delete', 403);

        try {
            $records = $this->records($resource, $proposal['ids']);
        } catch (ValidationException) {
            abort(409, __('filament-ai-assistant::assistant.errors.changed'));
        }

        abort_unless($records->every(fn (Model $record): bool => $this->canAct($resource, $proposal['operation'], $record)), 403);
        abort_unless($this->versions($records) === $proposal['versions'], 409, __('filament-ai-assistant::assistant.errors.changed'));

        return $proposal;
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

    private function canAct(string $resource, string $operation, Model $record): bool
    {
        return match ($operation) {
            'delete' => $resource::canEdit($record) && $resource::canDelete($record),
            'bulk_delete' => $this->canSee($resource, $record) && $resource::canDelete($record),
            default => $resource::canEdit($record),
        };
    }

    private function deleteWarning(string $resource, string $operation, Model $record): ?string
    {
        if (! in_array($operation, ['delete', 'bulk_delete'], true)) {
            return null;
        }

        $details = FilamentAiAssistantPlugin::current()->getDeleteWarning($resource);
        $permanent = in_array(SoftDeletes::class, class_uses_recursive($record), true)
            ? null : __('filament-ai-assistant::assistant.actions.permanent');

        return implode(' ', array_filter([$permanent, $details])) ?: null;
    }

    private function supportsPage(string $resource, string $name, string $type): bool
    {
        $page = $resource::getPages()[$name] ?? null;

        return $page !== null && is_subclass_of($page->getPage(), $type);
    }

    private function selectionKey(): string
    {
        return 'filament-ai-assistant.selections.'.FilamentAiAssistantPlugin::current()->chatScope();
    }

    private function reviewKey(ListRecords|EditRecord $page): string
    {
        return 'filament-ai-assistant.action-reviews.'.$page->getId();
    }

    private function confirmationKey(string $id): string
    {
        return 'filament-ai-assistant:action-confirmation:'.$id;
    }

    /** @return array<string, bool|string> */
    private function actionContext(string $id, bool $bulk): array
    {
        return ['mountedFromUrl' => true, 'assistantAction' => $id, ...($bulk ? ['table' => true, 'bulk' => true] : [])];
    }

    private function invalid(string $key, array $replace = []): never
    {
        throw ValidationException::withMessages(['assistant' => __('filament-ai-assistant::assistant.errors.'.$key, $replace)]);
    }
}
