<?php

namespace Pixelated\Streamline\Tests\Feature\Traits;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;

trait HttpMock
{
    const string RELEASES_URI = 'https://api.github.com/repos/*/releases*';

    const string WEB_URI = 'https://github.com/*/releases/download/*/*.zip';

    const array PAGINATION_HEADERS = [
        [
            'Link' => '<https://api.github.com/repos/test/releases?page=2&per_page=30>; rel="next", ' .
                '<https://api.github.com/repos/test/releases?page=2>; rel="last"',
        ],
        [
            'Link' => '<https://api.github.com/repos/test/releases?page=1&per_page=5>; rel="prev", ' .
                '<https://api.github.com/repos/test/releases?page=1>; rel="first"',
        ],
    ];

    private bool $withPaginationHeader = false;

    public function mockHttpReleases(Closure|PromiseInterface|null $response = null): self
    {
        if ($response) {
            Http::fake([self::RELEASES_URI => $response]);

            return $this;
        }

        if ($this->withPaginationHeader) {
            Http::fake([
                // using a sequence to only call the pagination once
                self::RELEASES_URI => Http::sequence()
                    ->push(
                        body: file_get_contents($_ENV['TEST_DIR'] . '/data/releases.json'),
                        headers: self::PAGINATION_HEADERS[0],
                    )
                    ->push(
                        body: file_get_contents($_ENV['TEST_DIR'] . '/data/releases.json'),
                        headers: self::PAGINATION_HEADERS[1],
                    ),
            ]);

            return $this;
        }

        Http::fake([
            self::RELEASES_URI => Http::response(
                body: file_get_contents($_ENV['TEST_DIR'] . '/data/releases.json')
            ),
        ]);

        return $this;
    }

    public function withPaginationHeader(): static
    {
        $this->withPaginationHeader = true;

        return $this;
    }

    public function mockGetWebArchive(): self
    {
        Http::fake([
            self::WEB_URI => Http::response(),
        ]);

        return $this;
    }
}
