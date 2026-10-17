<?php

declare(strict_types=1);

namespace MonologDatadog\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Monolog\Level;
use Monolog\LogRecord;
use MonologDatadog\Formatter\DatadogFormatter;
use PHPUnit\Framework\TestCase;

final class DatadogFormatterTest extends TestCase
{
    public function testAddsStatusAndDate(): void
    {
        $date = new DateTimeImmutable('2026-10-17 16:07:01.443', new DateTimeZone('UTC'));
        $record = new LogRecord($date, 'production', Level::Error, 'Olá Mundo');

        $json = json_decode((new DatadogFormatter())->format($record), true);

        $this->assertSame('Olá Mundo', $json['message']);
        $this->assertSame('error', $json['status']);
        $this->assertSame('2026-10-17T16:07:01.443+00:00', $json['date']);
        $this->assertSame(400, $json['level']);
        $this->assertSame('ERROR', $json['level_name']);
    }

    public function testBatchIsAJsonArrayWithTheStatusOfEachLog(): void
    {
        $records = [
            new LogRecord(new DateTimeImmutable(), 'app', Level::Warning, 'first'),
            new LogRecord(new DateTimeImmutable(), 'app', Level::Info, 'second'),
        ];

        $json = json_decode((new DatadogFormatter())->formatBatch($records), true);

        $this->assertSame(['warning', 'info'], array_column($json, 'status'));
    }
}
