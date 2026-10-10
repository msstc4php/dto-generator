<?php

declare(strict_types=1);

use MSSTC4PHP\DtoGenerator\Tests\Support\TestRunTemporaryDirectory;

require __DIR__ . '/../vendor/autoload.php';

TestRunTemporaryDirectory::isolate(__DIR__ . '/../var/tmp');
