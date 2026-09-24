<?php

declare(strict_types=1);

namespace App\Shared\Logging;

use App\Shared\Support\SensitiveData;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/** Redacts secrets and masks documents in log context/extra (SECURITY.md §17). */
final class RedactSensitiveDataProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: SensitiveData::redact($record->context),
            extra: SensitiveData::redact($record->extra),
        );
    }
}
