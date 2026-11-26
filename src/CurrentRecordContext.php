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
            'token' => $this->encrypt('filament-ai-assistant-current-record', $plugin->chatScope(), $resource::getSlug($panel), (string) $record->getKey()),
            'record_label' => $this->recordLabel($resource, $record),
            'resource_label' => $resource::getModelLabel(),
        ];
    }

    /** @return array{resource: string, record_id: string, resource_label: string, record_label: string, can_edit: bool}|null */
    public function resolve(string $token): ?array
    {
        $context = $this->decrypt($token, 'filament-ai-assistant-current-record');

        return is_string($context['record'] ?? null) ? $this->forRecord($context['resource'], $context['record']) : null;
    }

    /**
     * @param  class-string<resource>  $resource
     * @return array{token: string, label: string}|null
     */
    public function issueList(string $resource): ?array
    {
        $plugin = FilamentAiAssistantPlugin::current();
        if (! $plugin?->canUse() || ! $plugin->allowsResource($resource) || ! $resource::canViewAny()) {
            return null;
        }

        return [
            'token' => $this->encrypt('filament-ai-assistant-current-list', $plugin->chatScope(), $resource::getSlug(Filament::getCurrentPanel())),
            'label' => $resource::getPluralModelLabel(),
        ];
    }

    /** @return array{resource: string, label: string}|null */
    public function resolveList(string $token): ?array
    {
        $context = $this->decrypt($token, 'filament-ai-assistant-current-list');
        if ($context === null) {
            return null;
        }

        try {
            $resource = $this->resources->resource($context['resource']);
        } catch (ValidationException) {
            return null;
        }

        return ['resource' => $context['resource'], 'label' => $resource::getPluralModelLabel()];
    }

    private function encrypt(string $kind, string $scope, string $resource, ?string $record = null): string
    {
        return Crypt::encryptString(json_encode(compact('kind', 'scope', 'resource', 'record'), JSON_THROW_ON_ERROR));
    }

    /** @return array{resource: string, record?: string|null}|null */
    private function decrypt(string $token, string $kind): ?array
    {
        $plugin = FilamentAiAssistantPlugin::current();
        if (! $plugin?->canUse()) {
            return null;
        }

        try {
            $context = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($context) || ($context['kind'] ?? null) !== $kind
                || ($context['scope'] ?? null) !== $plugin->chatScope()
                || ! is_string($context['resource'] ?? null)) {
                return null;
            }

            return $context;
        } catch (DecryptException|JsonException) {
            return null;
        }
    }

    /** @return array{resource: string, record_id: string, resource_label: string, record_label: string, can_edit: bool}|null */
    public function forRecord(string $slug, string $recordId): ?array
    {
        try {
            $resource = $this->resources->resource($slug);
            $record = $this->resources->record($resource, $recordId);
        } catch (ValidationException) {
            return null;
        }

        $canEdit = $resource::canEdit($record);
        if (! $canEdit && ! $resource::canView($record)) {
            return null;
        }

        return [
            'resource' => $slug,
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
