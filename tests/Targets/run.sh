#!/bin/sh
# Lints and runs every golden emitter profile on the PHP version its directory is named after.
set -eu

for profile in tests/Fixtures/Emitter/*/; do
    version=$(basename "$profile" | cut -d- -f1)
    docker run --rm --user "$(id -u):$(id -g)" -v "$PWD:/app" -w /app "php:$version-cli" \
        sh -c 'for file in "$1"*.golden; do php -l "$file" > /dev/null || exit 1; done; php tests/Targets/smoke.php "$1"' sh "$profile"
done
