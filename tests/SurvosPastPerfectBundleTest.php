<?php

namespace Survos\SurvosPastPerfectBundle\Tests;

use PHPUnit\Framework\TestCase;
use Survos\SurvosPastPerfectBundle\SurvosPastPerfectBundle;

class SurvosPastPerfectBundleTest extends \TestCase
{
	public function testBundleExists(): void
	{
		$bundle = new SurvosPastPerfectBundle();
		$this->assertInstanceOf(SurvosPastPerfectBundle::class, $bundle);
	}
}
