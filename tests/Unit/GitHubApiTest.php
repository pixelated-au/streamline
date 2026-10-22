<?php

use Illuminate\Console\OutputStyle;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Pixelated\Streamline\Facades\GitHubApi;
use Pixelated\Streamline\Testing\Mocks\ProgressMeterFake;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

it('checks that it has a defined url before requesting data', function() {
    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('Error: No URL set. Set it via getWebUrl() or getApiUrl()');
    config(['streamline.github_repo' => 'test']);
    GitHubApi::get();
});

it('does not have a progress callback when none is given to withProgressCallback', function() {
    Http::fake(['https://api.github.com/repos/*' => Http::response('hi')]);
    Config::set('streamline.github_repo', 'test');

    GitHubApi::withApiUrl('test')
        ->withProgressCallback(null)
        ->get();
    Http::assertSentCount(1);
    Http::assertSent(function($request) {
        return $request->url() === 'https://api.github.com/repos/test/test';
    });
});

it('should paginate through multiple pages of data when the API returns more than 5 items', function() {
    $baseUrl = 'https://api.github.com/repos/test/test';
    $perPage = 5;

    Config::set('streamline.github_api_pagination_limit', $perPage);

    Http::fake([
        'github.com/*' => Http::sequence()
            ->push(body: mockApiBody(1), headers: ['Link' => "<$baseUrl?page=2&per_page=5>; rel=\"next\""])
            ->push(body: mockApiBody(2), headers: ['Link' => "<$baseUrl?page=3&per_page=5>; rel=\"next\""])
            ->push(body: mockApiBody(3, 3), headers: ['Link' => "<$baseUrl?page=2&per_page=5>; rel=\"prev\""]),
    ]);

    Config::set('streamline.github_repo', 'test');

    $result = GitHubApi::withProgressCallback(new ProgressMeterFake)
        ->withApiUrl('test')->paginate();

    expect($result)
        ->toHaveCount(13)
        ->and($result->slice(0, 5)->every(fn($item) => $item['id'] === 1))->toBeTrue()
        ->and($result->slice(5, 5)->every(fn($item) => $item['id'] === 2))->toBeTrue()
        ->and($result->slice(10)->every(fn($item) => $item['id'] === 3))->toBeTrue();

    Http::assertSentCount(3);
    Http::assertSentInOrder([
        fn(Request $request) => $request->url() === "$baseUrl?page=1&per_page=$perPage",
        fn(Request $request) => $request->url() === "$baseUrl?page=2&per_page=$perPage",
        fn(Request $request) => $request->url() === "$baseUrl?page=3&per_page=$perPage",
    ]);
});

it('should find the last page when paginating through multiple pages of data', function() {
    $baseUrl = 'https://api.github.com/repos/test/test';
    $perPage = 5;

    Config::set('streamline.github_api_pagination_limit', $perPage);

    Http::fake([
        'github.com/*' => Http::sequence()
            ->push(
                body: mockApiBody(1),
                headers: ['Link' => "<$baseUrl?page=2&per_page=5>; rel=\"next\", <$baseUrl?page=75>; rel=\"last\""]
            )
            ->push(
                body: mockApiBody(2),
                headers: ['Link' => "<$baseUrl?page=3&per_page=5>; rel=\"next\", <$baseUrl?page=85>; rel=\"last\""]
            )
            ->push(body: mockApiBody(3), headers: ['Link' => "<$baseUrl?page=2&per_page=5>; rel=\"prev\""]),
    ]);

    Config::set('streamline.github_repo', 'test');

    $ghApi = GitHubApi::withProgressCallback(new ProgressMeterFake)
        ->withApiUrl('test');
    $ghApi->paginate();

    $getTotalPages = Closure::bind(fn() => $this->totalPages, $ghApi, $ghApi);

    $this->assertSame(75, $getTotalPages());
});

function mockApiBody(int $id, int $count = 5): array
{
    return array_fill(0, $count, ['id' => $id]);
}

it('should add the auth token to the request when provided', function() {
    Config::set('streamline.github_repo', 'test-repo');
    Config::set('streamline.github_auth_token', 'test-token');

    Http::fake();

    GitHubApi::withApiUrl('test')->get();

    Http::assertSent(fn(
        Request $request
    ) => $request->hasHeader('Authorization') && $request->header('Authorization')[0] === 'Bearer test-token');
});

it('should not add the auth token to the request when not provided', function() {
    Config::set('streamline.github_repo', 'test-repo');
    Config::set('streamline.github_auth_token');

    Http::fake();

    GitHubApi::withApiUrl('test')->get();

    Http::assertSent(fn(Request $request) => !$request->hasHeader('Authorization'));
});
it('should throw a RuntimeException with rate limit details when 403 status code is returned', function() {
    $rateLimit = '60';
    $remaining = '0';
    $now       = Carbon::now();
    $reset     = $now->getTimestamp();

    $baseUrl = 'https://api.github.com/repos/test/test';
    // Mock the Carbon time formatting
    $expectedTime = $now->format('H:i:s - d M Y');

    Config::set('streamline.github_repo', 'test');
    Config::set('app.timezone', 'Australia/Melbourne');

    Http::fake([
        'github.com/*' => Http::response('Rate limit exceeded', 403, [
            'X-RateLimit-Limit'     => $rateLimit,
            'X-RateLimit-Remaining' => $remaining,
            'X-RateLimit-Reset'     => $reset,
        ]),
    ]);

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage("GitHub API rate limit exceeded. You have $remaining requests of $rateLimit remaining. You can make more requests at: $expectedTime");

    GitHubApi::withApiUrl('test')->get();

    Http::assertSent(fn(Request $request) => $request->url() === $baseUrl);
});

// ---------------------------------------------------------------------------
// WP6: auth, errors, rate limits, pagination, downloads, logging
// ---------------------------------------------------------------------------

function captureStreamlineLog(): ArrayObject
{
    $captured = new ArrayObject;
    $logger   = Mockery::mock();

    foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'] as $level) {
        $logger->shouldReceive($level)->andReturnUsing(function($message, $context = []) use ($captured) {
            $captured->append($message . ' ' . json_encode($context));
        });
    }
    Log::shouldReceive('channel')->andReturn($logger);

    return $captured;
}

it('never writes the auth token or Authorization header to the log at any verbosity', function(int $verbosity) {
    Config::set('streamline.github_repo', 'test');
    Config::set('streamline.github_auth_token', 'super-secret-token-123');
    $output = new OutputStyle(new ArrayInput([]), new BufferedOutput($verbosity));
    app()->instance(OutputStyle::class, $output);
    $captured = captureStreamlineLog();
    Http::fake(['*' => Http::response('{"message":"Bad credentials"}', 401)]);

    try {
        GitHubApi::withApiUrl('test')->get();
    } catch (RuntimeException) {
    }

    $logged = implode("\n", $captured->getArrayCopy());
    expect($logged)->not->toContain('super-secret-token-123')
        ->not->toContain('Bearer');
})->with([
    'quiet'        => OutputInterface::VERBOSITY_QUIET,
    'normal'       => OutputInterface::VERBOSITY_NORMAL,
    'verbose'      => OutputInterface::VERBOSITY_VERBOSE,
    'very verbose' => OutputInterface::VERBOSITY_VERY_VERBOSE,
    'debug'        => OutputInterface::VERBOSITY_DEBUG,
]);

it('only logs requests at verbose verbosity, with the Authorization header redacted', function() {
    Config::set('streamline.github_repo', 'test');
    Config::set('streamline.github_auth_token', 'super-secret-token-123');
    Http::fake(['*' => Http::response('ok')]);

    app()->instance(OutputStyle::class, new OutputStyle(new ArrayInput([]), new BufferedOutput(OutputInterface::VERBOSITY_NORMAL)));
    $captured = captureStreamlineLog();
    GitHubApi::withApiUrl('normal')->get();
    expect($captured)->toHaveCount(0);

    app()->instance(OutputStyle::class, new OutputStyle(new ArrayInput([]), new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE)));
    GitHubApi::withApiUrl('verbose')->get();
    expect($captured)->toHaveCount(1)
        ->and($captured[0])->toContain('https://api.github.com/repos/test/verbose')
        ->and($captured[0])->toContain('Authorization')
        ->and($captured[0])->toContain('[REDACTED]')
        ->not->toContain('super-secret-token-123');
});

it('throws the GitHub message for a 401 with a JSON body', function() {
    Config::set('streamline.github_repo', 'test');
    Http::fake(['*' => Http::response(['message' => 'Bad credentials'], 401)]);

    expect(fn() => GitHubApi::withApiUrl('test')->get())->toThrow(RuntimeException::class, 'Bad credentials');
});

it('throws a RuntimeException for a 401 without a JSON body', function(string $body) {
    Config::set('streamline.github_repo', 'test');
    Http::fake(['*' => Http::response($body, 401)]);

    expect(fn() => GitHubApi::withApiUrl('test')->get())->toThrow(RuntimeException::class);
})->with(['plain text' => 'Unauthorized', 'json without message' => '{"foo":"bar"}']);

it('throws a rate limit message for a 429', function() {
    Config::set('streamline.github_repo', 'test');
    $reset = Carbon::now()->addMinutes(5)->getTimestamp();
    Http::fake(['*' => Http::response('slow down', 429, [
        'X-RateLimit-Limit' => '60', 'X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => $reset,
    ])]);

    expect(fn() => GitHubApi::withApiUrl('test')->get())
        ->toThrow(RuntimeException::class, 'GitHub API rate limit exceeded');
});

it('reports a 429 with only Retry-After without inventing a reset time', function() {
    Config::set('streamline.github_repo', 'test');
    Http::fake(['*' => Http::response('slow down', 429, ['Retry-After' => '30'])]);

    try {
        GitHubApi::withApiUrl('test')->get();
        $this->fail('Expected exception');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('rate limit')
            ->toContain('30 seconds')
            ->not->toContain('1970');
    }
});

it('does not report a rate limit for a 403 with requests remaining', function() {
    Config::set('streamline.github_repo', 'test');
    Http::fake(['*' => Http::response(['message' => 'Resource protected by organization SAML enforcement.'], 403, [
        'X-RateLimit-Limit' => '5000', 'X-RateLimit-Remaining' => '4990', 'X-RateLimit-Reset' => time() + 100,
    ])]);

    try {
        GitHubApi::withApiUrl('test')->get();
        $this->fail('Expected exception');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('rate limit')
            ->toContain('403')
            ->toContain('SAML');
    }
});

it('does not report a rate limit or a 1970 reset time for a 403 with no rate limit headers', function() {
    Config::set('streamline.github_repo', 'test');
    Http::fake(['*' => Http::response('Forbidden', 403)]);

    try {
        GitHubApi::withApiUrl('test')->get();
        $this->fail('Expected exception');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('rate limit')
            ->not->toContain('1970')
            ->toContain('403');
    }
});

it('reports a rate limited 403 with Retry-After (secondary limit)', function() {
    Config::set('streamline.github_repo', 'test');
    Http::fake(['*' => Http::response(['message' => 'secondary rate limit'], 403, ['Retry-After' => '60'])]);

    expect(fn() => GitHubApi::withApiUrl('test')->get())
        ->toThrow(RuntimeException::class, 'GitHub API rate limit exceeded');
});

it('throws a RuntimeException for 404 and 500 responses', function(int $status) {
    Config::set('streamline.github_repo', 'test');
    Http::fake(['*' => Http::response('nope', $status)]);

    expect(fn() => GitHubApi::withApiUrl('test')->get())
        ->toThrow(RuntimeException::class, 'Error: Failed to connect to GitHub API');
})->with([404, 500]);

it('throws a RuntimeException on a connection failure', function() {
    Config::set('streamline.github_repo', 'test');
    Http::fake(['*' => fn() => throw new ConnectionException('cURL error 6: could not resolve host')]);

    expect(fn() => GitHubApi::withApiUrl('test')->get())
        ->toThrow(RuntimeException::class, 'Error: Failed to connect to GitHub API');
});

it('parses next and last from a Link header regardless of order', function(string $link, int $expectedTotal) {
    $base = 'https://api.github.com/repos/test/test';
    Config::set('streamline.github_repo', 'test');
    Config::set('streamline.github_api_pagination_limit', 5);
    Http::fake(['*' => Http::sequence()
        ->push(mockApiBody(1, 1), 200, ['Link' => str_replace('{base}', $base, $link)])
        ->push(mockApiBody(2, 1), 200, []),
    ]);

    $api    = GitHubApi::withApiUrl('test');
    $result = $api->paginate();

    expect($result)->toHaveCount(2);
    Http::assertSentCount(2);
    $total = Closure::bind(fn() => $this->totalPages, $api, $api);
    expect($total())->toBe($expectedTotal);
})->with([
    'next then last'       => ['<{base}?page=2&per_page=5>; rel="next", <{base}?page=9&per_page=5>; rel="last"', 9],
    'last then next'       => ['<{base}?page=9&per_page=5>; rel="last", <{base}?page=2&per_page=5>; rel="next"', 9],
    'per_page before page' => ['<{base}?per_page=5&page=2>; rel="next", <{base}?per_page=5&page=9>; rel="last"', 9],
    'with prev and first'  => ['<{base}?page=1&per_page=5>; rel="prev", <{base}?page=2&per_page=5>; rel="next", <{base}?page=9&per_page=5>; rel="last", <{base}?page=1&per_page=5>; rel="first"', 9],
]);

it('stops paginating when the next page is not greater than the current page', function() {
    $base = 'https://api.github.com/repos/test/test';
    Config::set('streamline.github_repo', 'test');
    Config::set('streamline.github_api_pagination_limit', 5);
    Http::fake(['*' => Http::response(mockApiBody(1, 1), 200, ['Link' => "<$base?page=1&per_page=5>; rel=\"next\""])]);

    $result = GitHubApi::withApiUrl('test')->paginate();

    expect($result)->toHaveCount(1);
    Http::assertSentCount(1);
});

it('fetches a single page when there is no Link header', function() {
    Config::set('streamline.github_repo', 'test');
    Config::set('streamline.github_api_pagination_limit', 5);
    Http::fake(['*' => Http::response(mockApiBody(1, 2))]);

    $result = GitHubApi::withApiUrl('test')->paginate();

    expect($result)->toHaveCount(2);
    Http::assertSentCount(1);
});

it('does not leak pagination query params into later requests', function() {
    Config::set('streamline.github_repo', 'test');
    Config::set('streamline.github_api_pagination_limit', 5);
    Http::fake(['*' => Http::response(mockApiBody(1, 1))]);

    GitHubApi::withApiUrl('releases')->paginate();
    GitHubApi::withWebUrl('releases/download/v1/release.zip.sha256')->get();

    $urls = [];
    Http::assertSent(function(Request $request) use (&$urls) {
        $urls[] = $request->url();

        return true;
    });
    expect(end($urls))->toBe('https://github.com/test/releases/download/v1/release.zip.sha256');
});

it('does not leak query params set explicitly into the next request', function() {
    Config::set('streamline.github_repo', 'test');
    Http::fake(['*' => Http::response('ok')]);

    GitHubApi::withApiUrl('a')->withQueryParams(['foo' => 'bar'])->get();
    GitHubApi::withApiUrl('b')->get();

    Http::assertSent(fn(Request $r) => $r->url() === 'https://api.github.com/repos/test/a?foo=bar');
    Http::assertSent(fn(Request $r) => $r->url() === 'https://api.github.com/repos/test/b');
});

it('throws and does not leave a successful result when a download returns 404', function() {
    Config::set('streamline.github_repo', 'test');
    $dir = sys_get_temp_dir() . '/ghapi-' . uniqid();
    mkdir($dir);
    $path = "$dir/release.zip";
    Http::fake(['*' => Http::response('Not Found', 404)]);

    try {
        expect(fn() => GitHubApi::withWebUrl('releases/download/v1/release.zip')->withDownloadPath($path)->get())
            ->toThrow(RuntimeException::class, 'Error: Failed to connect to GitHub API');
    } finally {
        @unlink($path);
        @rmdir($dir);
    }
});
