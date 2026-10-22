#!/usr/bin/env bash
# Installs PHP's zip extension, which composer.json requires (ext-zip) but the
# devcontainers php feature does not compile in.
#
# This runs from postCreateCommand, which matters twice over:
#   - it runs after the php feature, so phpize/php-config exist; and
#   - it runs before postStartCommand brings the firewall up, so pecl.php.net is
#     reachable. Moving it later would need pecl.php.net on the allow-list.
#
# It runs as `node`, which has no passwordless sudo — that works because the php
# feature leaves its extension and conf.d directories group-writable by `php`.
# libzip-dev comes from the Dockerfile, where apt still has network access.
set -euo pipefail

if php -m | grep -qx zip; then
  echo "ext-zip: already installed, nothing to do."
  exit 0
fi

php_config="$(command -v php-config)"
extension_dir="$("$php_config" --extension-dir)"
ini_dir="$(php -r 'echo PHP_CONFIG_FILE_SCAN_DIR;')"

echo "ext-zip: building into $extension_dir"
printf '\n' | pecl install zip

# The php feature's pecl does not always append to a scanned ini, so the
# extension is enabled explicitly. conf.d is what the feature uses for xdebug.
if [ -n "$ini_dir" ] && [ ! -f "$ini_dir/zip.ini" ]; then
  echo "extension=zip.so" > "$ini_dir/zip.ini"
fi

if php -m | grep -qx zip; then
  echo "ext-zip: installed."
else
  echo "ext-zip: FAILED to load after install. Check $extension_dir and $ini_dir." >&2
  exit 1
fi
