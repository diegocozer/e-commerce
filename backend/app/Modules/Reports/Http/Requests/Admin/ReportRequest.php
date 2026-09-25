<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Requests\Admin;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Reports\Contracts\Report;
use App\Modules\Reports\DTOs\ReportPeriod;
use App\Modules\Reports\DTOs\ReportQuery;
use App\Modules\Reports\Support\ReportRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Query of GET /admin/reports/{report} (API.md §3.G.15). */
final class ReportRequest extends FormRequest
{
    public const int MAX_RANGE_DAYS = 366;

    public const int CSV_MAX_ROWS = 50000;

    private ?Report $resolved = null;

    public function report(): Report
    {
        return $this->resolved ??= app(ReportRegistry::class)->find((string) $this->route('report'))
            ?? throw new NotFoundHttpException;
    }

    public function authorize(): bool
    {
        $user = $this->user('admin');
        if ($user === null || ! $user->canAny($this->report()->permissions())) {
            return false;
        }

        return ! $this->isCsv() || $user->can('reports.export');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $report = $this->report();
        $dateRule = $report->requiresPeriod() ? 'required' : 'nullable';

        $rules = [
            'date_from' => [$dateRule, 'date_format:Y-m-d', ...($report->requiresPeriod() ? [] : ['required_with:date_to'])],
            'date_to' => [$dateRule, 'date_format:Y-m-d', 'after_or_equal:date_from', ...($report->requiresPeriod() ? [] : ['required_with:date_from'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'format' => ['nullable', Rule::in(['json', 'csv'])],
        ];
        if ($report->supportsGrouping()) {
            $rules['group_by'] = ['nullable', Rule::in(['day', 'week', 'month'])];
        }
        foreach ($report->filters() as $filter) {
            $rules[$filter] = $filter === 'status'
                ? ['nullable', Rule::in(OrderStatus::values())]
                : ['nullable', 'integer', 'min:1'];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'date_from.required' => 'Informe a data inicial.',
            'date_to.required' => 'Informe a data final.',
            'date_from.date_format' => 'Data inválida (use AAAA-MM-DD).',
            'date_to.date_format' => 'Data inválida (use AAAA-MM-DD).',
            'date_to.after_or_equal' => 'A data final deve ser igual ou posterior à inicial.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || $this->query('date_from') === null) {
                return;
            }
            $period = ReportPeriod::fromDates((string) $this->query('date_from'), (string) $this->query('date_to'));
            if ($period->from->diffInDays($period->to) + 1 > self::MAX_RANGE_DAYS) {
                $validator->errors()->add('date_to', 'O período deve ter no máximo '.self::MAX_RANGE_DAYS.' dias.');
            }
        }];
    }

    public function isCsv(): bool
    {
        return $this->query('format') === 'csv';
    }

    public function toReportQuery(): ReportQuery
    {
        $data = $this->validated();
        $report = $this->report();

        if (isset($data['date_from'])) {
            $period = ReportPeriod::fromDates($data['date_from'], $data['date_to'], $report->supportsGrouping() ? ($data['group_by'] ?? 'day') : null);
        } else {
            // inventory without a period: sold_quantity over the last 30 days.
            $today = ReportPeriod::today();
            $period = new ReportPeriod($today->subDays(29), $today);
        }

        $filters = [];
        foreach ($report->filters() as $filter) {
            $filters[$filter] = isset($data[$filter]) ? ($filter === 'status' ? (string) $data[$filter] : (int) $data[$filter]) : null;
        }

        $limit = isset($data['limit']) ? (int) $data['limit'] : null;
        $limit = $this->isCsv()
            ? ($limit ?? self::CSV_MAX_ROWS + 1)
            : ($limit ?? (in_array($report->name(), ReportRegistry::RANKINGS, true) ? 100 : 500));

        return new ReportQuery($period, $filters, $limit);
    }

    /** Only the whitelisted keys; nothing else reaches the report. */
    protected function prepareForValidation(): void
    {
        $this->replace(array_intersect_key($this->query->all(), array_flip([
            'date_from', 'date_to', 'group_by', 'category_id', 'brand_id', 'shipping_method_id', 'status', 'limit', 'format',
        ])));
    }

}
