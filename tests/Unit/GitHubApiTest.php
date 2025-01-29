<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Pixelated\Streamline\Facades\GitHubApi;
use Pixelated\Streamline\Testing\Mocks\ProgressMeterFake;

it('checks that it has a defined url before requesting data', function () {
    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('Error: No URL set. Set it via getWebUrl() or getApiUrl()');
    config(['streamline.github_repo' => 'test']);
    GitHubApi::get();
});

it('does not have a progress callback when none is given to withProgressCallback', function () {
    Http::fake(['https://api.github.com/repos/*' => Http::response('hi')]);
    Config::set('streamline.github_repo', 'test');

    GitHubApi::withApiUrl('test')
        ->withProgressCallback(null)
        ->get();
    Http::assertSentCount(1);
    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.github.com/repos/test/test';
    });
});

it('should finish the progress meter if it has started', function () {
    $progressMeter             = new ProgressMeterFake();
    $progressMeter->hasStarted = true;

    Http::fake(['https://api.github.com/repos/*' => Http::response('response')]);
    Config::set('streamline.github_repo', 'test');

    GitHubApi::withApiUrl('test')
        ->withProgressCallback($progressMeter)
        ->get();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.github.com/repos/test/test';
    });
});
