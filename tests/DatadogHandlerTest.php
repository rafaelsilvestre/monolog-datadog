<?php

declare(strict_types=1);

namespace MonologDatadog\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DatadogHandlerTest extends TestCase
{
    private string $errorLog;

    private string|false $previousErrorLog;

    protected function setUp(): void
    {
        $this->errorLog = (string) tempnam(sys_get_temp_dir(), 'monolog-datadog-');
        $this->previousErrorLog = ini_set('error_log', $this->errorLog);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->previousErrorLog);
        @unlink($this->errorLog);
    }

    public function testSendsToTheV2IntakeWithTheKeyInTheHeader(): void
    {
        $handler = $this->handler();

        $this->logger($handler)->error('Olá Mundo');

        $this->assertCount(1, $handler->requests);
        $request = $handler->requests[0];
        $this->assertSame('https://http-intake.logs.datadoghq.com/api/v2/logs', $request['url']);
        $this->assertContains('DD-API-KEY: secret-key', $request['headers']);
        $this->assertContains('Content-Type: application/json', $request['headers']);
        $this->assertStringNotContainsString('secret-key', $request['url']);
    }

    public function testEntryHasMessageStatusDateAndReservedAttributes(): void
    {
        $handler = $this->handler(['source' => 'laravel', 'service' => 'jejua', 'hostname' => 'backoffice.jejua.app']);

        $this->logger($handler)->error('Olá Mundo', ['order' => 42]);

        [$entry] = $handler->lastEntries();
        $this->assertSame('Olá Mundo', $entry['message']);
        $this->assertSame('error', $entry['status']);
        $this->assertSame('laravel', $entry['ddsource']);
        $this->assertSame('jejua', $entry['service']);
        $this->assertSame('backoffice.jejua.app', $entry['hostname']);
        $this->assertSame(['order' => 42], $entry['context']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}[+-]\d{2}:\d{2}$/', $entry['date']);
    }

    #[DataProvider('levels')]
    public function testStatusFollowsTheMonologLevel(Level $level, string $status): void
    {
        $handler = $this->handler();

        $this->logger($handler)->log($level, 'message');

        $this->assertSame($status, $handler->lastEntries()[0]['status']);
    }

    /**
     * @return list<array{Level, string}>
     */
    public static function levels(): array
    {
        return [
            [Level::Debug, 'debug'],
            [Level::Info, 'info'],
            [Level::Notice, 'notice'],
            [Level::Warning, 'warning'],
            [Level::Error, 'error'],
            [Level::Critical, 'critical'],
            [Level::Alert, 'alert'],
            [Level::Emergency, 'emergency'],
        ];
    }

    public function testSiteIsConfigurable(): void
    {
        $handler = $this->handler([], 'datadoghq.eu');

        $this->logger($handler)->info('message');

        $this->assertSame('https://http-intake.logs.datadoghq.eu/api/v2/logs', $handler->requests[0]['url']);
    }

    public function testServiceWithAccentGoesInTheBody(): void
    {
        $handler = $this->handler(['service' => 'Jejuá']);

        $this->logger($handler)->info('message');

        $this->assertSame('Jejuá', $handler->lastEntries()[0]['service']);
        $this->assertStringNotContainsString('Jeju', $handler->requests[0]['url']);
    }

    public function testSendsJsonEvenWhenTheFormatterIsReplacedByALineFormatter(): void
    {
        // Laravel does this when the channel does not set 'formatter' => 'default'.
        $handler = $this->handler();
        $handler->setFormatter(new LineFormatter());

        $this->logger($handler)->error('Olá Mundo');

        [$entry] = $handler->lastEntries();
        $this->assertSame('Olá Mundo', $entry['message']);
        $this->assertSame('error', $entry['status']);
    }

    public function testAddsStatusAndDateWhenAPlainJsonFormatterIsUsed(): void
    {
        $handler = $this->handler();
        $handler->setFormatter(new JsonFormatter());

        $this->logger($handler)->warning('message');

        [$entry] = $handler->lastEntries();
        $this->assertSame('warning', $entry['status']);
        $this->assertArrayHasKey('date', $entry);
    }

    public function testDefaultsForSourceServiceAndHostname(): void
    {
        $handler = $this->handler();

        $this->logger($handler, 'production')->info('message');

        [$entry] = $handler->lastEntries();
        $this->assertSame('php', $entry['ddsource']);
        $this->assertSame('production', $entry['service']);
        $this->assertSame(gethostname(), $entry['hostname']);
        $this->assertArrayNotHasKey('ddtags', $entry);
    }

    public function testTagsAcceptMapsListsAndStrings(): void
    {
        $handler = $this->handler(['tags' => ['env' => 'production', 'team:core']]);
        $this->logger($handler)->info('message');
        $this->assertSame('env:production,team:core', $handler->lastEntries()[0]['ddtags']);

        $handler = $this->handler(['tags' => 'env:staging']);
        $this->logger($handler)->info('message');
        $this->assertSame('env:staging', $handler->lastEntries()[0]['ddtags']);
    }

    public function testHttpErrorIsReportedWithoutThrowingNorLeakingTheKey(): void
    {
        $handler = $this->handler();
        $handler->response = [400, 'Bad Request', null];

        $this->logger($handler)->error('message');

        $log = (string) file_get_contents($this->errorLog);
        $this->assertStringContainsString('[monolog-datadog] Could not send 1 log(s)', $log);
        $this->assertStringContainsString('HTTP 400 Bad Request', $log);
        $this->assertStringNotContainsString('secret-key', $log);
    }

    public function testTransportErrorIsReported(): void
    {
        $handler = $this->handler();
        $handler->response = [0, '', 'cURL error 28: Operation timed out'];

        $this->logger($handler)->error('message');

        $this->assertStringContainsString('cURL error 28', (string) file_get_contents($this->errorLog));
    }

    public function testExceptionInTheTransportIsReportedAndNotThrown(): void
    {
        $handler = $this->handler();
        $handler->response = new RuntimeException('boom');

        $this->logger($handler)->error('message');

        $this->assertStringContainsString('RuntimeException: boom', (string) file_get_contents($this->errorLog));
    }

    public function testSuccessfulDeliveryReportsNothing(): void
    {
        $handler = $this->handler();

        $this->logger($handler)->error('message');

        $this->assertSame('', file_get_contents($this->errorLog));
    }

    public function testRealTransportReturnsTheCurlError(): void
    {
        $handler = $this->handler();

        // Nothing listens on the discard port, so the connection is refused right away.
        [$status, $response, $error] = $handler->realPost('http://127.0.0.1:9/api/v2/logs', [], '[]');

        $this->assertSame(0, $status);
        $this->assertSame('', $response);
        $this->assertStringStartsWith('cURL error 7', (string) $error);
    }

    public function testHandleBatchSendsOneRequestAndSkipsLowerLevels(): void
    {
        $handler = $this->handler([], level: Level::Info);

        $handler->handleBatch([
            $this->record(Level::Debug, 'ignored'),
            $this->record(Level::Info, 'first'),
            $this->record(Level::Error, 'second'),
        ]);

        $this->assertCount(1, $handler->requests);
        $this->assertSame(['first', 'second'], array_column($handler->lastEntries(), 'message'));
        $this->assertSame(['info', 'error'], array_column($handler->lastEntries(), 'status'));
    }

    public function testHandleBatchSplitsIntoRequestsOfAtMost1000Logs(): void
    {
        $handler = $this->handler();

        $handler->handleBatch(array_map(fn (int $i): LogRecord => $this->record(Level::Info, "log $i"), range(1, 1001)));

        $this->assertCount(2, $handler->requests);
        $this->assertCount(1000, json_decode($handler->requests[0]['body'], true));
        $this->assertCount(1, json_decode($handler->requests[1]['body'], true));
    }

    public function testRequiresAnApiKey(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FakeDatadogHandler('');
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function handler(
        array $attributes = [],
        string $site = 'datadoghq.com',
        Level $level = Level::Debug
    ): FakeDatadogHandler {
        return new FakeDatadogHandler('secret-key', $attributes, $level, true, $site);
    }

    private function logger(FakeDatadogHandler $handler, string $channel = 'app'): Logger
    {
        return new Logger($channel, [$handler]);
    }

    private function record(Level $level, string $message): LogRecord
    {
        return new LogRecord(new DateTimeImmutable(), 'app', $level, $message);
    }
}
