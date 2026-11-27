<?php

namespace Alareqi\FilamentAiAssistant;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

final class AssistantActivityPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $slug = 'assistant-activity';

    protected static ?int $navigationSort = 105;

    protected string $view = 'filament-ai-assistant::activity';

    public static function canAccess(): bool
    {
        return FilamentAiAssistantPlugin::current()?->canViewActivity() ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return __('filament-ai-assistant::activity.title');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return FilamentAiAssistantPlugin::current()?->getActivityNavigationGroup();
    }

    public function getTitle(): string
    {
        return self::getNavigationLabel();
    }

    public function getSubheading(): string
    {
        return __('filament-ai-assistant::activity.description');
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    public function table(Table $table): Table
    {
        abort_unless(self::canAccess(), 403);

        return $table
            ->query(fn (): Builder => AssistantActivity::query()->where('scope', AssistantActivityRecorder::scope()))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('filament-ai-assistant::activity.time'))->dateTime()->sortable(),
                TextColumn::make('actor_name')->label(__('filament-ai-assistant::activity.actor'))
                    ->description(fn (AssistantActivity $record): string => '#'.$record->actor_id)
                    ->searchable(['actor_name', 'actor_id']),
                TextColumn::make('action_label')->label(__('filament-ai-assistant::activity.action'))
                    ->description(fn (AssistantActivity $record): string => $record->resource_label)
                    ->formatStateUsing(fn (string $state, AssistantActivity $record): string => match ($record->operation) {
                        'draft:create' => __('filament-ai-assistant::activity.create'),
                        'draft:edit' => __('filament-ai-assistant::activity.edit'),
                        default => $state,
                    })->searchable(['action_label', 'operation', 'resource', 'resource_label']),
                TextColumn::make('record_ids')->label(__('filament-ai-assistant::activity.records'))
                    ->badge()->limitList(4)->expandableLimitedList()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereJsonContains('record_ids', ltrim($search, '#'))),
                TextColumn::make('outcome')->label(__('filament-ai-assistant::activity.outcome'))
                    ->badge()->formatStateUsing(self::outcomeLabel(...))
                    ->color(fn (string $state): string => match ($state) {
                        AssistantActivity::SUCCEEDED => 'success',
                        AssistantActivity::FAILED => 'danger',
                        default => 'warning',
                    }),
            ])
            ->filters([
                SelectFilter::make('outcome')->label(__('filament-ai-assistant::activity.outcome'))
                    ->options([
                        AssistantActivity::SUCCEEDED => self::outcomeLabel(AssistantActivity::SUCCEEDED),
                        AssistantActivity::FAILED => self::outcomeLabel(AssistantActivity::FAILED),
                        AssistantActivity::INTERRUPTED => self::outcomeLabel(AssistantActivity::INTERRUPTED),
                    ]),
            ])
            ->emptyStateHeading(__('filament-ai-assistant::activity.empty'));
    }

    private static function outcomeLabel(string $state): string
    {
        return __('filament-ai-assistant::activity.outcomes.'.$state);
    }
}
