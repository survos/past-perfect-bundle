<?php

declare(strict_types=1);

namespace Survos\SurvosPastPerfectBundle\Tests;

use Survos\SurvosPastPerfectBundle\SurvosPastPerfectBundle;

class SurvosPastPerfectBundleTest extends \TestCase
{
    public function testBundleExists(): void
    {
        $bundle = new SurvosPastPerfectBundle();
        $this->assertInstanceOf(SurvosPastPerfectBundle::class, $bundle);
    }
}
