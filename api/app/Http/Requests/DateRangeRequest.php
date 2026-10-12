<?php

namespace App\Http\Requests;

use App\Enums\Provider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shared query parameters for the event endpoints.
 *
 * `start` / `end` accept any date or ISO-8601 datetime. Values without an explicit
 * offset are interpreted in the `timezone` parameter, else the authenticated user's
 * timezone. Defaults: start of today -> +30 days.
 */
class DateRangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'start' => ['nullable', 'date'],
            'end' => ['nullable', 'date'],
            'timezone' => ['nullable', 'timezone:all'],
            'calendar_ids' => ['nullable', 'array'],
            'calendar_ids.*' => ['integer'],
            'provider' => ['nullable', Rule::enum(Provider::class)],
            'q' => ['nullable', 'string', 'max:100'],
            'dedupe' => ['nullable', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                [$start, $end] = $this->range();
                $maxDays = config('timejump.events.max_range_days');

                if ($end->lte($start)) {
                    $validator->errors()->add('end', 'The end must be after the start.');
                } elseif ($start->diffInDays($end) > $maxDays) {
                    $validator->errors()->add('end', "The date range may not exceed {$maxDays} days.");
                }
            },
        ];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable} UTC start and end
     */
    public function range(): array
    {
        $timezone = $this->input('timezone') ?: ($this->user()?->timezone ?: 'UTC');

        $start = $this->filled('start')
            ? CarbonImmutable::parse($this->input('start'), $timezone)
            : CarbonImmutable::now($timezone)->startOfDay();

        $end = $this->filled('end')
            ? CarbonImmutable::parse($this->input('end'), $timezone)
            : $start->addDays(config('timejump.events.default_range_days'));

        return [$start->utc(), $end->utc()];
    }

    public function filters(): array
    {
        return array_filter([
            'calendar_ids' => array_map('intval', (array) $this->input('calendar_ids', [])),
            'provider' => $this->input('provider'),
            'search' => $this->input('q'),
            'dedupe' => $this->boolean('dedupe'),
        ]);
    }

    public function meta(): array
    {
        [$start, $end] = $this->range();

        return [
            'start' => $start->toIso8601ZuluString(),
            'end' => $end->toIso8601ZuluString(),
        ];
    }
}
