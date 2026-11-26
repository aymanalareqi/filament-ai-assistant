<?php

namespace Alareqi\FilamentAiAssistant;

use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

final class ResourceRegistry
{
    /** @return array<string, class-string<resource>> */
    private function resources(): array
    {
        $panel = Filament::getCurrentPanel();
        $plugin = FilamentAiAssistantPlugin::current();
        $resources = [];

        if (! $plugin?->canUse()) {
            return [];
        }

        foreach ($panel?->getResources() ?? [] as $resource) {
            if ($plugin->allowsResource($resource) && $resource::canViewAny()) {
                $resources[$resource::getSlug($panel)] = $resource;
            }
        }

        return $resources;
    }

    /** @return array<array{id: string, label: string, create: bool, edit: bool, native_actions: bool}> */
    public function catalog(): array
    {
        $result = [];
        $plugin = FilamentAiAssistantPlugin::current();

        foreach ($this->resources() as $id => $resource) {
            $result[] = [
                'id' => $id,
                'label' => $resource::getPluralModelLabel(),
                'create' => $this->supports($resource, 'create') && $resource::canCreate(),
                'edit' => $this->supports($resource, 'edit'),
                'native_actions' => $plugin->hasActionDiscovery($resource),
            ];
        }

        return $result;
    }

    /** @return class-string<resource> */
    public function resource(string $id): string
    {
        return $this->resources()[$id] ?? $this->invalid(__('filament-ai-assistant::assistant.errors.resource_unavailable'));
    }

    /** @param class-string<resource> $resource */
    private function supports(string $resource, string $operation): bool
    {
        $page = $resource::getPages()[$operation] ?? null;

        return $page !== null && is_subclass_of($page->getPage(), $operation === 'create' ? CreateRecord::class : EditRecord::class);
    }

    /** @param class-string<resource> $resource */
    public function record(string $resource, string $key): Model
    {
        return $resource::getEloquentQuery()->whereKey($key)->first()
            ?? $this->invalid(__('filament-ai-assistant::assistant.errors.record_missing'));
    }

    /** @return array<int, array{id: string, label: string, can_edit: bool}> */
    public function search(string $id, string $term): array
    {
        $resource = $this->resource($id);
        $term = Str::limit(trim($term), 100, '');
        $query = $resource::getEloquentQuery();
        $model = $query->getModel();
        $columns = $this->searchableLabels($model);
        $locale = app()->getLocale();
        $fallback = (string) config('app.fallback_locale', 'en');

        if ($term === '') {
            $this->invalid(__('filament-ai-assistant::assistant.errors.search_term'));
        }

        $numeric = ctype_digit($term);
        $key = match (true) {
            ! $numeric => null,
            in_array($model->getKeyType(), ['int', 'integer'], true) => filter_var(ltrim($term, '0') ?: '0', FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE),
            default => $term,
        };

        if ($key === null && $columns === []) {
            $this->invalid(__('filament-ai-assistant::assistant.errors.search_id'));
        }

        $operator = $numeric ? '=' : 'like';
        $value = $numeric ? $term : '%'.$term.'%';

        $query->where(function (Builder $matches) use ($key, $columns, $model, $operator, $value, $locale, $fallback): void {
            if ($key !== null) {
                $matches->whereKey($key);
            }

            foreach ($columns as $column) {
                $qualifiedColumn = $model->qualifyColumn($column);

                if (method_exists($model, 'isTranslatableAttribute') && $model->isTranslatableAttribute($column)) {
                    $matches->orWhere($qualifiedColumn.'->'.$locale, $operator, $value)
                        ->orWhere($qualifiedColumn.'->'.$fallback, $operator, $value);
                } else {
                    $matches->orWhere($qualifiedColumn, $operator, $value);
                }
            }
        });

        return $query->limit(12)->get()
            ->filter(fn (Model $record): bool => $resource::canView($record) || $resource::canEdit($record))
            ->take(8)->map(function (Model $record) use ($resource, $columns): array {
                $label = collect($columns)->map(fn (string $column): mixed => $record->getAttribute($column))
                    ->first(fn (mixed $value): bool => is_string($value) && filled($value));

                return [
                    'id' => (string) $record->getKey(),
                    'label' => is_string($label) ? Str::limit(strip_tags($label), 120) : '#'.$record->getKey(),
                    'can_edit' => $this->supports($resource, 'edit') && $resource::canEdit($record),
                ];
            })->values()->all();
    }

    /** @return list<string> */
    private function searchableLabels(Model $model): array
    {
        $columns = $model->getConnection()->getSchemaBuilder()->getColumnListing($model->getTable());

        return array_values(array_intersect(['name', 'title', 'code', 'slug', 'sku', 'order_number'], $columns));
    }

    /**
     * @param  class-string<resource>  $resource
     * @return array{page: CreateRecord|EditRecord, form: Schema, record: ?Model}
     */
    public function form(string $resource, string $operation, ?string $key): array
    {
        if (! in_array($operation, ['create', 'edit'], true) || ! $this->supports($resource, $operation)) {
            $this->invalid(__('filament-ai-assistant::assistant.errors.operation_unavailable'));
        }

        $page = Livewire::new($resource::getPages()[$operation]->getPage());
        $record = null;

        if ($operation === 'edit') {
            if ($key === null || $key === '') {
                $this->invalid(__('filament-ai-assistant::assistant.errors.select_record'));
            }

            $record = $this->record($resource, $key);

            if (! $resource::canEdit($record)) {
                $this->invalid(__('filament-ai-assistant::assistant.errors.edit_denied'));
            }

            $page->mount($record->getRouteKey());
        } else {
            if (! $resource::canCreate()) {
                $this->invalid(__('filament-ai-assistant::assistant.errors.create_denied'));
            }

            $page->mount();
        }

        return ['page' => $page, 'form' => $page->getSchema('form'), 'record' => $record];
    }

    /** @return array<string, Field> */
    public function fields(Schema $form): array
    {
        $page = $form->getLivewire();
        $plugin = FilamentAiAssistantPlugin::current();
        $fields = [];

        if ((! $page instanceof CreateRecord && ! $page instanceof EditRecord) || $plugin === null) {
            return [];
        }

        $resource = $page::getResource();

        foreach ($form->getFlatFields(withAbsoluteKeys: true) as $field) {
            $path = Str::after($field->getStatePath(), 'data.');

            if (! preg_match('/^[a-z][a-z0-9_]*(?:\.[a-zA-Z][a-zA-Z0-9_-]*)?$/D', $path)
                || preg_match('/password|secret|token|credential|private|otp|phone|email|balance|^roles?$|permission|configuration|metadata|payload|(?:^|_)key(?:_|$)/i', $path)
                || ! $plugin->allowsField($resource, $path)
                || $field->isHidden() || $field->isDisabled() || ! $field->isDehydrated()
                || (method_exists($field, 'isReadOnly') && $field->isReadOnly())
                || ($field instanceof TextInput && $field->isPassword())
                || ($field instanceof RichEditor && $field->isJson())
                || ($field instanceof Select && ($field->hasRelationship() || $field->isMultiple()))) {
                continue;
            }

            if ($field instanceof TextInput || $field instanceof Textarea || $field instanceof Toggle
                || $field instanceof DateTimePicker || $field instanceof RichEditor || $field instanceof Select) {
                $fields[$path] = $field;
            }
        }

        return $fields;
    }

    /** @return array<int, array<string, mixed>> */
    public function describe(Schema $form): array
    {
        $result = [];

        foreach ($this->fields($form) as $path => $field) {
            $value = $field->getState();
            $result[] = [
                'key' => $path,
                'label' => strip_tags((string) $field->getLabel()),
                'type' => match (true) {
                    $field instanceof Toggle => 'boolean',
                    $field instanceof DateTimePicker => $field->getFormat(),
                    $field instanceof TextInput && $field->isNumeric() => 'number',
                    $field instanceof Select => 'choice',
                    default => 'text',
                },
                'required' => $field->isRequired(),
                'current' => is_string($value) ? Str::limit(strip_tags($value), 2000) : (is_scalar($value) ? $value : null),
                ...($field instanceof Select ? ['options' => $this->options($field)] : []),
            ];
        }

        return $result;
    }

    /** @return array<string|int, string> */
    private function options(Select $field): array
    {
        $options = [];

        foreach ($field->getOptions() as $key => $label) {
            foreach (is_array($label) ? $label : [$key => $label] as $value => $option) {
                if (! $field->isOptionDisabled($value, $option)) {
                    $options[$value] = strip_tags((string) $option);
                }
            }
        }

        return array_slice($options, 0, 80, preserve_keys: true);
    }

    public function normalize(Field $field, mixed $value): string|bool|null
    {
        if ($value === null) {
            return null;
        }

        if ($field instanceof Toggle) {
            return match ($value) {
                true, 'true', '1' => true,
                false, 'false', '0' => false,
                default => $this->invalid(__('filament-ai-assistant::assistant.errors.boolean')),
            };
        }

        if ((! is_string($value) && ! is_int($value) && ! is_float($value)) || mb_strlen((string) $value) > 6000) {
            $this->invalid(__('filament-ai-assistant::assistant.errors.value'));
        }

        $value = (string) $value;

        if ($field instanceof Select && ! array_key_exists($value, $this->options($field))) {
            $this->invalid(__('filament-ai-assistant::assistant.errors.option'));
        }

        if ($field instanceof TextInput && $field->isNumeric() && ! is_numeric($value)) {
            $this->invalid(__('filament-ai-assistant::assistant.errors.number'));
        }

        return $field instanceof RichEditor ? '<p>'.nl2br(e($value), false).'</p>' : $value;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['assistant' => $message]);
    }
}
