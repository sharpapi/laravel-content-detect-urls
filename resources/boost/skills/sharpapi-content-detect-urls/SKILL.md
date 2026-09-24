---
name: sharpapi-content-detect-urls
description: Detect and extract URLs and their protocols from free text with SharpAPI via `SharpAPI\ContentDetectUrls\ContentDetectUrlsService` (sharpapi/laravel-content-detect-urls). Use when finding, extracting or stripping links in user content, moderating link spam, or touching `detectUrls()`, `fetchResults()` or `config/sharpapi-content-detect-urls.php`.
---

# SharpAPI Content Detect URLs

`sharpapi/laravel-content-detect-urls` wraps one SharpAPI endpoint (`POST /content/detect_urls`) to extract URLs (with their protocol) from free text. The work is async: `detectUrls()` submits a job and returns a status URL, then `fetchResults()` polls until the job finishes.

## When to use this skill

- Pulling links out of messages, posts or documents, including bare domains without a scheme.
- Moderating or stripping links from user content.
- Debugging empty or odd results from `detectUrls()` / `fetchResults()`.

## Install / wiring checklist

- `composer require sharpapi/laravel-content-detect-urls`. It pulls in `sharpapi/php-core`; this skill assumes php-core ≥ 1.4.1.
- `.env`: `SHARP_API_KEY=...` is required. If it is missing, constructing the service throws `InvalidArgumentException`.
- Optional env keys, shared by every SharpAPI wrapper:
  - `SHARP_API_BASE_URL` (default `https://sharpapi.com/api/v1`)
  - `SHARP_API_JOB_STATUS_POLLING_WAIT` (default `180`): the maximum seconds `fetchResults()` keeps polling.
  - `SHARP_API_JOB_STATUS_POLLING_INTERVAL` (default `10`): seconds between polls when the API sends no `Retry-After`.
  - `SHARP_API_JOB_STATUS_USE_POLLING_INTERVAL` (default `false`): when `true`, the fixed interval above replaces the server's `Retry-After`.
- The config file is optional. To publish it: `php artisan vendor:publish --tag=sharpapi-content-detect-urls` (creates `config/sharpapi-content-detect-urls.php`).
- The service provider is auto-discovered. There is **no facade and no container binding**. Type-hint `ContentDetectUrlsService` (the container builds it) or call `new ContentDetectUrlsService()`. The constructor takes no arguments and reads the config.

## API & config reference

```php
use SharpAPI\ContentDetectUrls\ContentDetectUrlsService;

public function detectUrls(string $text): string
```

- `$text` — the content to scan. The only parameter.

**Returns the status URL (a string), not the result.** Pass it to the inherited `fetchResults(string $statusUrl): SharpAPI\Core\DTO\SharpApiJob`, which blocks while it polls.

`SharpApiJob` has the public properties `id`, `type` (`"content_detect_urls"`), `status` (a string: `"success"` or `"failed"`) and `result` (`?stdClass`). It also has `getResultJson()`, `getResultArray()` (shallow), `getResultObject()` and `toArray()`.

Example `result` on success (shape from the SharpAPI response template; the values are illustrative):

```json
[
    { "url": "http://example.com", "protocol": "http" },
    { "url": "https://github.com", "protocol": "https" }
]
```

A list of objects with `url` and `protocol`. Bare domains come back with a scheme added. php-core hands the list over as a `stdClass` with numeric keys, so decode it as shown below.

Exceptions:
- `SharpAPI\Core\Exceptions\ApiException`: polling ran past `SHARP_API_JOB_STATUS_POLLING_WAIT`, or HTTP 429 retries ran out.
- `GuzzleHttp\Exception\ClientException` (4xx, e.g. 401 bad key, 422 validation) and other `GuzzleHttp\Exception\GuzzleException`s for transport or 5xx errors.

## Recipes

### Queued job (the default pattern)

```php
namespace App\Jobs;

use App\Models\Post;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable; // Laravel 10: Dispatchable, InteractsWithQueue, Queueable, SerializesModels
use Illuminate\Support\Facades\Log;
use SharpAPI\Core\Enums\SharpApiJobStatusEnum;
use SharpAPI\Core\Exceptions\ApiException;
use SharpAPI\ContentDetectUrls\ContentDetectUrlsService;

class ExtractPostLinks implements ShouldQueue
{
    use Queueable;

    public int $timeout = 240; // must exceed SHARP_API_JOB_STATUS_POLLING_WAIT (180)

    public int $tries = 1;     // every retry re-submits the text and is billed again

    public function __construct(public Post $post) {}

    public function handle(ContentDetectUrlsService $service): void
    {
        try {
            $statusUrl = $service->detectUrls($this->post->body);
            $job = $service->fetchResults($statusUrl); // blocks and polls; no loop needed
        } catch (ApiException|GuzzleException $e) {
            Log::warning('SharpAPI detectUrls failed: '.$e->getMessage());

            return;
        }

        if ($job->status !== SharpApiJobStatusEnum::SUCCESS->value) {
            Log::warning('SharpAPI detectUrls job did not succeed', $job->toArray());

            return;
        }

        $urls = array_values(json_decode($job->getResultJson(), true) ?? []);
        // $urls[0]['url'], $urls[0]['protocol']
        $this->post->update(['links' => array_column($urls, 'url')]);
    }
}
```

Resolve the service in `handle()`, as above, and never store it on a job property. It holds a Guzzle client, which cannot be serialized onto the queue.

## Gotchas

php-core is a transitive dependency, so these rules are repeated here:

1. **`fetchResults()` already polls.** It sleeps between polls (honouring `Retry-After` and rate-limit headers) until the job succeeds, fails or `SHARP_API_JOB_STATUS_POLLING_WAIT` runs out. Never write your own `while ($status === 'pending')` loop, and never call `detectUrls()` again to "retry": each call is a new billed job.
2. **A failed job does not throw.** Always compare `$job->status` with `SharpApiJobStatusEnum::SUCCESS->value` (`SharpAPI\Core\Enums\SharpApiJobStatusEnum`). On failure `result` can be an empty `stdClass`, so reading `$job->result->field` without `?? null` raises an "Undefined property" `ErrorException` in Laravel.
3. **Never call `fetchResults()` inside an HTTP request.** It can block for up to 180 s. Use a queued job whose `$timeout` exceeds the polling wait, keep `$tries` low, and make the worker/Horizon supervisor `timeout` at least the job timeout, with the queue connection's `retry_after` above it. Artisan commands are fine to run inline.
4. **For arrays, decode the JSON:** `json_decode($job->getResultJson(), true)`. `getResultArray()` only converts the top level, so nested objects stay `stdClass`, and list results arrive as objects with numeric keys. This package returns a list, so always decode it this way.

## Testing

- Mock the service. It must reach your code through the container (constructor/`handle()` injection or `app(ContentDetectUrlsService::class)`); `new ContentDetectUrlsService()` bypasses the mock.

```php
use SharpAPI\Core\DTO\SharpApiJob;
use SharpAPI\ContentDetectUrls\ContentDetectUrlsService;

$this->mock(ContentDetectUrlsService::class, function ($mock) {
    $mock->shouldReceive('detectUrls')->once()->andReturn('https://sharpapi.com/api/v1/job/status/fake-id');
    $mock->shouldReceive('fetchResults')->once()->andReturn(new SharpApiJob(
        id: 'fake-id',
        type: 'content_detect_urls',
        status: 'success',
        result: (object) [['url' => 'https://example.com', 'protocol' => 'https']],
    ));
});
```

- Test the failure path too: return `status: 'failed'` with `result: new \stdClass`.
- `Http::fake()` does **not** intercept these calls, because php-core sends them through its own Guzzle client. Mock the service instead. Without a mock, a test with no `SHARP_API_KEY` throws `InvalidArgumentException` as soon as the service is built.
