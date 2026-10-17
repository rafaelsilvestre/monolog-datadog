<?php

declare(strict_types=1);

namespace MonologDatadog\Handler;

use InvalidArgumentException;
use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Handler\MissingExtensionException;
use Monolog\Level;
use Monolog\LogRecord;
use MonologDatadog\Formatter\DatadogFormatter;
use stdClass;
use Throwable;

/**
 * Sends logs to the Datadog HTTP intake (Logs API v2).
 *
 * Delivery never throws: a failed request (network error, timeout or non-2xx response)
 * is reported with error_log(), so it is visible without breaking the application.
 *
 * @see https://docs.datadoghq.com/api/latest/logs/#send-logs
 * @author Gusp <contact@gusp.io>
 */
class DatadogHandler extends AbstractProcessingHandler
{
    public const DEFAULT_SITE = 'datadoghq.com';

    /** Datadog accepts at most 1000 entries per request. */
    public const MAX_BATCH_SIZE = 1000;

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;

    private string $apiKey;

    /** @var array<string, mixed> */
    private array $attributes;

    private string $endpoint;

    private int $timeout;

    private int $connectTimeout;

    private ?DatadogFormatter $fallbackFormatter = null;

    /**
     * @param string               $apiKey         Datadog API key
     * @param array<string, mixed> $attributes     source, service, hostname and tags (list, map or "a:b,c:d")
     * @param int|string|Level     $level          The minimum logging level at which this handler will be triggered
     * @param bool                 $bubble         Whether the messages that are handled can bubble up the stack or not
     * @param string               $site           Datadog site (datadoghq.com, us5.datadoghq.com, datadoghq.eu...)
     * @param int                  $timeout        Maximum time, in seconds, of each request
     * @param int                  $connectTimeout Maximum time, in seconds, to connect to the intake
     */
    public function __construct(
        string $apiKey,
        array $attributes = [],
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
        string $site = self::DEFAULT_SITE,
        int $timeout = 5,
        int $connectTimeout = 2
    ) {
        if (!extension_loaded('curl')) {
            throw new MissingExtensionException('The curl extension is needed to use the DatadogHandler');
        }

        if ($apiKey === '') {
            throw new InvalidArgumentException('The Datadog API key is required');
        }

        parent::__construct($level, $bubble);

        $this->apiKey = $apiKey;
        $this->attributes = $attributes;
        $this->endpoint = 'https://http-intake.logs.' . ($site !== '' ? $site : self::DEFAULT_SITE) . '/api/v2/logs';
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
    }

    /**
     * Sends the records in as few requests as possible (used by BufferHandler and similar wrappers).
     */
    public function handleBatch(array $records): void
    {
        $batch = [];

        foreach ($records as $record) {
            if (!$this->isHandling($record)) {
                continue;
            }

            if (\count($this->processors) > 0) {
                $record = $this->processRecord($record);
            }

            $record->formatted = $this->getFormatter()->format($record);
            $batch[] = $record;
        }

        foreach (array_chunk($batch, self::MAX_BATCH_SIZE) as $chunk) {
            $this->send($chunk);
        }
    }

    protected function write(LogRecord $record): void
    {
        $this->send([$record]);
    }

    /**
     * @param list<LogRecord> $records
     */
    protected function send(array $records): void
    {
        try {
            $entries = array_map(fn (LogRecord $record): stdClass => $this->toEntry($record), $records);
            $body = json_encode($entries, self::JSON_FLAGS);

            if ($body === false) {
                $this->reportFailure(\count($records), 'could not encode the logs as JSON: ' . json_last_error_msg());

                return;
            }

            [$status, $response, $error] = $this->post($this->endpoint, [
                'Content-Type: application/json',
                'DD-API-KEY: ' . $this->apiKey,
            ], $body);

            if ($error !== null) {
                $this->reportFailure(\count($records), $error);
            } elseif ($status < 200 || $status >= 300) {
                $this->reportFailure(\count($records), sprintf('HTTP %d %s', $status, trim(substr($response, 0, 300))));
            }
        } catch (Throwable $e) {
            $this->reportFailure(\count($records), \get_class($e) . ': ' . $e->getMessage());
        }
    }

    /**
     * The log entry as Datadog expects it: the formatted record plus the reserved attributes.
     */
    protected function toEntry(LogRecord $record): stdClass
    {
        $entry = \is_string($record->formatted) ? json_decode($record->formatted) : null;

        if (!$entry instanceof stdClass) {
            // The formatter does not produce a JSON object (e.g. Laravel applies a LineFormatter unless
            // the channel sets 'formatter' => 'default'), and Datadog needs JSON to read the status.
            $this->fallbackFormatter ??= new DatadogFormatter();
            $entry = json_decode($this->fallbackFormatter->format($record));
        }

        if (!$entry instanceof stdClass) {
            $entry = new stdClass();
            $entry->message = $record->message;
        }

        $entry->status ??= $record->level->toPsrLogLevel();
        $entry->date ??= $record->datetime->format(DatadogFormatter::DATE_FORMAT);
        $entry->ddsource = $this->getSource();
        $entry->service = $this->getService($record);
        $entry->hostname = $this->getHostname();

        $tags = $this->getTags();
        if ($tags !== '') {
            $entry->ddtags = $tags;
        }

        return $entry;
    }

    /**
     * Sends the request. Kept apart so the transport can be replaced (e.g. in tests).
     *
     * @param list<string> $headers
     *
     * @return array{0: int, 1: string, 2: string|null} HTTP status, response body and transport error
     */
    protected function post(string $url, array $headers, string $body): array
    {
        $ch = curl_init($url);

        if ($ch === false) {
            return [0, '', 'could not initialize cURL'];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);

        if (!\is_string($response)) {
            return [0, '', sprintf('cURL error %d: %s', curl_errno($ch), curl_error($ch))];
        }

        return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $response, null];
    }

    /**
     * Reports a delivery failure without throwing, so logging never breaks the application.
     */
    protected function reportFailure(int $count, string $reason): void
    {
        error_log(sprintf('[monolog-datadog] Could not send %d log(s) to %s: %s', $count, $this->endpoint, $reason));
    }

    protected function getSource(): string
    {
        return !empty($this->attributes['source']) ? (string) $this->attributes['source'] : 'php';
    }

    protected function getService(LogRecord $record): string
    {
        return !empty($this->attributes['service']) ? (string) $this->attributes['service'] : $record->channel;
    }

    protected function getHostname(): string
    {
        if (!empty($this->attributes['hostname'])) {
            return (string) $this->attributes['hostname'];
        }

        return gethostname() ?: 'localhost';
    }

    protected function getTags(): string
    {
        $tags = $this->attributes['tags'] ?? [];

        if (\is_string($tags)) {
            return $tags;
        }

        $list = [];
        foreach ((array) $tags as $key => $value) {
            $list[] = \is_int($key) ? (string) $value : $key . ':' . $value;
        }

        return implode(',', $list);
    }

    protected function getDefaultFormatter(): FormatterInterface
    {
        return new DatadogFormatter();
    }
}
