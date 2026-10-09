#!/bin/sh
# Builds the image; with a checkout of the Symfony bridge (BRIDGE, by default the sibling one), its committed HEAD
# stands in for the published package, exported as a release would be.
set -eu

image="${1:-dto-generator:local}"
root="$(cd "$(dirname "$0")/.." && pwd)"
# The image reports the latest release; the bridge's constraint on the core is checked against it.
version="$(git -C "$root" describe --tags --abbrev=0 --match 'v[0-9]*' --exclude='*-*' 2>/dev/null | sed 's/^v//')"
set -- ${version:+--build-arg "VERSION=$version"}
bridge="${BRIDGE:-$root/../../msstc4symfony/dto-generator-bridge-symfony}"
if [ ! -d "$bridge/.git" ]; then
    exec docker build -f "$root/docker/Dockerfile" "$@" -t "$image" "$root"
fi

export_dir="$(mktemp -d)"
trap 'rm -rf "$export_dir"' EXIT
git -C "$bridge" archive --format=tar HEAD | tar -xf - -C "$export_dir"
docker build -f "$root/docker/Dockerfile" "$@" --build-context "bridge=$export_dir" \
    --build-arg "BRIDGE_SOURCE=local $(git -C "$bridge" rev-parse HEAD)" -t "$image" "$root"
