<?php

namespace Alareqi\FilamentAiAssistant;

use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\Livewire;

final class FilamentAiAssistantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/filament-ai-assistant.php', 'filament-ai-assistant');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'filament-ai-assistant');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'filament-ai-assistant');
        Livewire::component('filament-ai-assistant.chat', AssistantChat::class);
        $this->publishes([
            __DIR__.'/../config/filament-ai-assistant.php' => config_path('filament-ai-assistant.php'),
        ], 'filament-ai-assistant-config');

        FilamentAsset::register([
            Css::make('chat', __DIR__.'/../resources/css/chat.css'),
        ], 'alareqi/filament-ai-assistant');

        // The native resource page has mounted and authorized the record before rendering its form.
        Livewire::listen('render', static function (Component $component): void {
            if (($component instanceof EditRecord || $component instanceof ViewRecord)
                && request()->isMethod('GET') && request()->routeIs($component::getRouteName())) {
                request()->attributes->set('filament-ai-assistant.current-record',
                    app(CurrentRecordContext::class)->issue($component::getResource(), $component->getRecord()));
            } elseif ($component instanceof ListRecords
                && request()->isMethod('GET') && request()->routeIs($component::getRouteName())) {
                $listContext = app(CurrentRecordContext::class)->issueList($component::getResource());
                request()->attributes->set('filament-ai-assistant.current-list',
                    $listContext === null ? null : [...$listContext, 'component' => $component->getId()]);
            }

            $actionId = request()->query('assistantAction');
            if (($component instanceof ListRecords || $component instanceof EditRecord)
                && is_string($actionId) && Str::isUuid($actionId)) {
                app(ActionProposalStore::class)->open($component, $actionId);

                return;
            }

            if (! $component instanceof CreateRecord && ! $component instanceof EditRecord) {
                return;
            }

            $id = request()->query('assistantDraft');
            if (is_string($id) && Str::isUuid($id) && FilamentAiAssistantPlugin::current() !== null) {
                app(DraftStore::class)->load($component, $id);
            }
        });

        Livewire::listen('call', static function (Component $component, string $method, array $parameters): ?callable {
            if ($component instanceof ListRecords || $component instanceof EditRecord) {
                $proposals = app(ActionProposalStore::class);

                if (in_array($method, ['unmountAction', 'unmountTableBulkAction'], true)) {
                    return static function ($result) use ($component, $proposals) {
                        if (blank($component->mountedActions)) {
                            $proposals->cancel($component);
                        }

                        return $result;
                    };
                }

                if (in_array($method, ['callMountedAction', 'callMountedTableAction', 'callMountedTableBulkAction'], true)) {
                    $proposal = $proposals->beforeConfirm($component, $parameters);

                    return $proposal === null ? null : static function ($result) use ($component, $proposals, $proposal) {
                        $proposals->afterConfirm($component, $proposal);

                        return $result;
                    };
                }
            }

            if (! (($method === 'create' && $component instanceof CreateRecord)
                || (in_array($method, ['save', 'saveFormComponentOnly'], true) && $component instanceof EditRecord))) {
                return null;
            }

            $store = app(DraftStore::class);
            $review = $store->beforeSave($component);

            return $review === null ? null : static function ($result) use ($store, $component, $review) {
                $store->afterSave($component, $review);

                return $result;
            };
        });
    }
}
