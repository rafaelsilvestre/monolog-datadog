# Datadog Monolog integration

Monolog 3 handler that sends logs straight to the Datadog HTTP intake (Logs API v2) with cURL, with no Datadog Agent needed.

## Requirements

- PHP 8.1+ with `ext-curl`
- Monolog 3
- A [Datadog API key](https://app.datadoghq.com/organization-settings/api-keys)

## Installation

The package is not on Packagist, so add the repository to `composer.json`:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/rafaelsilvestre/monolog-datadog" }
],
"require": {
    "rafaelsilvestre/monolog-datadog": "dev-master"
}
```

## Usage

```php
use Monolog\Level;
use Monolog\Logger;
use MonologDatadog\Handler\DatadogHandler;

$handler = new DatadogHandler(
    apiKey: 'YOUR-DATADOG-API-KEY',
    attributes: [
        'source' => 'php',                  // Default: php
        'service' => 'my-service',          // Default: the Monolog channel name
        'hostname' => 'web-1',              // Default: gethostname()
        'tags' => ['env' => 'production'],  // Optional: map, list ("env:production") or "a:b,c:d"
    ],
    level: Level::Info,
    site: 'datadoghq.com',                  // us3.datadoghq.com, us5.datadoghq.com, datadoghq.eu, ap1.datadoghq.com...
);

$logger = new Logger('app', [$handler]);
$logger->error('Payment failed', ['order' => 42]);
```

## What is sent

Each record is posted as JSON to `https://http-intake.logs.<site>/api/v2/logs`, with the API key in the `DD-API-KEY` header (never in the URL):

- Monolog's JSON: `message`, `context`, `extra`, `level`, `level_name`, `channel` and `datetime`.
- `status`: the PSR-3 level name (`error`, `warning`...). Without it Datadog reads the numeric `level` (e.g. 400), which is outside the 0-7 syslog range, and shows every log as info.
- `date`: when the record was created.
- `ddsource`, `service`, `hostname` and `ddtags`, from the attributes.

The body is always JSON. If the formatter was replaced by one that does not output a JSON object (a `LineFormatter`, for instance), the handler falls back to its own `DatadogFormatter`.

## Failures

Logging never breaks the application:

- Requests time out after 5 seconds (2 to connect); change this with the `timeout` and `connectTimeout` arguments.
- Requests are not retried.
- Any failure (network error, timeout or non-2xx response) is reported with `error_log()`: the PHP error log, or stderr on the CLI. The API key is never included.

```
[monolog-datadog] Could not send 1 log(s) to https://http-intake.logs.datadoghq.com/api/v2/logs: HTTP 403 {"errors":["Forbidden"]}
```

## Batching

Each record is sent synchronously, in its own request. To send one request per batch (up to 1000 logs each), wrap the handler in a `BufferHandler`:

```php
$logger = new Logger('app', [new BufferHandler($handler)]);
```

## Laravel

In `config/logging.php`:

```php
'datadog' => [
    'driver' => 'monolog',
    'level' => env('DATADOG_LOG_LEVEL', 'info'),
    'handler' => MonologDatadog\Handler\DatadogHandler::class,
    'handler_with' => [
        'apiKey' => env('DATADOG_API_KEY'),
        'site' => env('DATADOG_SITE', 'datadoghq.com'),
        'attributes' => [
            'source' => 'laravel',
            'service' => env('DATADOG_SERVICE', 'my-app'),
            'hostname' => env('DATADOG_HOSTNAME') ?: gethostname(),
        ],
    ],
    // Keeps the handler's DatadogFormatter; otherwise Laravel applies its LineFormatter.
    'formatter' => 'default',
],
```

Then add `datadog` to `LOG_STACK` (e.g. `LOG_STACK=daily,datadog`).

The handler requires an API key. Where it may be empty (local, CI), switch the channel to `Monolog\Handler\NullHandler`.

After changing `.env` in production, run `php artisan config:cache` and restart the queue workers (Horizon).

## Tests

```
composer install
composer test
```

## License

MIT
