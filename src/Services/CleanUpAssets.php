<?php

namespace Pixelated\Streamline\Services;

use Illuminate\Container\Attributes\Config;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;
use RuntimeException;

/**
 */
class CleanUpAssets
{
    /**
     * @template TGroupedFiles array<string, array{filename: string, mtime: int}>
     * @description Groups filenames by their root name. Eg foo.adc4953.js will be grouped by "foo"
     * @note Expects the filenames to be in the format [filename].[hash].[ext]
     * @var Collection<int, string|TGroupedFiles>
     */
    protected Collection $filesToDelete;
    private readonly string $buildDir;
    private int $numRevisions;

    public function __construct(
//        #[Config('streamline.laravel_build_dir_name')]
//        private readonly string $buildDir,
//        #[Config('streamline.laravel_asset_dir_name')]
//        private readonly string $assetDir,
//        #[Config('streamline.web_assets_build_num_revisions')]
//        private int             $numRevisions
    )
    {
        //TODO restore these after upgrading to Laravel 11
        $this->buildDir = config('streamline.laravel_build_dir_name');
        $this->numRevisions = config('streamline.web_assets_build_num_revisions');
        $assetDir = config('streamline.laravel_asset_dir_name');
        $this->filesToDelete = collect(Facades\Storage::files($this->buildDir . '/' . $assetDir));
    }

    public function run(?int $numRevisions = null): void
    {
        if ($numRevisions !== null) {
            $this->numRevisions = $numRevisions;
        }

        $assets = $this->filter();
        Facades\Log::channel('streamline')->info('DELETING EXPIRED ASSETS: ' . $assets->implode(', '));
        $result = Facades\Storage::delete($assets->toArray());

        if (!$result) {
            throw new RuntimeException('Error: Failed to sync front-end build assets. Could not execute the cleanup command.');
        }
    }

    /**
     * @return Collection<int, string|TGroupedFiles>
     */
    protected function filter(): Collection
    {
        return $this->filesToDelete
            // Keep only files that end with .js, .css or .map
            ->filter(fn(string $file) => Str::endsWith(
                haystack: $file,
                needles: Arr::map(
                    array: Facades\Config::commaToArray('streamline.web_assets_filterable_file_types'),
                    callback: static fn(string $ext) => Str::of($ext)
                        ->when(
                            value: fn(Stringable $ext) => $ext->charAt(0) !== '.',
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
                        'filename' => $this->buildDir ? "$this->buildDir/$file" : $file,
                        'mtime'    => Facades\Storage::lastModified($file),
                    ]];
                }
            )
            // sort the collection by mtime
            ->map(fn(Collection $group) => $group
                ->sortBy(fn(array $meta) => $meta['mtime']))
            // remove Global number of revisions from the collection
            ->map(fn(Collection $meta, string $baseName) => $meta
                ->take($meta->count() - $this->numRevisions)
                ->map(fn(array $meta) => $meta['filename'])
            )
            ->flatten();
    }
}
