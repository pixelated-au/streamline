# Streamline: Self-update your Laravel deployment

[//]: # ([![Latest Version on Packagist]&#40;https://img.shields.io/packagist/v/pixelated-au/streamline.svg?style=flat-square&#41;]&#40;https://packagist.org/packages/pixelated-au/streamline&#41;)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/pixelated-au/streamline/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/pixelated-au/streamline/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/pixelated-au/streamline.svg?style=flat-square)](https://packagist.org/packages/pixelated-au/streamline)

This specialised tool is designed to help you self-update your Laravel deployment directly from GitHub.

_This project assumes your package includes all built front-end JavaScript assets._

Using artisan, you can update the package via the CLI or a tool such as Laravel Envoy.

## Installation

During beta, you can install the package via composer:

```bash
composer require pixelated-au/streamline:dev-main
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="streamline-config"
```

You will need to ensure `Illuminate\Database\Eloquent\Collection::class` is serializable in the `cache.php` config file
([Laravel 13 Upgrade guide](https://laravel.com/framework/docs/13.x/upgrade#cache-serializable_classes-configuration)).
Add this to `cache.php` (At the end is a good location)

```php
       
    /*
    |--------------------------------------------------------------------------
    | Serializable Classes
    |--------------------------------------------------------------------------
    |
    | This value determines the classes that can be unserialized from cache
    | storage. By default, no PHP classes will be unserialized from your
    | cache to prevent gadget chain attacks if your APP_KEY is leaked.
    |
    */

    'serializable_classes' => [
        Illuminate\Database\Eloquent\Collection::class,
    ],
```

You will need to set the following environment variables in your `.env` file.
- Replace [github] and [project-name] with your GitHub repository.
- Version installed is the version of the package that is currently installed. It will be set automatically.

```dotenv
STREAMLINE_GITHUB_RELEASE_REPOSITORY=[github]/[project-name]
STREAMLINE_APPLICATION_VERSION_INSTALLED=v0.0.0
```

## Verifying Releases

Streamline overwrites your live application with whatever it downloads, so it is worth being sure
that what it downloaded is what you meant to publish. Two independent checks are available -
checksums, which prove the archive arrived intact, and signatures, which prove the release was
published by you.

**Both ship turned off**, so that installations upgrading to this version of Streamline keep working
without any change to their release process. While either is off, every update prints a banner
saying so.

**See [docs/verifying-releases.md](docs/verifying-releases.md)** for what each layer protects
against, how to publish checksums and signatures with your releases, and how to turn enforcement on.

## Retaining Files Across Updates

An update replaces your installation with the contents of the release archive, so anything the
archive does not ship would otherwise be lost. There are two settings for keeping files, and the
difference between them is what happens on a collision.

### `protected_files` - secrets and state the release must never touch

Paths listed here are copied into the new release and **overwrite** whatever the release ships at
that path. Use it for configuration and state that belongs to this deployment, not to the release.

```dotenv
STREAMLINE_PROTECTED_PATHS=.env,storage/logs/streamline.log
```

These files are given `STREAMLINE_PROTECTED_FILE_PERMISSIONS` (default `0600`) rather than the
general file permission, because they usually hold secrets - your `.env` contains `APP_KEY` and your
database credentials, and on a shared host `0644` means every other user on the machine can read it.

Wildcards are supported (`*`, `?` and `[...]`, matched one path segment at a time against the old
release), e.g. `STREAMLINE_PROTECTED_PATHS=.env,storage/app/*`. Patterns that match nothing are logged
and skipped. Empty entries and `.` are ignored, and paths that step outside the installation (`..`)
are never copied. Symbolic links inside these paths are recreated as links with the same target; they
are not followed.

> **If your web server runs as a different user to the one that runs the update**, `0600` will make
> the `.env` unreadable and your site will break. Set `STREAMLINE_PROTECTED_FILE_PERMISSIONS=0640`
> and make the file group-readable by the web server instead.

### `carry_forward_paths` - user content the application writes

Paths listed here are copied from the old release into the new one, but **never overwrite** a file
the incoming release ships. The new release always wins. Use it for content your application
generates at runtime, which the archive knows nothing about:

```dotenv
STREAMLINE_CARRY_FORWARD_PATHS=public/uploads,public/images,storage/app/public
```

Both directories and individual files are accepted, and paths are relative to your installation
root. Files carried across are given the ordinary `STREAMLINE_FILE_PERMISSIONS` (default `0644`),
since this content is usually served publicly.

The front-end build directory (`public/build`) is always carried forward this way, so old hashed
assets survive an update and browsers holding a cached page can still fetch them. Use
`streamline:clean-assets` to prune those over time.

> **Do not list `public` itself.** Nothing would be overwritten, but every stale asset from every
> previous release would be dragged forward for ever. List the specific directories your
> application writes into.

## Events

This library emits two events for hooking into updates:

- `\Pixelated\Streamline\Events\AvailableVersionsUpdated` Emits when it's pulled down the available versions from GitHub
- `\Pixelated\Streamline\Events\InstalledVersionSet` Emits when the current, installed version has been set
- `\Pixelated\Streamline\Events\NextAvailableVersionUpdated` Emits when the next available version has been set

## Artisan Console

There are several Artisan commands that you can interact with:

- `streamline:check` Check for an available update
- `streamline:clean-assets` Tidy up the front-end build assets directory. Default values are configured in
  config/streamline.php. This can be set using a schedule
- `streamline:list` Retrieves the available updates from GitHub and stores them in the cache
- `streamline:run-update` CLI update of the software. Make sure to back up first!

## Questions

#### What is the point of this project?
    
Unlike other updater projects available (which are excellent by the way), this runs an update by calling an external
PHP script/class. For performance reasons, Laravel loads classes dynamically. If updating directly within Laravel, the
application may fail to load classes that have been removed/deprecated causing the update to fail, leaving a broken
site. This project runs an update by calling an external PHP script/class, ensuring that during the update, there is an
extremely low chance of the update failing.

#### Why is does this project assume all front-end assets will be pre-compiled?

To bypass any potential compilation needs. Building a release of a project using something like CI or doing it locally
ensures that when it's deployed, there are no post-processing requirements. For example, you don't need to have NPM or
Node installed. You don't need to worry about the version of Node that's installed either. It simplifies updates

#### Does this need Composer installed? OR How do I run Composer to install dependencies?

Yes, Composer is required to install dependencies. It happens automatically as part of the update.

#### Who is this for?

It was built so that a project could be deployed on single-instance machines, not unlike upgrading a WordPress
instance. It's designed to simplify deployment - moving the build/dependency mechanics into a centralised place such as
CI. For example, this could be used inside a cPanel hosting environment.

#### Who's it not for?

If you have a project being deployed onto virtual machines inside dedicated environments, other 'updater' projects
may suit your needs more than this project. That said, this project was designed with extensibility in mind. As such, if
you're keen to extend it, you can either do a pull request or extend it locally in your project.

#### Extending/Customising

Streamline is built upon a pipeline architecture, so you can inject your own steps into the update process. For
example, if you wish to make it build your front-end assets instead of using pre-built assets, you can create a new 
pipeline step.

Use the config file `config/streamline.php` to customise the update process to suit your needs.

You can also create your own implementation of `Pixelated\Streamline\Interfaces\UpdateBuilderInterface` which is the
tool to progressively gather information about the update process before the final update is run.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## TODO
- [x] Detect if the GitHub token has expired. If so, output an error to the user  

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
