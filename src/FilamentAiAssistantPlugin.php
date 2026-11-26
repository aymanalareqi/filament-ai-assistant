<?php

namespace Alareqi\FilamentAiAssistant;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Filament\Resources\Resource;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

final class FilamentAiAssistantPlugin implements Plugin
{
    private ?Closure $authorizer = null;

    private ?Closure $resourceFilter = null;

    private ?Closure $fieldFilter = null;

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
        $panel->renderHook(
            PanelsRenderHook::BODY_END,
            fn (): View|string => $this->canUse() ? view('filament-ai-assistant::launcher') : '',
        );
    }

    public function boot(Panel $panel): void {}

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
}
