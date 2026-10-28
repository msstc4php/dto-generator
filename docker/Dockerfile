# Built from the repository root: docker build -f docker/Dockerfile -t ghcr.io/msstc4php/dto-generator .
# The Symfony bridge comes from its repository; an export of a checkout can stand in for it with
# --build-context bridge=<dir> (make docker-build does that when the sibling checkout exists).
FROM scratch AS bridge

FROM composer:2 AS vendor
# The package has no lock file and the build context no .git: the image's version is given, not guessed.
ARG VERSION=1.18.0
ARG BRIDGE_REPOSITORY=https://github.com/msstc4symfony/dto-generator-bridge
ARG BRIDGE_VERSION=^1.0
# The bridge 1.2+ requires its runtime package, which is not on Packagist either.
ARG RUNTIME_REPOSITORY=https://github.com/msstc4symfony/dto-generator-bridge-runtime
ENV COMPOSER_ROOT_VERSION=$VERSION
# Where the bridge comes from: build.sh names the local export with its commit. The bind mount below is not part of
# the step's cache key, this argument is, so a layer never crosses between the two sources.
ARG BRIDGE_SOURCE=repository
WORKDIR /opt/dto-generator
COPY composer.json LICENSE ./
COPY bin ./bin
COPY src ./src
# composer.json pins the platform to PHP 7.4 for development; the image resolves for the PHP it runs. It runs no plugins
# or scripts of its own.
# The repositories are cloned with git (no-api): anonymous GitHub API calls hit the shared runners' rate limit.
RUN --mount=type=bind,from=bridge,target=/opt/bridge \
    composer config platform.php 8.4 \
    && composer config repositories.runtime "{\"type\": \"vcs\", \"url\": \"$RUNTIME_REPOSITORY\", \"no-api\": true}" \
    && if [ -f /opt/bridge/composer.json ]; then \
        composer config repositories.bridge '{"type": "path", "url": "/opt/bridge", "options": {"symlink": false, "versions": {"msstc4symfony/dto-generator-bridge": "1.0.0"}}}'; \
    else \
        composer config repositories.bridge "{\"type\": \"vcs\", \"url\": \"$BRIDGE_REPOSITORY\", \"no-api\": true}"; \
    fi \
    && composer require --no-update "msstc4symfony/dto-generator-bridge:$BRIDGE_VERSION" \
    && composer install --no-dev --no-interaction --no-progress --classmap-authoritative --no-plugins --no-scripts

FROM php:8.4-cli-alpine
COPY --from=vendor /opt/dto-generator /opt/dto-generator
RUN ln -s /opt/dto-generator/bin/dto-generator /usr/local/bin/dto-generator
# "verifyClasses: auto" would load the mounted project's autoloader, that is run its code (spec §9.4).
ENV DTO_GENERATOR_VERIFY_CLASSES=0
USER 1000:1000
WORKDIR /app
ENTRYPOINT ["dto-generator", "generate"]
