#!/bin/sh
# Lints and runs every golden emitter profile on the PHP version its directory is named after, then runs
# PHPStan max over it with that version (spec success criterion 2).
set -eu

workdir=$(mktemp -d "${TMPDIR:-/tmp}/dto-generator-targets.XXXXXX")
trap 'rm -rf "$workdir"' EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

# $1: directory of *.golden files, $2: PHP version, $3: extra command to run in the container after linting.
check() {
    docker run --rm --user "$(id -u):$(id -g)" -v "$PWD:/app" -w /app "php:$2-cli" \
        sh -c 'for file in "$1"*.golden; do php -l "$file" > /dev/null || exit 1; done; '"$3" sh "$1"

    config="$workdir/phpstan.neon"
    cat > "$config" <<NEON
parameters:
    level: max
    phpVersion: $(( ${2%.*} * 10000 + ${2#*.} * 100 ))
    fileExtensions: [golden]
    paths: ["$PWD/$1"]
    scanFiles: ["$PWD/tests/Targets/attributes.php"]
NEON
    tools/vendor/bin/phpstan analyse -c "$config" --no-progress --error-format=raw
}

for profile in tests/Fixtures/Emitter/*/; do
    check "$profile" "$(basename "$profile" | cut -d- -f1)" 'php tests/Targets/smoke.php "$1"'
done

for expected in tests/Fixtures/Projects/golden/expected/*/; do
    check "$expected" "$(basename "$expected" | cut -d- -f1)" 'echo "ok $1"'
done
