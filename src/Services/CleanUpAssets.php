<?php

namespace Pixelated\Streamline\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;
use Pixelated\Streamline\Events\CommandClassCallback;
use RuntimeException;

/**
 * @template TGroupedFiles array<string, array{filename: string, mtime: int}>
 * @description Groups filenames by their root name. Eg foo.adc4953.js will be grouped by "foo"
 * @note Expects the filenames to be in the format [filename].[hash].[ext]
 */
class CleanUpAssets
{
    /**
     * @var Collection<int, string|TGroupedFiles>
     */
    protected Collection $filesToDelete;
    private int $numRevisions;
    private Filesystem $filesystem;

    public function __construct(
//        #[Config('streamline.laravel_asset_dir_name')]
//        private readonly string $assetDir,
//        #[Config('streamline.web_assets_build_num_revisions')]
//        private int             $numRevisions
    )
    {
        //TODO restore these after upgrading to Laravel 11
        $this->numRevisions  = config('streamline.web_assets_build_num_revisions');
        $assetDir            = config('streamline.laravel_asset_dir_name');
        $this->filesystem    = Facades\Storage::disk(config('streamline.laravel_public_disk_name'));
        $buildDir            = config('streamline.laravel_build_dir_name');
        $this->filesToDelete = collect($this->filesystem->files("$buildDir/$assetDir"));
    }

    public function run(?int $numRevisions = null): void
    {
        if ($numRevisions !== null) {
            $this->numRevisions = $numRevisions;
        }

        $assets = $this->filter();
        Event::dispatch(new CommandClassCallback('info', 'DELETING EXPIRED ASSETS: ' . ($assets->isEmpty() ? 'No matching assets found. Likely because none meet the minimum amount of revisions' : $assets->implode(', '))));
        $result = $this->filesystem->delete($assets->toArray());

        if (!$result) {
            throw new RuntimeException('Error: Failed to clean out redundant front-end build assets. Could not execute the cleanup command.');
        }
    }

    /**
     * @return Collection<int, string|TGroupedFiles>
     */
    protected function filter(): Collection
    {
        // Keep only files that end with .js, .css or .map
        return $this->filesToDelete
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
                function (string $file) {
                    $baseName = preg_replace('/\.[^.]+\.[^.]+$/', '', $file);
                    return [$baseName => [
                        'filename' => $file,
                        'mtime'    => $this->filesystem->lastModified($file),
                    ]];
                }
            )
            ->map(fn(Collection $group) => $group
                ->sortBy(fn(array $meta) => $meta['mtime']))
            ->tap(function (Collection $group) {
            })
            // remove Global number of revisions from the collection
            ->map(fn(Collection $meta, string $baseName) => $meta
                ->when(
                    $meta->count() > $this->numRevisions,
                    fn(Collection $meta) => $meta->take($meta->count() - $this->numRevisions),
                    fn() => collect(),
                )
                ->map(fn(array $meta) => $meta['filename'])
            )
            ->flatten();
    }
}
