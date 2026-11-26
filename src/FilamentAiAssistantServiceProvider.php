<?php

namespace Alareqi\FilamentAiAssistant;

use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\EditRecord;
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
            if (! $component instanceof CreateRecord && ! $component instanceof EditRecord) {
                return;
            }

            $id = request()->query('assistantDraft');
            if (is_string($id) && Str::isUuid($id) && FilamentAiAssistantPlugin::current() !== null) {
                app(DraftStore::class)->load($component, $id);
            }
        });

        Livewire::listen('call', static function (Component $component, string $method): ?callable {
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
