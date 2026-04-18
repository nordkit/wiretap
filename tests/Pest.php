<?php

declare(strict_types=1);

use Nordkit\Wiretap\Tests\TestCase;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

uses(TestCase::class)->in('Feature');
uses(OrchestraTestCase::class)->in('Unit');
