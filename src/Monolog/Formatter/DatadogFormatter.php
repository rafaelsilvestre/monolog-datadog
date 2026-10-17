<?php

declare(strict_types=1);

namespace MonologDatadog\Formatter;

use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;

/**
 * JSON formatter that adds the attributes Datadog reads from each log entry.
 *
 * - `status`: the PSR-3 level name (error, warning, ...). Without it Datadog falls back to
 *   the numeric Monolog `level` (e.g. 400), which is outside the 0-7 syslog range and is
 *   mapped to "info".
 * - `date`: when the record was created, so Datadog does not use the intake time.
 *
 * @see https://docs.datadoghq.com/logs/log_configuration/pipelines/#preprocessing
 */
class DatadogFormatter extends JsonFormatter
{
    public const DATE_FORMAT = 'Y-m-d\TH:i:s.vP';

    public function formatBatch(array $records): string
    {
        if ($this->batchMode !== self::BATCH_MODE_JSON) {
            return parent::formatBatch($records);
        }

        // Older Monolog 3 releases skip normalizeRecord() in JSON batches, which would drop status and date.
        $normalized = array_map(fn (LogRecord $record): array => $this->normalizeRecord($record), $records);

        return $this->toJson($normalized, true);
    }

    protected function normalizeRecord(LogRecord $record): array
    {
        $normalized = parent::normalizeRecord($record);

        $normalized['status'] = $record->level->toPsrLogLevel();
        $normalized['date'] = $record->datetime->format(self::DATE_FORMAT);

        return $normalized;
    }
}
