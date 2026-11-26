<?php

namespace Alareqi\FilamentAiAssistant;

use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;

final class CurrentRecordContext
{
    public function __construct(private ResourceRegistry $resources) {}

    /**
     * @param  class-string<resource>  $resource
     * @return array{token: string, record_label: string, resource_label: string}|null
     */
    public function issue(string $resource, Model $record): ?array
    {
        $plugin = FilamentAiAssistantPlugin::current();
        $panel = Filament::getCurrentPanel();

        if (! $plugin?->canUse() || ! $plugin->allowsResource($resource) || ! $resource::canViewAny()
            || ! ($resource::canView($record) || $resource::canEdit($record))) {
            return null;
        }

        return [
            'token' => Crypt::encryptString(json_encode([
                'kind' => 'filament-ai-assistant-current-record',
                'scope' => $plugin->chatScope(),
                'resource' => $resource::getSlug($panel),
                'record' => (string) $record->getKey(),
            ], JSON_THROW_ON_ERROR)),
            'record_label' => $this->recordLabel($resource, $record),
            'resource_label' => $resource::getModelLabel(),
        ];
    }

    /** @return array{resource: string, record_id: string, resource_label: string, record_label: string, can_edit: bool}|null */
    public function resolve(string $token): ?array
    {
        $plugin = FilamentAiAssistantPlugin::current();
        if (! $plugin?->canUse()) {
            return null;
        }

        try {
            $context = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($context) || ($context['kind'] ?? null) !== 'filament-ai-assistant-current-record'
                || ($context['scope'] ?? null) !== $plugin->chatScope()
                || ! is_string($context['resource'] ?? null) || ! is_string($context['record'] ?? null)) {
                return null;
            }

            $resource = $this->resources->resource($context['resource']);
            $record = $this->resources->record($resource, $context['record']);
        } catch (DecryptException|JsonException|ValidationException) {
            return null;
        }

        $canEdit = $resource::canEdit($record);
        if (! $canEdit && ! $resource::canView($record)) {
            return null;
        }

        return [
            'resource' => $context['resource'],
            'record_id' => (string) $record->getKey(),
            'resource_label' => $resource::getModelLabel(),
            'record_label' => $this->recordLabel($resource, $record),
            'can_edit' => $canEdit,
        ];
    }

    /** @param class-string<resource> $resource */
    private function recordLabel(string $resource, Model $record): string
    {
        return Str::limit(trim(strip_tags((string) $resource::getRecordTitle($record))), 120) ?: '#'.$record->getKey();
    }
}
