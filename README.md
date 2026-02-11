# Streamline: Self-update your Laravel deployment

[//]: # ([![Latest Version on Packagist]&#40;https://img.shields.io/packagist/v/pixelated-au/streamline.svg?style=flat-square&#41;]&#40;https://packagist.org/packages/pixelated-au/streamline&#41;)

[//]: # ([![GitHub Tests Action Status]&#40;https://img.shields.io/github/actions/workflow/status/pixelated-au/streamline/run-tests.yml?branch=main&label=tests&style=flat-square&#41;]&#40;https://github.com/pixelated-au/streamline/actions?query=workflow%3Arun-tests+branch%3Amain&#41;)

[//]: # ([![GitHub Code Style Action Status]&#40;https://img.shields.io/github/actions/workflow/status/pixelated-au/streamline/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square&#41;]&#40;https://github.com/pixelated-au/streamline/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain&#41;)

[//]: # ([![Total Downloads]&#40;https://img.shields.io/packagist/dt/pixelated-au/streamline.svg?style=flat-square&#41;]&#40;https://packagist.org/packages/pixelated-au/streamline&#41;)

This specialised tool is designed to help you self-update your Laravel deployment directly from GitHub.

_Unlike other tools, this project assumes your package includes all built assets and composer vendor packages._

Using artisan, you can update the package via the CLI or a tool such as Laravel Envoy.

## Installation

You can install the package via composer:

```bash
composer require pixelated-au/streamline
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="streamline-config"
```

## Events

This library emits two events for hooking into updates:

- `\Pixelated\Streamline\Events\AvailableVersionsUpdated` Emits when it's pulled down the available versions from GitHub
- `\Pixelated\Streamline\Events\InstalledVersionSet` Emits when the current, installed version has been set
- `\Pixelated\Streamline\Events\NextAvailableVersionUpdated` Emits when the next available version has been set

# Artisan Console

There are several Artisan commands that you can interact with:

- `streamline:check` Check for an available update
- `streamline:clean-assets` Tidy up the front-end build assets directory. Default values are configured in
  config/streamline.php. This can be set using a schedule
- `streamline:list` Retrieves the available updates from GitHub and stores them in the cache
- `streamline:run-update` CLI update of the software. Make sure to back up first!

## Common Questions

Why is does this project assume all front-end assets will be pre-compiled?
: To bypass any potential compilation needs. Building a release of a project using something like CI or doing it locally
ensures that when it's deployed, there are no post-processing requirements. For example, you don't need to have NPM or
Node installed. You don't need to worry about the version of Node that's installed either. It simplifies updates

Does this need Composer installed? OR How do I run Composer to install dependencies?
: Whilst technically you can utilise Composer after an update, this package doesn't support it. The reasoning being the
same as the previous answer on front-end-assets. When we don't depend on Composer as part of the installation process,
we won't get build errors during an upgrade.

Who is this for?
: First up, it's not for all projects! It was built so that a project could be deployed on single-instance machines, not
unlike upgrading a WordPress instance. It's designed to simplify deployment - moving the build/dependency mechanics into
a centralised place such as CI. For example, this could be used inside a cPanel hosting environment.

Who's it not for?
: If you have a project being deployed onto virtual machines inside of dedicated environments, other 'updater' projects
may suit your needs more than this project. That said, this project was designed with extensibility in mind. As such, if
you're keen to extend it, you can eiter do a pull request or extend it locally in your project.

What other whizzbang features does this have?
: Unlike other updater projects available (which are excellent by the way), this runs an update by calling an external
PHP script/class. This ensures that during the update, the only classes loaded into memory are directly attached to this
project.

: As part of its optimisation techniques, Laravel (and potentially child libraries) doesn't load all classes into
memory. This is great except when after an update, Laravel may try to load said classes. Again, not a problem...
except if those classes have been removed/deprecated! If that happens, the deployment will halt/fail and will require
manual work. Not good if you want a self-maintainable product!

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Pixelated](https://github.com/pixelated-au)
- [All Contributors](../../contributors)

## TODO
- [ ] Detect if the GitHub token has expired. If so, output an error to the user  

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
