# Built from the repository root: docker build -f docker/Dockerfile -t ghcr.io/msstc4php/dto-generator .
FROM composer:2 AS vendor
WORKDIR /opt/dto-generator
COPY composer.json ./
COPY bin ./bin
COPY src ./src
# The image runs no plugins or scripts of its own; the lock file is not part of the package.
RUN composer install --no-dev --no-interaction --no-progress --classmap-authoritative --no-plugins --no-scripts

FROM php:8.4-cli-alpine
COPY --from=vendor /opt/dto-generator /opt/dto-generator
RUN ln -s /opt/dto-generator/bin/dto-generator /usr/local/bin/dto-generator
# "verifyClasses: auto" would load the mounted project's autoloader, that is run its code (spec §9.4).
ENV DTO_GENERATOR_VERIFY_CLASSES=0
USER 1000:1000
WORKDIR /app
ENTRYPOINT ["dto-generator", "generate"]
