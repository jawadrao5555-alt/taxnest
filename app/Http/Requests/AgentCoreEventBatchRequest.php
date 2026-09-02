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
            'events.*.event_type' => ['required', 'string', Rule::in(AgentCoreEventInboxService::V1_EVENT_TYPES)],
            'events.*.occurred_at' => ['nullable', 'date'],
            'events.*.idempotency_key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'events.*.payload' => ['required', 'array'],
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
            }
        });
    }
}