<?php

namespace App\Http\Requests;

use App\Services\AgentCoreEventInboxService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AgentCoreEventBatchRequest extends FormRequest
{
    public const MAX_EVENTS = 100;
    public const MAX_PAYLOAD_BYTES = 16384;

    public function authorize(): bool
    {
        return $this->attributes->has('agent_company');
    }

    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'in:1'],
            'device_uid' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'events' => ['required', 'array', 'min:1', 'max:' . self::MAX_EVENTS],
            'events.*' => ['required', 'array'],
            'events.*.event_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._:-]+$/'],
            // Optional only for the pre-versioned generic inbox vocabulary.
            'events.*.version' => ['sometimes', 'integer', 'in:1'],
            'events.*.event_type' => ['required', 'string', Rule::in(AgentCoreEventInboxService::V1_EVENT_TYPES)],
            'events.*.scope_lease_id' => ['sometimes', 'integer', 'min:1'],
            'events.*.scope_lease' => ['sometimes', 'string', 'max:128'],
            'events.*.lease_chain' => ['sometimes', 'array'],
            'events.*.lease_chain.lease_id' => ['required_with:events.*.lease_chain', 'integer', 'min:1'],
            'events.*.lease_chain.sequence' => ['required_with:events.*.lease_chain', 'integer', 'min:1'],
            'events.*.lease_chain.prev_hash' => ['required_with:events.*.lease_chain', 'string', 'size:64', 'regex:/^[a-f0-9]+$/'],
            'events.*.lease_chain.signature' => ['required_with:events.*.lease_chain', 'string', 'size:64', 'regex:/^[a-f0-9]+$/'],
            'events.*.occurred_at' => ['nullable', 'date'],
            'events.*.idempotency_key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'events.*.payload' => ['required', 'array'],
            'events.*.scope' => ['required', 'array'],
            'events.*.scope.company_id' => ['required', 'string', 'max:128'],
            'events.*.scope.branch_id' => ['required', 'string', 'max:128'],
            'events.*.scope.device_id' => ['required', 'string', 'max:128'],
            'events.*.scope.user_id' => ['required', 'string', 'max:128'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            foreach ((array) $this->input('events', []) as $index => $event) {
                if (!is_array($event) || !array_key_exists('payload', $event)) {
                    continue;
                }

                try {
                    $bytes = strlen(json_encode($event['payload'], JSON_THROW_ON_ERROR));
                } catch (\JsonException $e) {
                    $validator->errors()->add("events.$index.payload", 'The payload must be JSON serializable.');
                    continue;
                }

                if ($bytes > self::MAX_PAYLOAD_BYTES) {
                    $validator->errors()->add("events.$index.payload", 'The payload may not exceed 16 KiB.');
                }

                // Do this here rather than with nested wildcard rules:
                // FormRequest::validated() must retain arbitrary legacy
                // payload keys exactly as sent for hashing/projection.
                $payload = $event['payload'];
                if (str_starts_with((string) ($payload['schema'] ?? ''), 'local-core.')) {
                    foreach (['command_type', 'aggregate_id', 'aggregate_revision', 'data'] as $field) {
                        if (!array_key_exists($field, $payload)) {
                            $validator->errors()->add("events.$index.payload.$field", 'Canonical Local Core envelope field is required.');
                        }
                    }
                    if (!is_array($payload['data'] ?? null)
                        || !is_int($payload['aggregate_revision'] ?? null)
                        || ($payload['aggregate_revision'] ?? 0) < 1) {
                        $validator->errors()->add("events.$index.payload", 'Canonical Local Core envelope is invalid.');
                    }
                    if (empty($event['lease_chain'])
                        && (empty($event['scope_lease_id']) || empty($event['scope_lease']))) {
                        $validator->errors()->add("events.$index.lease_chain", 'A signed lease chain is required for Local Core commands.');
                    }
                }
                if (array_key_exists('schema', $payload)
                    && (!is_string($payload['schema']) || strlen($payload['schema']) > 128)) {
                    $validator->errors()->add("events.$index.payload.schema", 'The schema must be a string no longer than 128 characters.');
                }
                if (array_key_exists('depends_on', $payload)) {
                    if (!is_array($payload['depends_on']) || count($payload['depends_on']) > 100) {
                        $validator->errors()->add("events.$index.payload.depends_on", 'Dependencies must be an array of no more than 100 event IDs.');
                    } else {
                        foreach ($payload['depends_on'] as $dependencyIndex => $dependency) {
                            if (!is_string($dependency) || strlen($dependency) > 64
                                || !preg_match('/^[A-Za-z0-9._:-]+$/', $dependency)) {
                                $validator->errors()->add(
                                    "events.$index.payload.depends_on.$dependencyIndex",
                                    'Each dependency must be a valid event ID.'
                                );
                            }
                        }
                    }
                }
            }
        });
    }
}