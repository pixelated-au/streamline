<?php

namespace Pixelated\Streamline\Services;

use GuzzleHttp\RequestOptions;
use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Conditionable;
use Pixelated\Streamline\Actions\ProgressMeter;
use RuntimeException;

class GitHubApi
{
    use Conditionable;

    public const string   GITHUB_API_URL             = 'https://api.github.com/repos';
    public const string   GITHUB_WEB_URL             = 'https://github.com';
    public const string   GITHUB_RELEASES_PATH       = '/releases';
    protected const array REQUEST_USER_AGENT_HEADERS = ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/50.0.2661.102 Safari/537.36'];

    private ?ProgressMeter $progressMeter = null;
    private ?string $downloadPath = null;
    private string $url;
    private array $queryParams = [];

    public function __construct(
        #[Config('streamline.github_repo')]
        private readonly string  $githubRepo,
        #[Config('streamline.github_auth_token')]
        private readonly ?string $authToken = null
    )
    {
    }

    public function get(): Response
    {
        $this->checkHasUrl();
        try {
            $requestClient = Http::withHeaders(self::REQUEST_USER_AGENT_HEADERS)
                ->when(
                    value: (bool)$this->authToken,
                    callback: fn(PendingRequest $request) => $request->withToken($this->authToken)
                )
                ->when(
                    value: (bool)$this->progressMeter,
                    callback: function (PendingRequest $request) {
                        $request->withOptions($request->mergeOptions([
                            RequestOptions::PROGRESS => $this->progressMeter->progressCallback
                        ]));
                    }
                )
                ->when(
                    value: (bool)$this->downloadPath,
                    callback: fn(PendingRequest $request) => $request->sink($this->downloadPath)
                )
                ->get($this->buildUrl());
            if ($this->progressMeter?->hasStarted) {
                $this->progressMeter->finish();
            }

            return $requestClient;
        } catch (ConnectionException $e) {
            throw new RuntimeException(message: 'Error: Failed to connect to GitHub API', previous: $e);
        }
    }

    public function paginate(): Collection
    {
        $this->checkHasUrl();
        $allData = collect();
        $page    = 1;
        $perPage = config('streamline.github_api_pagination_limit');

        do {
            $this->withQueryParams(['page' => $page, 'per_page' => $perPage]);
            $response = $this->get();
            $data     = $response->collect();
            $allData  = $allData->merge($data);

            $page++;

            // Check if there are more pages
            $linkHeader  = $response->header('Link');
            $hasNextPage = Str::contains($linkHeader, 'rel="next"');
        } while ($hasNextPage);

        return $allData;
    }

    private function checkHasUrl(): void
    {
        if (!isset($this->url) || !$this->url) {
            throw new RuntimeException('Error: No URL set. Set it via getWebUrl() or getApiUrl()');
        }
    }

    /**
     * @param null|ProgressMeter(?int $downloadTotal, ?int $downloadedBytes, ?int $uploadTotal, ?int $uploadedBytes):void $callback
     * @return static
     */
    public function withProgressCallback(?ProgressMeter $callback): static
    {
        $this->progressMeter = $callback;
        return $this;
    }

    public function withDownloadPath(?string $path): static
    {
        $this->downloadPath = $path;
        return $this;
    }

    public function withApiUrl(string $path): static
    {
        $this->url = self::GITHUB_API_URL . '/' . $this->githubRepo . '/' . $this->normalisePath($path);
        return $this;
    }

    public function withWebUrl(string $path): static
    {
        $this->url = self::GITHUB_WEB_URL . '/' . $this->githubRepo . '/' . $this->normalisePath($path);
        return $this;
    }

    protected function normalisePath(string $path): string
    {
        return ltrim($path, '/');
    }

    public function withQueryParams(array $params): static
    {
        $this->queryParams = array_merge($this->queryParams, $params);
        return $this;
    }

    protected function buildUrl(): string
    {
        $url = $this->url;
        if (!empty($this->queryParams)) {
            $url .= '?' . http_build_query($this->queryParams);
        }
        return $url;
    }
}
