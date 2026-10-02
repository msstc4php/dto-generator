<?php

declare(strict_types=1);

use App\Attr\Choice;
use App\Attr\Constraints\Positive;
use App\Attr\Constraints\Valid;
use App\Attr\Meta;
use App\Attr\Table;
use App\Dto\Animal;
use App\Dto\Circle;
use App\Dto\Dog;
use App\Dto\Sample;
use App\Dto\Shape;
use App\Dto\Tag;

// Runs on the profile's own PHP version, so the generated code is checked by the runtime it targets.
// Notices and deprecations (e.g. an optional parameter before a required one) fail the run.
set_error_handler(static function (int $severity, string $message): bool {
    throw new ErrorException($message, 0, $severity);
});

$profile = $argv[1] ?? '';
// The attribute classes; below PHP 8.0 `#[...]` is a comment, so the file loads on every target.
require __DIR__ . '/attributes.php';
require $profile . 'Currency.php.golden';
require $profile . 'Tag.php.golden';
require $profile . 'Sample.php.golden';
require $profile . 'Copy.php.golden';
require $profile . 'Animal.php.golden';
require $profile . 'Dog.php.golden';
require $profile . 'Shape.php.golden';
require $profile . 'Circle.php.golden';

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
// A native enum case from PHP 8.1, the backing value of a class constant before.
check(read($sample, 'currency') === constant('App\\Dto\\Currency::EUR'), 'enum default');

if (method_exists($sample, 'withId')) {
    $copy = $sample->withId(8);
    check(read($copy, 'id') === 8 && read($sample, 'id') === 7 && read($copy, 'code') === 'A', 'wither');
}

$properties = array_map(
    static fn (ReflectionProperty $property): string => $property->getName(),
    (new ReflectionClass($sample))->getProperties(),
);
check(count($properties) === 10, 'every Sample property is exercised');

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

// Required parameters of the whole chain come first, the parent's before the class's own.
$dog = new Dog('d1', 'beagle');
check($dog instanceof Animal && read($dog, 'id') === 'd1' && read($dog, 'breed') === 'beagle', 'inherited and own arguments');
check(read($dog, 'nickname') === null && read($dog, 'goodBoy') === true, 'inherited and own defaults');
check(read(new Animal('a1', 'Rex'), 'nickname') === 'Rex', 'open base on its own');
if (method_exists($dog, 'withId')) {
    $renamed = $dog->withId('d2');
    check($renamed instanceof Dog && read($renamed, 'id') === 'd2' && read($renamed, 'breed') === 'beagle' && read($dog, 'id') === 'd1', 'wither of an inherited property');
    check(read($dog->withBreed('pug'), 'breed') === 'pug' && read($dog->withBreed('pug'), 'id') === 'd1', 'wither of an own property');
}

if (method_exists($dog, 'setNickname')) {
    check($dog->setNickname('Max') === $dog && read($dog, 'nickname') === 'Max', 'inherited setter');
}

$circle = new Circle('circle', 2.0);
check($circle instanceof Shape && read($circle, 'kind') === 'circle' && read($circle, 'radius') === 2.0, 'discriminated variant');
check((new ReflectionClass(Shape::class))->isAbstract() && !(new ReflectionClass(Animal::class))->isFinal(), 'base classes stay open');

if (strpos(basename($profile), '-immutable') !== false && !method_exists($sample, 'getId')) {
    $rejected = false;

    try {
        $sample->id = 1;
    } catch (Error $error) {
        $rejected = true;
    }

    check($rejected, 'readonly property');
}

if (PHP_VERSION_ID >= 80000) {
    // newInstance() checks every attribute's arguments against its constructor.
    $instances = array_map(
        static fn (ReflectionAttribute $attribute): object => $attribute->newInstance(),
        array_merge(
            (new ReflectionClass(Sample::class))->getAttributes(),
            (new ReflectionProperty(Sample::class, 'id'))->getAttributes(),
            (new ReflectionProperty(Sample::class, 'code'))->getAttributes(),
            (new ReflectionProperty(Sample::class, 'currency'))->getAttributes(),
        ),
    );
    check(array_map('get_class', $instances) === [Table::class, Valid::class, Positive::class, Choice::class, Meta::class], 'attributes');
    check($instances[0]->name === "sample\tdto" && $instances[3]->choices === [1, 'A'] && $instances[3]->mode === 'strict', 'attribute arguments');
    check($instances[4]->type === 'App\\Dto\\Currency', 'class reference argument');
}

if (is_file($profile . 'Rules.php.golden')) {
    require $profile . 'Rules.php.golden';
    $guard = (new ReflectionProperty('App\\Dto\\Rules', 'count'))->getAttributes()[0]->newInstance();
    check($guard->limit->max === 3, 'new in attribute arguments');
}

echo 'ok ' . basename($profile) . "\n";
