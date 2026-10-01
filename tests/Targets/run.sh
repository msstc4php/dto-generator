#!/bin/sh
# Lints and runs every golden emitter profile on the PHP version its directory is named after, then runs
# PHPStan max over it with that version (spec success criterion 2).
set -eu

for profile in tests/Fixtures/Emitter/*/; do
    version=$(basename "$profile" | cut -d- -f1)
    docker run --rm --user "$(id -u):$(id -g)" -v "$PWD:/app" -w /app "php:$version-cli" \
        sh -c 'for file in "$1"*.golden; do php -l "$file" > /dev/null || exit 1; done; php tests/Targets/smoke.php "$1"' sh "$profile"

    config=$(mktemp --suffix=.neon)
    cat > "$config" <<NEON
parameters:
    level: max
    phpVersion: $(( ${version%.*} * 10000 + ${version#*.} * 100 ))
    fileExtensions: [golden]
    paths: ["$PWD/$profile"]
NEON
    tools/vendor/bin/phpstan analyse -c "$config" --no-progress --error-format=raw || { rm -f "$config"; exit 1; }
    rm -f "$config"
done
