# Streamline — High-Level Overview

_A walk-through of what this project is and how it works. Generated 2026-08-28._

## What it is

`pixelated-au/streamline` is a **Laravel package** (not an application) that lets a deployed Laravel
site **update itself in place from GitHub Releases** — conceptually similar to how WordPress
self-updates. It is aimed at single-instance/shared hosting (e.g. cPanel) where there is no CI
runner or deployment pipeline on the box.

Key assumptions baked into the design:

- Releases are published as a **pre-built archive** (default `release.zip`) attached to a GitHub
  Release, containing all compiled front-end assets. No Node/NPM is needed on the server.
- **Composer is available** on the server; dependencies are installed as part of the update.
- Versioning follows Git tags (`tag_name` from the GitHub Releases API), compared with PHP's
  `version_compare()`.

Package metadata: PHP >= 8.3, requires `ext-zip`, `symfony/console`, `symfony/process`, and
`spatie/laravel-package-tools`. Tested with Pest 4 / Orchestra Testbench 10 against a `workbench/`
scaffold app.

## The central design idea

Laravel autoloads classes lazily. If you overwrite the application's files *while Laravel is
running*, the framework can later try to load a class file that the update just deleted, and the
update dies half-finished leaving a broken site.

Streamline avoids this by doing the destructive part of the work **outside Laravel**:

1. The Artisan command prepares everything (download, unpack, back up) while Laravel is healthy.
2. It then spawns a **separate PHP process** (`php -r 'require "…"; (new StreamlineUpdater)->run();'`)
   that has no Laravel bootstrap of its own. All configuration is passed to it via **environment
   variables**.
3. That external script swaps the directories, runs Composer, migrates, and optimises.

`src/Global/StreamlineUpdater.php` is deliberately a **global, namespace-less class** (registered
under a PSR-0 autoload rule in `composer.json`) so it can be referenced by a bare class name from a
`php -r` one-liner.

## Architecture at a glance

```
Artisan command  ──►  UpdateBuilder  ──►  Pipeline of Pipes  ──►  external PHP process
(UpdateCommand)       (carries state)      (config-driven)         (StreamlineUpdater
                                                                    → RunCompleteGitHubVersionRelease)
```

### 1. Artisan commands (`src/Commands`)

| Command | Purpose |
| --- | --- |
| `streamline:list` | Pulls all release tags from the GitHub API and caches them. |
| `streamline:check` | Works out the next available version (optionally including pre-releases). |
| `streamline:run-update` | The main entry point — runs the update pipeline. |
| `streamline:clean-assets` | Prunes old hashed front-end build assets, keeping N revisions. |
| `streamline:init-installed-version` | Seeds the cached "currently installed version". |
| `streamline:finish-update` | Hidden; run *after* the swap, in the new codebase, to verify and record the new version. |

Two traits support them: `Traits\GitHubApi` (adds a `--gh-token` option) and
`Traits\OutputSubProcessCalls` (mirrors console output to a dedicated `streamline` log channel and
subscribes to `CommandClassCallback` events so deep-in-the-stack classes can write to the console
without holding a reference to the command).

### 2. The builder (`src/Updater/UpdateBuilder.php`)

A mutable value object implementing `UpdateBuilderInterface`. It is the single passable that flows
through the pipeline, accumulating: base path, composer path, current version, requested version,
next available version, force flag, temp dir, downloaded archive path. Users can swap in their own
implementation.

### 3. The pipeline (`src/Pipeline`, `src/Pipes`)

A small, hand-rolled Laravel-style pipeline (`then()` / `catch()` / `finally()`). Steps are listed
in `config/streamline.php` under `pipeline-update`, so the process is **user-extensible** —
add, remove or reorder pipes without touching the package. A pipe returning `null` (rather than the
builder) short-circuits the pipeline straight to the destination — that is how "already up to date"
exits cleanly.

Default order:

1. `WarnUnverifiedRelease` — print a boxed banner if release verification is not enforced.
2. `CheckLaravelBasePathWritable` — bail early if the install dir is read-only.
3. `CheckComposer` — verify `composer -V` runs; resolve its absolute path.
4. `GetNextAvailableReleaseVersion` — populate the next version from cache/GitHub.
5. `VerifyVersion` — validate the target: exists, newer than current, not a pre-release unless `--force`.
6. `MakeTempDir` — create the work directory (default `../.streamline_tmp`).
7. `DownloadRelease` — stream `releases/download/<tag>/release.zip` with a progress bar.
8. `VerifyReleaseSignature` — check the signed checksum against the configured public key.
9. `VerifyReleaseChecksum` — check the archive against a checksum published with the release.
10. `UnpackRelease` — `ZipArchive` extract into the temp dir.
11. `BackupCurrentInstallation` — zip the whole current install into the backup dir.
12. `RunUpdate` — hand off to the external process.

`finally` runs `Services\FinishUpdate`, which shells out to `php artisan streamline:finish-update`
in the (new) base path — a fresh boot of the updated application.

#### Release verification

> Operator-facing setup instructions live in [verifying-releases.md](verifying-releases.md).

Two independent layers guard what gets unpacked over the live installation, and both default to
off — hence the banner, which prints on every update until they are enforced.

`VerifyReleaseChecksum` answers *"did the archive arrive intact?"*. Its answer is only as
trustworthy as the release it came from: the checksum sits next to the archive, so whoever can
replace one can replace the other.

`VerifyReleaseSignature` answers *"was this published by the key holder?"*. The trust anchor is
`release_public_key`, held by the deployment rather than the release, so a stolen GitHub token is
not enough to pass it. What is signed is the **checksum file**, not the archive — `openssl_verify()`
has no streaming form and would need the whole archive in memory, which shared hosting cannot be
relied on to allow. The key vouches for the digest and the digest vouches for the archive.

When the signature pipe succeeds it verifies the archive itself and stores the digest via
`UpdateBuilder::setVerifiedChecksum()`, which makes `VerifyReleaseChecksum` a no-op. Doing the
archive check inside the signature pipe rather than delegating it means a pipeline that drops the
checksum step still cannot end up with a valid signature and an unchecked archive.

Shared plumbing for both lives in `Pipes\Concerns\VerifiesReleaseAssets`. Note that PHP's
`openssl_verify()` cannot use Ed25519 keys — the pipe detects that case (`-1` rather than `0`) and
reports a configuration problem instead of claiming tampering.

### 4. The external updater (`src/Global`, `src/Updater`)

`StreamlineUpdater` reads its configuration from env vars (`TEMP_DIR`, `LARAVEL_BASE_PATH`,
`PROTECTED_PATHS`, permissions, etc.), collects any missing ones into a single error, locates and
requires the app's `vendor/autoload.php` (honouring a custom `vendor-dir`), then delegates to
`RunCompleteGitHubVersionRelease::run()`, which performs, in order:

1. Copy old front-end build assets into the new release (without overwriting new ones), so
   browser-cached asset URLs keep resolving, then do the same for any `carry_forward_paths`
   (user uploads and similar content the release archive does not ship).
2. Preserve **protected paths** (default `.env` and `storage/logs/streamline.log`) into the new tree.
3. `rename()` the live dir to `<base>_old.N` and the temp dir to the live path — an **atomic-ish swap**. Both renames are checked, and a failure restores the previous release.
4. `composer install --no-dev --no-interaction --prefer-install=dist`.
5. Rewrite `STREAMLINE_APPLICATION_VERSION_INSTALLED` in the new `.env`.
6. `php artisan optimize:clear` and `php artisan migrate --force`.
7. Delete the `_old.N` directory — kept until this point so any earlier failure can be rolled back.
8. Keep or delete the backup archive per `retain_old_releases`.

### 5. Supporting pieces

- **`Services/GitHubApi`** — thin wrapper over Laravel's HTTP client: optional bearer token,
  Link-header pagination, rate-limit (403/429) and auth (401) error translation, `sink()` downloads
  with a Guzzle progress callback.
- **`Actions/ProgressMeter`** — Symfony `ProgressBar` bridged to that download callback.
- **`Services/CleanUpAssets`** — groups hashed asset files (`name.hash.ext`) by base name, sorts by
  mtime, and deletes all but the newest N revisions.
- **`Actions/CreateArchive` + `Factories/CompressedArchiveBuilder` + `Iterators/ArchiveBuilderIterator`**
  — build the pre-update backup zip, skipping symlinks.
- **Events** — `AvailableVersionsUpdated`, `InstalledVersionSet`, `NextAvailableVersionUpdated` are
  public hook points; `CommandClassCallback` is the internal console-output bus.
- **`Macros/ConfigCommaToArrayMacro`** — `Config::commaToArray()`, so list-style settings accept
  either an array or a comma-separated `.env` string.
- **`Install/RequiredFunctionsCheck`** — a Composer script that warns when PHP's `system()` is
  disabled, since the update relies on shelling out.
- **State** lives in the Laravel **cache** (`streamline_available_versions`,
  `streamline_next_available_version`, `streamline_installed_version`) plus the `.env` file — there
  is no database table.

## Configuration

Everything is in `config/streamline.php`, all env-overridable. The notable knobs:
`github_repo`, `github_auth_token`, `installed_version`, `protected_files`, `carry_forward_paths`,
`work_temp_dir`, `backup_dir`, `retain_old_releases`, directory/file/protected-file permissions, the
release verification settings (checksum, public key, signature), the asset-cleanup settings,
and the three extension points —
`external_process_class`, `runner_class`, and the `pipeline-update` array.

## Testing & tooling

- **Pest 4** with five suites: `Unit`, `Feature`, `UpdaterUnit`, `UpdaterFeature`, `Arch`.
- `vfsStream` for filesystem mocking; `workbench/` is a real Testbench skeleton app used as a target.
- `IS_TESTING` is defined in `phpunit.xml.dist`; both `StreamlineUpdater` and
  `RunCompleteGitHubVersionRelease` check it to stub out `exec()` calls and process launches.
  `src/Testing/Mocks` holds fakes (`UpdateRunnerFake`, `PharDataFake`, `ProgressMeterFake`); in the
  `local` environment the service provider swaps `runner_class` for `UpdateRunnerFake`.
- **PHPStan** via Larastan at level 6 (`phpstan.dist.neon`) and **Laravel Pint** (`pint.json`),
  both run by `composer lint`. PHPStan needs more than the default memory limit, so the composer
  scripts pass `--memory-limit=1G`.
- GitHub Actions: run-tests, Pint auto-fix, changelog updates, Dependabot auto-merge.

## Status

The README describes the package as **beta** (`composer require pixelated-au/streamline:dev-main`).
A few `// TODO restore this after upgrading to Laravel 11` comments remain where constructor
`#[Config]` attribute injection is commented out in favour of `config()` calls.
