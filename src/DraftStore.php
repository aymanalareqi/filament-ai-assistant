<?php

namespace Alareqi\FilamentAiAssistant;

use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class DraftStore
{
    public function __construct(private ResourceRegistry $resources) {}

    /**
     * @param  list<array{field: string, value: mixed}>  $changes
     * @return array{id: string, url: string, label: string, fields: array}
     */
    public function prepare(string $slug, string $operation, ?string $key, array $changes): array
    {
        abort_unless(FilamentAiAssistantPlugin::current()?->canUse(), 403);

        $resource = $this->resources->resource($slug);
        ['form' => $form, 'record' => $record] = $this->resources->form($resource, $operation, $key);
        $fields = $this->resources->fields($form);
        $patch = $preview = [];

        foreach ($changes as $change) {
            $path = $change['field'];

            if (! isset($fields[$path]) || array_key_exists($path, $patch)) {
                throw ValidationException::withMessages(['assistant' => __('filament-ai-assistant::assistant.errors.unsupported_field', ['field' => $path])]);
            }

            $field = $fields[$path];
            $current = $field->getState();
            $proposed = $this->resources->normalize($field, $change['value'] ?? null);
            if ($current === $proposed) {
                continue;
            }

            $patch[$path] = $proposed;
            $preview[] = [
                'label' => strip_tags((string) $field->getLabel()),
                'current' => $this->previewValue($current),
                'proposed' => $this->previewValue($proposed),
            ];
        }

        if ($patch === []) {
            throw ValidationException::withMessages(['assistant' => __('filament-ai-assistant::assistant.errors.no_changes')]);
        }

        $id = (string) Str::uuid();
        $drafts = array_filter(session()->get('filament-ai-assistant.drafts', []), fn (array $draft): bool => $draft['expires'] > now()->timestamp);
        $drafts[$id] = [
            'actor' => $this->actor(),
            'panel' => Filament::getCurrentPanel()->getId(),
            'tenant' => $this->tenant(),
            'resource' => $resource,
            'record' => $record === null ? null : (string) $record->getKey(),
            'version' => $this->fingerprint($record),
            'operation' => $operation,
            'patch' => $patch,
            'expires' => now()->addMinutes(30)->timestamp,
        ];
        session()->put('filament-ai-assistant.drafts', array_slice($drafts, -8, preserve_keys: true));

        return [
            'id' => $id,
            'url' => $resource::getUrl($operation, [
                ...($record === null ? [] : ['record' => $record]),
                'assistantDraft' => $id,
            ]),
            'label' => $resource::getModelLabel(),
            'fields' => $preview,
        ];
    }

    public function isPending(string $id): bool
    {
        return session()->get('filament-ai-assistant.drafts.'.$id.'.expires', 0) > now()->timestamp;
    }

    public function load(CreateRecord|EditRecord $page, string $id): void
    {
        if (session()->get('filament-ai-assistant.reviews.'.$page->getId().'.id') === $id) {
            return;
        }

        $draft = session()->get('filament-ai-assistant.drafts.'.$id);
        $this->authorize($page, $draft);
        abort_unless($draft['expires'] > now()->timestamp, 410, __('filament-ai-assistant::assistant.errors.expired'));
        $this->verifyVersion($page, $draft);

        $fields = $this->resources->fields($page->getSchema('form'));
        foreach ($draft['patch'] as $path => $value) {
            abort_unless(isset($fields[$path]), 409, __('filament-ai-assistant::assistant.errors.changed'));
            $fields[$path]->state($value);
        }

        $reviews = array_filter(session()->get('filament-ai-assistant.reviews', []), fn (array $review): bool => $review['expires'] > now()->timestamp);
        $reviews[$page->getId()] = ['id' => $id, ...$draft, 'patch' => array_keys($draft['patch'])];
        session()->put('filament-ai-assistant.reviews', array_slice($reviews, -16, preserve_keys: true));
    }

    /** @return array<string, mixed>|null */
    public function beforeSave(CreateRecord|EditRecord $page): ?array
    {
        $review = session()->get('filament-ai-assistant.reviews.'.$page->getId());

        if ($review !== null) {
            $this->authorize($page, $review);
            abort_unless($review['expires'] > now()->timestamp && session()->has('filament-ai-assistant.drafts.'.$review['id']), 410, __('filament-ai-assistant::assistant.errors.expired'));
            $review['before'] = $this->draftFieldValues($this->verifyVersion($page, $review), $review['patch']);
        }

        return $review;
    }

    /** @param array<string, mixed> $review */
    public function afterSave(CreateRecord|EditRecord $page, array $review): void
    {
        $record = $page->getRecord();
        $savedRecord = $record?->exists ? $record->fresh() : null;
        if ($savedRecord === null || ($review['operation'] === 'edit' && $this->fingerprint($savedRecord) === $review['version'])) {
            return;
        }

        $before = $review['before'];
        $after = $this->draftFieldValues($savedRecord, $review['patch']);
        $savedFields = array_values(array_filter($review['patch'], fn (string $path): bool => ($before[$path] ?? null) !== ($after[$path] ?? null)
            || array_key_exists($path, $before) !== array_key_exists($path, $after)));

        Log::info('Filament AI assistant draft reviewed and saved.', [
            'actor_id' => $review['actor'],
            'resource' => $review['resource'],
            'record_id' => $savedRecord->getKey(),
            'operation' => $review['operation'],
            'proposed_fields' => $review['patch'],
            'saved_fields' => $savedFields,
        ]);

        session()->forget('filament-ai-assistant.drafts.'.$review['id']);
        session()->forget('filament-ai-assistant.reviews.'.$page->getId());
    }

    /**
     * @param  list<string>  $paths
     * @return array<string, mixed>
     */
    private function draftFieldValues(?Model $record, array $paths): array
    {
        $attributes = $record?->getRawOriginal() ?? [];
        $values = [];

        foreach ($paths as $path) {
            [$attribute, $child] = array_pad(explode('.', $path, 2), 2, null);

            if (! array_key_exists($attribute, $attributes)) {
                continue;
            }

            $value = $attributes[$attribute];
            if ($child === null) {
                $values[$path] = $value;

                continue;
            }

            $nested = is_string($value) ? json_decode($value, true) : $value;
            if (is_array($nested) && Arr::has($nested, $child)) {
                $values[$path] = Arr::get($nested, $child);
            }
        }

        return $values;
    }

    /** @param array<string, mixed>|null $draft */
    private function authorize(CreateRecord|EditRecord $page, ?array $draft): void
    {
        $record = $page instanceof EditRecord ? $page->getRecord() : null;
        $resource = $page::getResource();
        $plugin = FilamentAiAssistantPlugin::current();

        abort_unless($draft !== null && $plugin?->canUse()
            && $plugin->allowsResource($resource)
            && $draft['actor'] === $this->actor()
            && $draft['panel'] === Filament::getCurrentPanel()->getId()
            && $draft['tenant'] === $this->tenant()
            && $draft['resource'] === $resource
            && $draft['operation'] === ($record === null ? 'create' : 'edit')
            && $draft['record'] === ($record === null ? null : (string) $record->getKey())
            && $resource::canViewAny()
            && ($record === null ? $resource::canCreate() : $resource::canEdit($record)), 403);
    }

    /** @param array<string, mixed> $draft */
    private function verifyVersion(CreateRecord|EditRecord $page, array $draft): ?Model
    {
        if (! $page instanceof EditRecord) {
            return null;
        }

        $resource = $page::getResource();
        $current = $this->resources->record($resource, (string) $page->getRecord()->getKey());

        abort_unless($resource::canEdit($current), 403);
        abort_unless($this->fingerprint($current) === $draft['version'], 409, __('filament-ai-assistant::assistant.errors.changed'));

        return $current;
    }

    private function fingerprint(?Model $record): ?string
    {
        if ($record === null) {
            return null;
        }

        $attributes = $record->getRawOriginal();
        ksort($attributes);

        return hash('sha256', serialize($attributes));
    }

    private function actor(): string
    {
        return (string) Filament::auth()->id();
    }

    private function tenant(): ?string
    {
        $tenant = Filament::getTenant();

        return $tenant === null ? null : $tenant::class.':'.$tenant->getKey();
    }

    private function previewValue(mixed $value): string
    {
        return match (true) {
            $value === null, $value === '' => '—',
            is_bool($value) => __('filament-ai-assistant::assistant.'.($value ? 'yes' : 'no')),
            is_scalar($value) => Str::limit(html_entity_decode(strip_tags((string) $value)), 400),
            default => '—',
        };
    }
}
