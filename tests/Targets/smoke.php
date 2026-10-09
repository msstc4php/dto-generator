<?php

declare(strict_types=1);

use App\Attr\Choice;
use App\Attr\Constraints\Positive;
use App\Attr\Constraints\Valid;
use App\Attr\Meta;
use App\Attr\Table;
use App\Dto\Animal;
use App\Dto\Bird;
use App\Dto\BlobX;
use App\Dto\Circle;
use App\Dto\Dog;
use App\Dto\EuroWallet;
use App\Dto\Leaf;
use App\Dto\Parrot;
use App\Dto\Product;
use App\Dto\Sample;
use App\Dto\Shape;
use App\Dto\Side;
use App\Dto\Square;
use App\Dto\Tag;
use App\Dto\Wallet;

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
require $profile . 'Square.php.golden';
require $profile . 'Wallet.php.golden';
require $profile . 'EuroWallet.php.golden';
require $profile . 'Top.php.golden';
require $profile . 'Mid.php.golden';
require $profile . 'Leaf.php.golden';
require $profile . 'Side.php.golden';
require $profile . 'Blob.php.golden';
require $profile . 'BlobX.php.golden';
require $profile . 'Creature.php.golden';
require $profile . 'Bird.php.golden';
require $profile . 'Parrot.php.golden';
require $profile . 'Named.php.golden';
require $profile . 'Device.php.golden';
require $profile . 'Phone.php.golden';
require $profile . 'Product.php.golden';

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

function rejects(callable $create, string $message): void
{
    try {
        $create();
    } catch (InvalidArgumentException $exception) {
        check($exception->getMessage() === $message, 'message: ' . $exception->getMessage());

        return;
    }

    check(false, 'rejected: ' . $message);
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
check(read(new Circle('round', 1.0), 'kind') === 'round', 'second discriminator value');
rejects(static fn (): Circle => new Circle('square', 1.0), '"square" does not select Circle by "kind".');

$square = new Square(3.0);
check($square instanceof Shape && read($square, 'kind') === 'square' && read($square, 'side') === 3.0, 'discriminator default');
rejects(static fn (): Square => new Square(3.0, 'circle'), '"circle" does not select Square by "kind".');
foreach ([$circle, $square] as $variant) {
    check(!method_exists($variant, 'withKind') && !method_exists($variant, 'setKind'), 'no discriminator mutators');
}

if (method_exists($square, 'withSide')) {
    check(read($square->withSide(4.0), 'kind') === 'square', 'a wither keeps the discriminator');
}

$euro = constant('App\\Dto\\Currency::EUR');
$wallet = new EuroWallet(10);
check($wallet instanceof Wallet && read($wallet, 'currency') === $euro && read($wallet, 'balance') === 10, 'enum discriminator default');
rejects(
    static fn (): EuroWallet => new EuroWallet(10, constant('App\\Dto\\Currency::IN_PROGRESS')),
    '"in-progress" does not select EuroWallet by "currency".',
);

// A discriminator of any class in the chain leaves no mutator that could change it.
foreach (['App\\Dto\\Mid' => ['Kind', 'Sub'], 'App\\Dto\\Leaf' => ['Kind', 'Sub'], 'App\\Dto\\Side' => ['Kind'], 'App\\Dto\\BlobX' => ['Kind'], 'App\\Dto\\Bird' => ['Kind'], 'App\\Dto\\Parrot' => ['Kind']] as $class => $suffixes) {
    foreach ($suffixes as $suffix) {
        check(!method_exists($class, 'with' . $suffix) && !method_exists($class, 'set' . $suffix), 'no mutator of ' . $class . '::' . $suffix);
    }
}

$leaf = new Leaf('x');
check(read($leaf, 'kind') === 'm' && read($leaf, 'sub') === 'one' && read($leaf, 'a') === 'x', 'two discriminator defaults');
rejects(static fn (): Leaf => new Leaf('x', 's'), '"s" does not select Leaf by "kind".');
rejects(static fn (): Leaf => new Leaf('x', 'm', 'two'), '"two" does not select Leaf by "sub".');
check(read(new Side('c'), 'kind') === 's', 'optional discriminator default');
rejects(static fn (): Side => new Side('c', null), 'null does not select Side by "kind".');
rejects(static fn (): Side => new Side('c', 'm'), '"m" does not select Side by "kind".');
check(read(new BlobX(['any'], 1), 'kind') === ['any'], 'unchecked discriminator');

check(read(new Bird('bird', 2), 'kind') === 'bird', 'open class');
rejects(static fn (): Bird => new Bird('parrot', 2), '"parrot" does not select Bird by "kind".');
rejects(static fn (): Bird => new Bird('fish', 2), '"fish" does not select Bird by "kind".');
$parrot = new Parrot(2);
check($parrot instanceof Bird && read($parrot, 'kind') === 'parrot' && read($parrot, 'wings') === 2, 'subclass of an open class');
rejects(static fn (): Parrot => new Parrot(2, 'bird'), '"bird" does not select Parrot by "kind".');

// Product is outside Device's discriminated chain, so it changes `kind` although Named, inside it, may not.
foreach (['App\\Dto\\Named', 'App\\Dto\\Phone'] as $class) {
    check(!method_exists($class, 'withKind') && !method_exists($class, 'setKind'), 'no mutator of ' . $class . '::kind');
}

$product = new Product('gadget', 'box', 5);
if (strpos(basename($profile), '-immutable') !== false && strpos(basename($profile), '-nowithers') === false) {
    $renamed = $product->withKind('crate');
    check($renamed instanceof Product && read($renamed, 'kind') === 'crate' && read($product, 'kind') === 'gadget', 'restored wither');
}

if (method_exists($product, 'setKind')) {
    check($product->setKind('crate') === $product && read($product, 'kind') === 'crate', 'restored setter');
}

if (strpos(basename($profile), '-mutable-getters') !== false) {
    check(method_exists($product, 'setKind'), 'restored setter declared');
}
check((new ReflectionClass(Shape::class))->isAbstract() && !(new ReflectionClass(Animal::class))->isFinal(), 'base classes stay open');

if (strpos(basename($profile), '-nowithers') !== false) {
    foreach (get_declared_classes() as $class) {
        if (strpos($class, 'App\\Dto\\') === 0) {
            $withers = preg_grep('/^with/', get_class_methods($class));
            check($withers === [], 'no withers in ' . $class);
        }
    }
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

if (PHP_VERSION_ID < 80000) {
    // Below 8.0 the same metadata is docblock annotations.
    $classDoc = (string) (new ReflectionClass(Sample::class))->getDocComment();
    $idDoc = (string) (new ReflectionProperty(Sample::class, 'id'))->getDocComment();
    check(strpos($classDoc, '@\\App\\Attr\\Table(name="sample') !== false && strpos($classDoc, '@Assert\\Valid') !== false, 'class annotations');
    check(strpos($idDoc, '@Assert\\Positive') !== false, 'property annotation');
}

if (is_file($profile . 'Rules.php.golden')) {
    require $profile . 'Rules.php.golden';
    $guard = (new ReflectionProperty('App\\Dto\\Rules', 'count'))->getAttributes()[0]->newInstance();
    check($guard->limit->max === 3, 'new in attribute arguments');
}

echo 'ok ' . basename($profile) . "\n";
