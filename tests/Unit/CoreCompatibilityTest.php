<?php

namespace Plugins\Jw\PowerCache\Tests\Unit;

use Plugins\Jw\PowerCache\Runtime\CoreCompatibility;
use Plugins\Jw\PowerCache\Tests\Support\PowerCacheTestCase;

final class CoreCompatibilityTest extends PowerCacheTestCase
{
    public function test_explicit_override_can_model_supported_and_unsupported_cores(): void
    {
        self::assertTrue((new CoreCompatibility(true))->supportsTransactionalActions());
        self::assertFalse((new CoreCompatibility(false))->supportsTransactionalActions());
    }
}
