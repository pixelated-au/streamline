<?php

namespace Pixelated\Streamline\Tests\Feature\Traits;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;

trait HttpMock
{
    const string RELEASES_URI = 'https://api.github.com/repos/*/releases*';
    const string WEB_URI = 'https://github.com/*/releases/download/*/*.zip';

    public function mockHttpReleases(Closure|PromiseInterface|null $response = null): self
    {
        if ($response) {
            Http::fake([self::RELEASES_URI => $response]);
            return $this;
        }

        Http::fake([
            self::RELEASES_URI => Http::response(file_get_contents($_ENV['TEST_DIR'] . '/data/releases.json')),
        ]);
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
