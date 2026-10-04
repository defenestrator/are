<?php

namespace App\Http\Requests;

use App\Analytics\DateRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The date range for the attribution page and its CSV: a preset, or a
 * from/to pair of dates. With neither, it is this ISO week.
 */
class AttributionRangeRequest extends FormRequest
{
    /** A bad range goes back to the default week, with the error shown. */
    protected $redirectRoute = 'admin.attribution';

    public function authorize(): bool
    {
        return $this->user()?->can('viewAttribution') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'preset' => ['nullable', Rule::in(array_keys(DateRange::PRESETS))],
            'from' => ['nullable', 'required_with:to', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    public function range(): DateRange
    {
        if ($this->filled('from') && $this->filled('to')) {
            return DateRange::days($this->string('from')->value(), $this->string('to')->value());
        }

        return DateRange::preset($this->string('preset')->value() ?: 'this_week');
    }
}
