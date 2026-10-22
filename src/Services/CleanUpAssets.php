<?php

namespace Pixelated\Streamline\Services;

use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;
use Pixelated\Streamline\Events\CommandClassCallback;
use RuntimeException;

/**
 * @description Groups filenames by their root name. Eg foo.adc4953.js or foo-AbC12_xY.js will be grouped by "foo"
 *
 * @note Expects the filenames to be in the format [filename].[hash].[ext] or Vite's default [filename]-[hash].[ext]
 *       where the Vite hash is 8 characters of [A-Za-z0-9_-]. Source maps ([...].[ext].map) are grouped with the
 *       files of the same type, never with their JS/CSS counterparts.
 */
class CleanUpAssets
{
    /**
     * @var Collection<int, string>
     */
    protected Collection $filesToDelete;

    private Filesystem $filesystem;

    private string $buildDir;

    public function __construct(
        #[Config('streamline.laravel_asset_dir_name')]
        string $assetDir,
        #[Config('streamline.web_assets_build_num_revisions')]
        private int $numRevisions
    ) {
        $this->filesystem    = Facades\Storage::disk(config('streamline.laravel_public_disk_name'));
        $buildDir            = config('streamline.laravel_build_dir_name');
        $this->buildDir      = is_string($buildDir) ? $buildDir : '';
        $this->filesToDelete = collect($this->filesystem->files("$buildDir/$assetDir"));
    }

    public function run(?int $numRevisions = null): void
    {
        if ($numRevisions !== null) {
            $this->numRevisions = $numRevisions;
        }

        $assets = $this->filter();
        CommandClassCallback::dispatch(
            'info',
            'DELETING EXPIRED ASSETS: '
            . (
                $assets->isEmpty()
                ? 'No matching assets found. Likely because none meet the minimum amount of revisions'
                : $assets->implode(', ')
            )
        );
        $result = $this->filesystem->delete($assets->toArray());

        if (!$result) {
            throw new RuntimeException('Error: Failed to clean out redundant front-end build assets. Could not execute the cleanup command.');
        }
    }

    /**
     * @return Collection<int, string>
     */
    protected function filter(): Collection
    {
        $inUse = $this->filesInUse();

        // Keep only files that end with .js, .css or .map
        return $this->filesToDelete
            // The build referenced by the current manifest is live: never delete it, regardless of its mtime
            // (carried-forward assets are copied with a fresh mtime, which makes the live build look the oldest)
            ->reject(fn(string $file) => $inUse->contains($file))
            // Filter out files whose extension does not match any of the allowed ones
            ->filter(fn(string $file) => Str::endsWith(
                haystack: $file,
                needles: Arr::map(
                    array: Facades\Config::commaToArray('streamline.web_assets_filterable_file_types'),
                    callback: static fn(string $ext) => Str::of($ext)
                        // Prefix each extension with a dot if it doesn't already have one
                        ->when(
                            value: fn(Stringable $ext) => !$ext->startsWith('.'),
                            callback: fn(Stringable $ext) => $ext->prepend('.')
                        )
                )
            ))
            // build a collection of files and their mtime grouped by the base name.
            // For example: If a file is named "foo.34sar4d.js" then the base name is "foo"
            ->mapToGroups(
                function(string $file) {
                    return [$this->groupKey($file) => [
                        'filename' => $file,
                        'mtime'    => $this->filesystem->lastModified($file),
                    ]];
                }
            )
            ->map(fn(Collection $group) => $group
                ->sortBy(fn(array $meta) => $meta['mtime']))
            ->tap(function(Collection $group) {})
            // remove Global number of revisions from the collection
            ->map(
                fn(Collection $meta, string $baseName) => $meta
                    ->when(
                        $meta->count() > $this->numRevisions,
                        fn(Collection $meta) => $meta->take($meta->count() - $this->numRevisions),
                        fn() => collect(),
                    )
                    ->map(fn(array $meta) => $meta['filename'])
            )
            ->flatten();
    }

    /**
     * Revision group for a file: its base name plus its type, with the hash removed.
     *
     * - "assets/vendor-react-AbC12_xY.js" => "assets/vendor-react|.js"
     * - "assets/app.123abc.css.map"       => "assets/app|.css.map"
     */
    protected function groupKey(string $file): string
    {
        $suffix = '';

        if (Str::endsWith($file, '.map')) {
            $suffix = '.map';
            $file   = substr($file, 0, -4);
        }

        // Vite: name-hash.ext. The hash is exactly 8 chars and may itself contain '-', so it is anchored to the end.
        if (preg_match('/^(.+)-[A-Za-z0-9_-]{8}\.([^.\/]+)$/', $file, $matches) === 1) {
            return "$matches[1]|.$matches[2]$suffix";
        }

        // Classic: name.hash.ext
        if (preg_match('/^(.+)\.[^.\/]+\.([^.\/]+)$/', $file, $matches) === 1) {
            return "$matches[1]|.$matches[2]$suffix";
        }

        return "$file|$suffix";
    }

    /**
     * Paths (relative to the disk root) of every file referenced by the current Vite manifest, plus their source maps.
     *
     * @return Collection<int, string>
     */
    protected function filesInUse(): Collection
    {
        $inUse = collect();

        foreach (['manifest.json', '.vite/manifest.json'] as $manifest) {
            $path = ltrim("$this->buildDir/$manifest", '/');

            if (!$this->filesystem->exists($path)) {
                continue;
            }

            $entries = json_decode((string) $this->filesystem->get($path), true);

            if (!is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                foreach (['file', 'css', 'assets'] as $key) {
                    foreach (Arr::wrap($entry[$key] ?? []) as $file) {
                        if (is_string($file) && $file !== '') {
                            $inUse->push(ltrim("$this->buildDir/$file", '/'), ltrim("$this->buildDir/$file.map", '/'));
                        }
                    }
                }
            }
        }

        return $inUse->unique()->values();
    }
}
