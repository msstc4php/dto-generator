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
    # ":z" lets SELinux hosts share the directory with the container.
    docker run --rm -u "$(id -u):$(id -g)" -v "$work/$dir:/app:z" "$image" "--config=$config" "$@"
}

mkdir "$work/golden"
cp -R "$root/tests/Fixtures/Projects/golden/api" "$root/tests/Fixtures/Projects/golden/php8.2.yaml" "$work/golden/"
run golden php8.2.yaml
for expected in "$root"/tests/Fixtures/Projects/golden/expected/8.2/*.golden; do
    cmp "$expected" "$work/golden/generated/8.2/$(basename "$expected" .golden)"
done
expected_files=$(cd "$root/tests/Fixtures/Projects/golden/expected/8.2" && ls | sed 's/\.golden$//' | sort)
generated_files=$(cd "$work/golden/generated/8.2" && ls | sort)
[ "$expected_files" = "$generated_files" ]
run golden php8.2.yaml --check
# The JSON report must be the only thing on stdout: no deprecation notice of a dependency on PHP 8.4.
run golden php8.2.yaml --check --format=json \
    | docker run --rm -i --entrypoint php "$image" -r 'exit(json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR)["status"] === "ok" ? 0 : 1);'
[ "$(docker run --rm --entrypoint id "$image" -u)" = 1000 ]
docker run --rm --entrypoint test "$image" -f /opt/dto-generator/LICENSE

mkdir -p "$work/auto/api"
printf '{"require": {"php": ">=8.1"}}\n' > "$work/auto/composer.json"
printf 'version: 1\nsources:\n  - {spec: api/openapi.yaml, namespace: App\\Dto, outputDir: out}\n' > "$work/auto/dto-generator.yaml"
printf 'openapi: 3.1.0\ncomponents:\n  schemas:\n    Pet: {type: object, required: [name], properties: {name: {type: string}}}\n' > "$work/auto/api/openapi.yaml"
run auto dto-generator.yaml
grep -q 'public readonly string $name' "$work/auto/out/Pet.php"

# The image ships the Symfony bridge: a project locking symfony/validator gets constraints.
mkdir -p "$work/bridge/api"
printf '{"require": {"php": ">=8.2"}}\n' > "$work/bridge/composer.json"
printf '{"packages": [{"name": "symfony/validator", "version": "v7.4.0"}], "packages-dev": []}\n' > "$work/bridge/composer.lock"
printf 'version: 1\nsources:\n  - {spec: api/openapi.yaml, namespace: App\\Dto, outputDir: out}\n' > "$work/bridge/dto-generator.yaml"
printf 'openapi: 3.1.0\ncomponents:\n  schemas:\n    Pet: {type: object, required: [name], properties: {name: {type: string, minLength: 1}}}\n' > "$work/bridge/api/openapi.yaml"
run bridge dto-generator.yaml
grep -q 'Assert\\Length(min: 1)' "$work/bridge/out/Pet.php"

# A large spec needs more than the 128M a php image allows by default; the generator raises its own limit.
mkdir -p "$work/large/api"
printf 'version: 1\nsources:\n  - {spec: api/openapi.json, namespace: App\\Dto, outputDir: out}\n' > "$work/large/dto-generator.yaml"
printf '{"require": {"php": ">=8.2"}}\n' > "$work/large/composer.json"
docker run --rm -u "$(id -u):$(id -g)" -v "$work/large:/app:z" --entrypoint php "$image" -r '
    $schemas = [];
    for ($s = 0; $s < 1500; ++$s) {
        $properties = [];
        for ($p = 0; $p < 30; ++$p) {
            $properties["field$p"] = ["type" => "string", "maxLength" => 255];
        }
        $schemas["Model$s"] = ["type" => "object", "required" => ["field0"], "properties" => $properties];
    }
    file_put_contents("api/openapi.json", json_encode(["openapi" => "3.1.0", "components" => ["schemas" => $schemas]]));'
[ "$(docker run --rm --entrypoint php "$image" -r 'echo ini_get("memory_limit");')" = 128M ]
run large dto-generator.yaml > /dev/null
[ "$(ls "$work/large/out" | wc -l)" -eq 1500 ]

echo "docker smoke: ok"
