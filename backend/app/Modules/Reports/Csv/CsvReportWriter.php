<?php

declare(strict_types=1);

namespace App\Modules\Reports\Csv;

use App\Modules\Reports\DTOs\ReportPeriod;
use Carbon\CarbonImmutable;

/**
 * pt-BR CSV (API.md §3.G.15): UTF-8 with BOM, ";" separator, "," decimals, dd/mm/aaaa dates,
 * money in reais, and CSV/formula injection protection (cells starting with = + - @ tab CR get a
 * leading apostrophe).
 */
final class CsvReportWriter
{
    public const string BOM = "\xEF\xBB\xBF";

    private const array STATUS_LABELS = [
        'pending_payment' => 'Aguardando pagamento', 'paid' => 'Pago', 'processing' => 'Em separação',
        'shipped' => 'Enviado', 'delivered' => 'Entregue', 'ready_for_pickup' => 'Pronto para retirada',
        'picked_up' => 'Retirado', 'cancelled' => 'Cancelado',
    ];

    /**
     * Writes header + rows to the stream.
     *
     * @param  resource  $stream
     * @param  array<string, array{0: string, 1: string}>  $columns
     * @param  iterable<array<string, mixed>>  $rows
     */
    public function write($stream, array $columns, iterable $rows): void
    {
        fwrite($stream, self::BOM);
        $this->put($stream, array_map(fn (array $c): string => $this->escape($c[0]), array_values($columns)));

        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $key => [, $type]) {
                $line[] = $this->format($row[$key] ?? null, $type);
            }
            $this->put($stream, $line);
        }
    }

    public function format(mixed $value, string $type): string
    {
        if ($value === null) {
            return '';
        }

        return match ($type) {
            'money' => self::money((int) $value),
            'int' => (string) (int) $value,
            'decimal3' => number_format((float) $value, 3, ',', ''),
            'bp' => number_format(((int) $value) / 100, 2, ',', ''),
            'date' => CarbonImmutable::createFromFormat('!Y-m-d', (string) $value)->format('d/m/Y'),
            'datetime' => CarbonImmutable::parse((string) $value)->setTimezone(ReportPeriod::TIMEZONE)->format('d/m/Y H:i'),
            'bool' => $value ? 'Sim' : 'Não',
            'customer_type' => $value === 'company' ? 'PJ' : 'PF',
            'order_status' => self::STATUS_LABELS[$value] ?? $this->escape((string) $value),
            default => $this->escape((string) $value),
        };
    }

    /** 123456 → "1234,56"; -5 → "-0,05". */
    public static function money(int $cents): string
    {
        $abs = abs($cents);

        return ($cents < 0 ? '-' : '').intdiv($abs, 100).','.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Formula injection protection (OWASP CSV injection). */
    public function escape(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    /**
     * @param  resource  $stream
     * @param  list<string>  $fields
     */
    private function put($stream, array $fields): void
    {
        fputcsv($stream, $fields, ';', '"', '', "\r\n");
    }
}
