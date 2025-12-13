<?php

declare(strict_types=1);

namespace WordPress\AiClient\Tests\unit\Providers\ApiBasedImplementation;

use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\Contracts\WithHttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\WithRequestAuthenticationInterface;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;

/**
 * Mock class for testing AbstractApiBasedModel.
 */
class MockApiBasedModel extends AbstractApiBasedModel implements
    ModelInterface,
    WithHttpTransporterInterface,
    WithRequestAuthenticationInterface
{
    // No additional methods needed for this mock, as we are testing the abstract class's concrete methods.
}
