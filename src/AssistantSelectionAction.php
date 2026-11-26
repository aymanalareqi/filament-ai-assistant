<?php

namespace Alareqi\FilamentAiAssistant;

use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Validation\ValidationException;

final class AssistantSelectionAction
{
    public static function make(): BulkAction
    {
        return BulkAction::make('sendSelectionToAssistant')
            ->label(fn (): string => __('filament-ai-assistant::assistant.actions.use_selection'))
            ->icon('heroicon-o-sparkles')
            ->authorize(fn (BulkAction $action): bool => self::canUse($action))
            ->action(function (BulkAction $action): void {
                $page = $action->getLivewire();
                abort_unless($page instanceof ListRecords, 403);

                try {
                    app(ActionProposalStore::class)->captureSelection($page);
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();

                    return;
                }

                $page->dispatch('filament-ai-assistant-selection');
            });
    }

    private static function canUse(BulkAction $action): bool
    {
        $page = $action->getLivewire();

        return $page instanceof ListRecords
            && array_diff(app(ActionProposalStore::class)->available($page::getResource()), ['delete']) !== [];
    }
}
