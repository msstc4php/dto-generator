# Built from the repository root: docker build -f docker/Dockerfile -t ghcr.io/msstc4php/dto-generator .
FROM composer:2 AS vendor
WORKDIR /opt/dto-generator
COPY composer.json LICENSE ./
COPY bin ./bin
COPY src ./src
# composer.json pins the platform to PHP 7.4 for development; the image resolves for the PHP it runs. It runs no plugins
# or scripts of its own, and the package has no lock file.
RUN composer config platform.php 8.4 \
    && composer install --no-dev --no-interaction --no-progress --classmap-authoritative --no-plugins --no-scripts

FROM php:8.4-cli-alpine
COPY --from=vendor /opt/dto-generator /opt/dto-generator
RUN ln -s /opt/dto-generator/bin/dto-generator /usr/local/bin/dto-generator
# "verifyClasses: auto" would load the mounted project's autoloader, that is run its code (spec §9.4).
ENV DTO_GENERATOR_VERIFY_CLASSES=0
USER 1000:1000
WORKDIR /app
ENTRYPOINT ["dto-generator", "generate"]
