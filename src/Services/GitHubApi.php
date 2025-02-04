<?php

namespace Pixelated\Streamline\Services;

use GuzzleHttp\RequestOptions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Traits\Conditionable;
use Pixelated\Streamline\Actions\ProgressMeter;
use Pixelated\Streamline\Enums\GitHubPaginationLinkHeaderTypeEnum as LinkHeader;
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
    private string $defaultProgressMessage = '';
    private int $page = 0;
    private ?int $totalPages = null;
    private readonly string $githubRepo;
    private readonly ?string $authToken;

    public function __construct(
//        #[Config('streamline.github_repo')]
//        private readonly string  $githubRepo,
//        #[Config('streamline.github_auth_token')]
//        private readonly ?string $authToken = null
    )
    {
        //TODO restore this after upgrading to Laravel 11
        $this->githubRepo = config('streamline.github_repo');
        $this->authToken  = config('streamline.github_auth_token');
    }

    public function get(): Response
    {
        $this->checkHasUrl();
        try {
            $this->setProgressMessage();

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

            if ($this->progressMeter?->hasStarted()) {
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
        $allData          = collect();
        $this->page       = 1;
        $perPage          = config('streamline.github_api_pagination_limit');
        $this->totalPages = null;

        do {
            $this->withQueryParams(['page' => $this->page, 'per_page' => $perPage]);

            $response = $this->get();
            $data     = $response->collect();
            $allData  = $allData->merge($data);

            $linkHeader = $response->header('Link');

            // Extract total pages if not already set
            if ($this->totalPages === null) {
                $this->totalPages = $this->extractLinkPageNumber($linkHeader, LinkHeader::LAST);
            }

            // Check if there are more pages
            $nextPage    = $this->extractLinkPageNumber($linkHeader, LinkHeader::NEXT);
            $hasNextPage = $nextPage !== null && $nextPage > $this->page;
            $this->page++;
        } while ($hasNextPage);

        $this->page = 0;

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

    public function progressMessage(string $message): static
    {
        $this->defaultProgressMessage = $message;
        return $this;
    }

    /**
     * @see https://docs.github.com/en/rest/using-the-rest-api/using-pagination-in-the-rest-api
     */
    private function extractLinkPageNumber(?string $linkHeader, LinkHeader $type): ?int
    {
        if (!$linkHeader) {
            return null;
        }

        // This regex extracts the page number from the GitHub API Link header
        // Explanation:
        // <.*                  : Matches the opening angle bracket and any characters
        // (?:&|\?).            : Matches either '&', '?'
        // page=(\d+)           : Matches 'page=' followed by one or more digits (captured)
        // .*>                  : Matches any remaining characters until the closing angle bracket
        // ; rel="$type->value" : Matches the rel attribute with the specified link type
        if (preg_match("/<.*(?:&|\?)page=(\\d+).*>; rel=\"$type->value\"/", $linkHeader, $matches)) {
            return (int)$matches[1];
        }

        return null;
    }

    /**
     * @return void
     */
    public function setProgressMessage(): void
    {
        if (!$this->progressMeter) {
            return;
        }
        $message = $this->defaultProgressMessage;
        if ($this->page > 0) {
            $message .= " - Page $this->page";
        }
        if ($this->totalPages !== null && $this->totalPages > 0) {
            $message .= " of $this->totalPages";
        }
        $this->progressMeter->setMessage($message);
    }
}
