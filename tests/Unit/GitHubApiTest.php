<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Pixelated\Streamline\Facades\GitHubApi;
use Pixelated\Streamline\Testing\Mocks\ProgressMeterFake;

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
