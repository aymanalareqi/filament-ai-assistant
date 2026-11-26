<?php

namespace Alareqi\FilamentAiAssistant;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\Page;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class NativeResourceActions
{
    private const PAGES = [
        'index' => ListRecords::class,
        'create' => CreateRecord::class,
        'view' => ViewRecord::class,
        'edit' => EditRecord::class,
        'row' => ListRecords::class,
        'table' => ListRecords::class,
        'bulk' => ListRecords::class,
    ];

    public function canonical(string $action): string
    {
        return match ($action) {
            'publish', 'unpublish' => 'bulk:'.$action,
            'delete' => 'edit:delete',
            'bulk_delete' => 'bulk:delete',
            default => $action,
        };
    }

    public function surface(string $action): string
    {
        return Str::before($this->canonical($action), ':');
    }

    public function requiresRecord(string $surface): bool
    {
        return in_array($surface, ['row', 'view', 'edit'], true);
    }

    public function pageKey(string $surface): string
    {
        return in_array($surface, ['row', 'table', 'bulk'], true) ? 'index' : $surface;
    }

    public function pageClass(string $resource, string $surface): ?string
    {
        if ($resource::getParentResourceRegistration() !== null) {
            return null;
        }

        $type = self::PAGES[$surface] ?? null;
        $class = ($resource::getPages()[$this->pageKey($surface)] ?? null)?->getPage();

        return $type !== null && $class !== null && is_subclass_of($class, $type) ? $class : null;
    }

    /** @param list<string> $ids */
    public function page(string $resource, string $surface, ?Model $record, array $ids = []): ?Page
    {
        $class = $this->pageClass($resource, $surface);
        if ($class === null) {
            return null;
        }

        $routeRecord = in_array($surface, ['edit', 'view'], true) ? $record : null;
        $parameters = $routeRecord === null ? [] : ['record' => $routeRecord];
        if (! $class::canAccess($parameters)) {
            return null;
        }

        try {
            $page = Livewire::new($class);
            if ($routeRecord !== null) {
                $routeKey = $resource::getRecordRouteKeyName();
                $page->mount($routeKey === null ? $routeRecord->getRouteKey() : $routeRecord->getAttribute($routeKey));
            } else {
                $page->mount();
            }

            if ($page instanceof ListRecords) {
                $page->bootedInteractsWithTable();
                $page->selectedTableRecords = $ids;
            }
            $page->cacheInteractsWithHeaderActions();

            return $page;
        } catch (AuthorizationException) {
            return null;
        } catch (HttpExceptionInterface $exception) {
            if (! in_array($exception->getStatusCode(), [403, 404], true)) {
                throw $exception;
            }

            return null;
        }
    }

    /** @return list<array{id: string, label: string, surface: string, input: bool}> */
    public function describe(string $resource, ?Model $record, array $ids): array
    {
        $result = [];
        $pages = [];
        foreach (array_keys(self::PAGES) as $surface) {
            if (($this->requiresRecord($surface) && $record === null) || ($surface === 'bulk' && $ids === [])) {
                continue;
            }

            $pageKey = $this->pageKey($surface);
            if (! array_key_exists($pageKey, $pages)) {
                $pages[$pageKey] = $this->page($resource, $surface, $record, $pageKey === 'index' ? $ids : []);
            }

            $page = $pages[$pageKey];
            if ($page === null) {
                continue;
            }

            foreach (array_keys($this->actions($page, $surface)) as $name) {
                $key = $surface.':'.$name;
                $action = $this->resolve($page, $key, $record?->getKey());
                if ($action !== null && $this->canReview($page, $key, $action)) {
                    $result[] = ['id' => $key, 'label' => $this->label($action), 'surface' => $surface, 'input' => $page->mountedActionHasSchema($action)];
                }
            }
        }

        return $result;
    }

    public function resolve(Page $page, string $key, string|int|null $recordId): ?Action
    {
        $surface = $this->surface($key);
        $name = Str::after($key, ':');
        $candidate = $this->actions($page, $surface)[$name] ?? null;
        if ($candidate === null || ($surface !== 'bulk' && $candidate instanceof BulkAction)) {
            return null;
        }

        $context = $this->context($key, $recordId);
        if ($surface === 'row' && ($recordId === null || (string) $page->getTableRecord((string) $recordId)?->getKey() !== (string) $recordId)) {
            return null;
        }

        $resolved = $page->getAction([['name' => $name, 'context' => $context]], isMounting: false);

        return $resolved === $candidate ? $resolved : null;
    }

    public function canReview(Page $page, string $key, Action $action): bool
    {
        $plugin = FilamentAiAssistantPlugin::current();
        $bulk = $this->surface($key) === 'bulk';

        return ($plugin?->allowsNativeAction($page::getResource(), $key, $action) ?? false)
            && ($bulk ? $action instanceof BulkAction && $action->shouldFetchSelectedRecords() : ! $action instanceof BulkAction)
            && $action->hasAction() && blank($action->getUrl()) && $action->isLivewireClickHandlerEnabled()
            && $action->isVisible() && ! $action->isDisabled() && $action->isAuthorized()
            && $page->mountedActionShouldOpenModal($action);
    }

    public function label(Action $action): string
    {
        return Str::limit(trim(strip_tags((string) $action->getLabel())), 120) ?: $action->getName();
    }

    /** @return array<string, string|bool> */
    public function context(string $key, string|int|null $recordId): array
    {
        return match ($this->surface($key)) {
            'row' => ['table' => true, 'recordKey' => (string) $recordId],
            'table' => ['table' => true],
            'bulk' => ['table' => true, 'bulk' => true],
            default => [],
        };
    }

    /** @return array<string, Action> */
    private function actions(Page $page, string $surface): array
    {
        if ($page instanceof ListRecords) {
            $table = $page->getTable();
            if ($surface === 'bulk') {
                return $table->getFlatBulkActions();
            }
            if ($surface === 'row') {
                return $this->flatten($table->getRecordActions());
            }
            if ($surface === 'table') {
                return $this->flatten([...$table->getHeaderActions(), ...$table->getToolbarActions()]);
            }
        }

        return in_array($surface, ['index', 'create', 'view', 'edit'], true)
            ? $this->flatten($page->getCachedHeaderActions())
            : [];
    }

    /** @param array<Action|ActionGroup> $actions */
    private function flatten(array $actions): array
    {
        $flat = [];
        foreach ($actions as $action) {
            if ($action instanceof ActionGroup) {
                $flat = [...$flat, ...$action->getFlatActions()];
            } else {
                $flat[$action->getName()] = $action;
            }
        }

        return $flat;
    }
}
