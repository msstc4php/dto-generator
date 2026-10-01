<?php

declare(strict_types=1);

use App\Dto\Sample;
use App\Dto\Tag;

// Runs on the profile's own PHP version, so the generated code is checked by the runtime it targets.
// Notices and deprecations (e.g. an optional parameter before a required one) fail the run.
set_error_handler(static function (int $severity, string $message): bool {
    throw new ErrorException($message, 0, $severity);
});

$profile = $argv[1] ?? '';
require $profile . 'Tag.php.golden';
require $profile . 'Sample.php.golden';
require $profile . 'Copy.php.golden';

/**
 * @return mixed
 */
function read(object $dto, string $property)
{
    $getter = 'get' . ucfirst($property);

    return method_exists($dto, $getter) ? $dto->{$getter}() : $dto->{$property};
}

function check(bool $condition, string $what): void
{
    if (!$condition) {
        fwrite(STDERR, "failed: {$what}\n");
        exit(1);
    }
}

$tag = new Tag('red');
$sample = new Sample(7, [$tag], 'A');

check(read($sample, 'id') === 7, 'required argument');
check(read($sample, 'tags') === [$tag], 'list argument');
check(read($sample, 'code') === 'A', 'union argument');
check(read($sample, 'name') === 'anonymous', 'string default');
check(read($sample, 'score') === 1.5, 'float default');
check(read($sample, 'flags') === [true, false], 'list default');
check(read($sample, 'createdAt') === null, 'null default');

if (method_exists($sample, 'withId')) {
    $copy = $sample->withId(8);
    check(read($copy, 'id') === 8 && read($sample, 'id') === 7 && read($copy, 'code') === 'A', 'wither');
}

$properties = array_map(static fn (ReflectionProperty $property): string => $property->getName(), (new ReflectionClass($sample))->getProperties());
check(count($properties) === 9, 'every Sample property is exercised');

foreach ($properties as $property) {
    $suffix = ucfirst($property);
    if (method_exists($sample, 'with' . $suffix)) {
        $copy = $sample->{'with' . $suffix}(read($sample, $property));
        check($copy !== $sample && read($copy, $property) === read($sample, $property), 'with' . $suffix);
    }

    if (method_exists($sample, 'set' . $suffix)) {
        check($sample === $sample->{'set' . $suffix}(read($sample, $property)), 'set' . $suffix);
    }
}

if (method_exists($sample, 'setId')) {
    check($sample->setId(9) === $sample && read($sample, 'id') === 9, 'setter');
}

$holder = new Copy(5);
if (method_exists($holder, 'withClone')) {
    check(read($holder->withClone(6), 'clone') === 6 && read($holder, 'clone') === 5, 'wither of a property named clone');
}

if (strpos(basename($profile), '-immutable') !== false && !method_exists($sample, 'getId')) {
    $rejected = false;

    try {
        $sample->id = 1;
    } catch (Error $error) {
        $rejected = true;
    }

    check($rejected, 'readonly property');
}

echo 'ok ' . basename($profile) . "\n";
