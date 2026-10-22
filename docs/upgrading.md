# Upgrading

[&larr; Back to the README](../README.md)

## Upgrading to the release-verification release

This release adds release verification (see [verifying-releases.md](verifying-releases.md)) and fixes
a number of bugs in the updater. Most of it needs no action, but the changes below alter existing
behaviour. Read them before updating a live site.

### Laravel 11 or later is required

Streamline now reads its configuration through constructor `#[Config]` attributes, which Laravel
added in 11. The package now declares `illuminate/contracts: ^11.0|^12.0|^13.0`. Composer will refuse
to install it on Laravel 9 or 10. Stay on the previous Streamline version if you can't upgrade
Laravel.

### Failed commands now fail the update

The updater used to ignore the exit codes of the commands it runs after swapping in the new
release, so a failed command was reported as success. It also ran `composer install` in the
directory of the old release, which is then deleted. Both are fixed: commands now run in the new
release, and a non-zero exit code stops the update.

- **`composer install` fails:** the update is rolled back to the previous release, and the command
  exits with a failure.
- **`migrate` or `optimize:clear` fails:** there is no rollback, because the database may already be
  partly migrated. The previous release is **kept** (not deleted) next to your installation as
  `<install-dir>_old.N`, a message explains what happened, and the command exits with a failure.
- **The updater process itself crashes:** the Artisan command now exits with a failure and shows the
  updater's output. It used to exit 0.

**What to check:** deploy scripts and cron jobs that run `streamline:run-update` and only look at
the exit code. Updates that used to "succeed" while composer or migrations silently failed will
now show up as failures. That is intended, but you may see failures the first time you run it.

### The work directory is emptied before every update

`STREAMLINE_WORK_TEMP_DIR` (default: `.streamline_tmp` next to your installation) is where the
release is downloaded and unpacked. It used to be reused as-is, so leftovers came back:

- A failed release from an earlier rollback reappeared.
- Files that upstream had deleted reappeared.
- `release.zip` itself was copied into the live site.

Streamline now **deletes everything inside that directory** at the start of each update. It also
deletes `release.zip` after unpacking.

As a safety check, the update refuses to run if the directory is any of the following:

- your installation directory or one of its parent directories
- `/`
- a symlink to any of the above

The same check applies to `STREAMLINE_BACKUP_DIR`, which must also sit outside the installation.

**What to check:** if you set `STREAMLINE_WORK_TEMP_DIR` yourself, make sure it points at a
directory used **only** by Streamline. Anything else in it will be deleted on the next update.

### Any version with a `-suffix` is now a pre-release

Streamline used to recognise only a few pre-release markers, so `v2.0.0-rc1`, `v2.0.0-alpha.1`,
`v2.0.0-beta.2` and `v2.0.0-dev` were treated as stable and could be installed automatically. A
version now counts as a pre-release if:

- it has anything after a `-` (the SemVer pre-release rule), e.g. `-rc1`, `-RC.1`, `-alpha.1`,
  `-preview`. Build metadata after `+` is ignored, so `v2.0.0+build-5` is still stable.
- it ends in one of the older markers (`a`, `b`, `alpha`, `beta`).
- the GitHub release has **"Set as a pre-release"** ticked, whatever its tag says.

Pre-releases are only installed automatically when you pass `--pre-releases`, or when you name a
version with `--install-version`.

**What to check:**

- Tags such as `v1.4.2-hotfix` or `v1.4.2-patch1` now count as pre-releases. Without
  `--pre-releases` they are skipped, and the newest stable version is installed instead. Rename the
  tag (e.g. `v1.4.3`) if it is meant to be a stable release.
- If **every** available version is a pre-release, `streamline:check` and `streamline:run-update`
  now fail with a message telling you to use `--pre-releases`. They no longer install the
  pre-release.

### `streamline:clean-assets` changes

- `--revisions` must be a whole number of 1 or more. `0`, negative numbers and decimals used to
  delete every asset, including the live build. They are now rejected.
- Files listed in the current Vite manifest are never deleted.
- Vite's default `name-hash.ext` file names are now grouped and pruned. Previously they were never
  cleaned up, so the first run after upgrading may delete more old files than usual.
- Revisions are now counted separately for each file type. For example, `app.js` and `app.css` each
  keep their own N revisions. Previously the two types shared a single count.

### Smaller changes

- **Path entries:** empty entries, `.`, absolute paths and `..` in `STREAMLINE_PROTECTED_PATHS` and
  `STREAMLINE_CARRY_FORWARD_PATHS` are rejected. A trailing comma (`.env,`) is simply ignored. You
  can also give either setting as a JSON array string.
- **Wildcards:** wildcards (`storage/*`) in protected and carried-forward paths now work. They were
  previously ignored.
- **`.env` version key:** if `STREAMLINE_APPLICATION_VERSION_INSTALLED` is missing from `.env`, it
  is now added. It used to be silently skipped.
- **GitHub token:** the token is no longer written to `storage/logs/streamline.log`. Request
  logging now only happens at `-v` verbosity, with credentials redacted. **If you used a token
  with an earlier version, rotate it and clear that log.**
