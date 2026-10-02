#!/bin/sh
# Generates the golden project and a "target.php: auto" project inside the image and compares the output.
set -eu

image="${1:-dto-generator:local}"
root="$(cd "$(dirname "$0")/../.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
run() {
    dir="$1"
    config="$2"
    shift 2
    docker run --rm -u "$(id -u):$(id -g)" -v "$work/$dir:/app" "$image" "--config=$config" "$@"
}

mkdir "$work/golden"
cp -R "$root/tests/Fixtures/Projects/golden/api" "$root/tests/Fixtures/Projects/golden/php8.2.yaml" "$work/golden/"
run golden php8.2.yaml
for expected in "$root"/tests/Fixtures/Projects/golden/expected/8.2/*.golden; do
    cmp "$expected" "$work/golden/generated/8.2/$(basename "$expected" .golden)"
done
run golden php8.2.yaml --check

mkdir -p "$work/auto/api"
printf '{"require": {"php": ">=8.1"}}\n' > "$work/auto/composer.json"
printf 'version: 1\nsources:\n  - {spec: api/openapi.yaml, namespace: App\\Dto, outputDir: out}\n' > "$work/auto/dto-generator.yaml"
printf 'openapi: 3.1.0\ncomponents:\n  schemas:\n    Pet: {type: object, required: [name], properties: {name: {type: string}}}\n' > "$work/auto/api/openapi.yaml"
run auto dto-generator.yaml
grep -q 'public readonly string $name' "$work/auto/out/Pet.php"

echo "docker smoke: ok"
