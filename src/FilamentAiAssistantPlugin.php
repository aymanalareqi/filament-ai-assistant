<?php

namespace Alareqi\FilamentAiAssistant;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\DeleteBulkAction;
use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Resources\Resource;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/** @phpstan-type QuickPrompt array{label: string, prompt: string, icon?: string, requiresRecord?: bool} */
final class FilamentAiAssistantPlugin implements Plugin
{
    /** @var string|Closure(): string|null */
    private string|Closure|null $instructions = null;

    /** @var list<QuickPrompt>|(Closure(): list<QuickPrompt>)|null */
    private array|Closure|null $quickPrompts = null;

    /** @var bool|(Closure(): bool)|null */
    private bool|Closure|null $liveProgress = null;

    private ?Closure $authorizer = null;

    private ?Closure $activityAuthorizer = null;

    private string|UnitEnum|null $activityNavigationGroup = null;

    private ?Closure $resourceFilter = null;

    private ?Closure $fieldFilter = null;

    private bool $discoversActions = false;

    private ?Closure $actionFilter = null;

    /** @var array<class-string<resource>, list<string>> */
    private array $resourceActions = [];

    /** @var array<class-string<resource>, true> */
    private array $bulkDeleteResources = [];

    /** @var array<class-string<resource>, Closure(): string> */
    private array $deleteWarnings = [];

    public static function make(): self
    {
        return new self;
    }

    public static function current(): ?self
    {
        $panel = Filament::getCurrentPanel();

        if (! $panel?->hasPlugin('filament-ai-assistant')) {
            return null;
        }

        $plugin = $panel->getPlugin('filament-ai-assistant');

        return $plugin instanceof self ? $plugin : null;
    }

    public function getId(): string
    {
        return 'filament-ai-assistant';
    }

    public function register(Panel $panel): void
    {
        if ($this->recordsActivity()) {
            $panel->pages([AssistantActivityPage::class]);
        }

        $panel->renderHook(
            PanelsRenderHook::BODY_END,
            fn (): View|string => $this->canUse()
                ? view('filament-ai-assistant::launcher', ['chatScope' => $this->chatScope()])
                : '',
        );
    }

    public function boot(Panel $panel): void {}

    /** @param Closure(Authenticatable, Panel): bool $authorize */
    public function withActivity(Closure $authorize, string|UnitEnum|null $navigationGroup = null): self
    {
        $this->activityAuthorizer = $authorize;
        $this->activityNavigationGroup = $navigationGroup;

        return $this;
    }

    public function recordsActivity(): bool
    {
        return $this->activityAuthorizer !== null;
    }

    public function canViewActivity(): bool
    {
        $panel = Filament::getCurrentPanel();
        $user = $panel?->auth()->user();

        return $this->activityAuthorizer !== null && $user !== null
            && ($this->activityAuthorizer)($user, $panel);
    }

    public function getActivityNavigationGroup(): string|UnitEnum|null
    {
        return $this->activityNavigationGroup;
    }

    /** @param string|Closure(): string $instructions */
    public function instructions(string|Closure $instructions): self
    {
        $this->instructions = $instructions;

        return $this;
    }

    public function getInstructions(): ?string
    {
        return $this->instructions instanceof Closure ? ($this->instructions)() : $this->instructions;
    }

    /** @param bool|(Closure(): bool) $enabled */
    public function liveProgress(bool|Closure $enabled = true): self
    {
        $this->liveProgress = $enabled;

        return $this;
    }

    public function hasLiveProgress(): bool
    {
        return $this->liveProgress instanceof Closure
            ? ($this->liveProgress)()
            : ($this->liveProgress ?? (bool) config('filament-ai-assistant.live_progress', true));
    }

    /** @param list<QuickPrompt>|(Closure(): list<QuickPrompt>) $prompts */
    public function quickPrompts(array|Closure $prompts): self
    {
        $this->quickPrompts = $prompts;

        return $this;
    }

    /** @return list<QuickPrompt> */
    public function getQuickPrompts(): array
    {
        if ($this->quickPrompts instanceof Closure) {
            return ($this->quickPrompts)();
        }

        return $this->quickPrompts ?? [
            [
                'label' => __('filament-ai-assistant::assistant.examples.manage'),
                'prompt' => __('filament-ai-assistant::assistant.examples.manage'),
                'icon' => 'heroicon-o-chat-bubble-left-right',
            ],
            [
                'label' => __('filament-ai-assistant::assistant.examples.find'),
                'prompt' => __('filament-ai-assistant::assistant.examples.find'),
                'icon' => 'heroicon-o-magnifying-glass',
            ],
        ];
    }

    /**
     * @param  class-string<resource>  $resource
     * @param  list<string>  $actions
     * @param  (Closure(): string)|null  $deleteWarning
     */
    public function actionsFor(string $resource, array $actions, ?Closure $deleteWarning = null): self
    {
        $this->resourceActions[$resource] = $actions;

        if ($deleteWarning !== null) {
            $this->deleteWarnings[$resource] = $deleteWarning;
        }

        return $this;
    }

    /** @return list<string> */
    public function getResourceActions(string $resource): array
    {
        return $this->resourceActions[$resource] ?? [];
    }

    public function discoverActions(bool $enabled = true): self
    {
        $this->discoversActions = $enabled;

        return $this;
    }

    /** @param class-string<resource> $resource */
    public function allowBulkDeleteFor(string $resource): self
    {
        $this->bulkDeleteResources[$resource] = true;

        return $this;
    }

    /** @param Closure(Action, class-string<resource>, string): bool $callback */
    public function allowActionUsing(Closure $callback): self
    {
        $this->actionFilter = $callback;

        return $this;
    }

    public function hasActionDiscovery(string $resource): bool
    {
        return $this->discoversActions || $this->getResourceActions($resource) !== [];
    }

    public function allowsNativeAction(string $resource, string $key, Action $action): bool
    {
        if ($this->actionFilter !== null && ! ($this->actionFilter)($action, $resource, $key)) {
            return false;
        }

        if (array_key_exists($resource, $this->resourceActions)) {
            $actions = array_map(app(NativeResourceActions::class)->canonical(...), $this->getResourceActions($resource));

            return in_array($key, $actions, true);
        }

        return $this->discoversActions
            && (! $action instanceof DeleteBulkAction || isset($this->bulkDeleteResources[$resource]));
    }

    public function deleteWarningFor(string $resource, Closure $warning): self
    {
        $this->deleteWarnings[$resource] = $warning;

        return $this;
    }

    public function getDeleteWarning(string $resource): ?string
    {
        return isset($this->deleteWarnings[$resource]) ? ($this->deleteWarnings[$resource])() : null;
    }

    /** @param Closure(Authenticatable, Panel): bool $callback */
    public function authorizeUsing(Closure $callback): self
    {
        $this->authorizer = $callback;

        return $this;
    }

    /** @param Closure(class-string<resource>): bool $callback */
    public function allowResourceUsing(Closure $callback): self
    {
        $this->resourceFilter = $callback;

        return $this;
    }

    /** @param class-string<resource> $resource */
    public function allowsResource(string $resource): bool
    {
        return $this->resourceFilter === null || ($this->resourceFilter)($resource);
    }

    /** @param Closure(class-string<resource>, string): bool $callback */
    public function allowFieldUsing(Closure $callback): self
    {
        $this->fieldFilter = $callback;

        return $this;
    }

    /** @param class-string<resource> $resource */
    public function allowsField(string $resource, string $path): bool
    {
        return $this->fieldFilter === null || ($this->fieldFilter)($resource, $path);
    }

    public function canUse(): bool
    {
        $panel = Filament::getCurrentPanel();
        $user = $panel?->auth()->user();

        return $user !== null && ($this->authorizer !== null
            ? ($this->authorizer)($user, $panel)
            : Gate::forUser($user)->allows('use-filament-ai-assistant'));
    }

    public function chatScope(): string
    {
        $panel = Filament::getCurrentPanel();
        $tenant = Filament::getTenant();

        return hash('sha256', serialize([
            $panel->getId(),
            $panel->getAuthGuard(),
            (string) $panel->auth()->id(),
            $tenant === null ? null : [$tenant::class, (string) $tenant->getKey()],
        ]));
    }
}
