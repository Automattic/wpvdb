<?php

declare(strict_types=1);

namespace WordPress\AiClient\Tests\unit\Providers\ApiBasedImplementation;

use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModelMetadataDirectory;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

/**
 * Mock class for testing AbstractApiBasedModelMetadataDirectory.
 */
class MockApiBasedModelMetadataDirectory extends AbstractApiBasedModelMetadataDirectory
{
    /**
     * @var array<string, ModelMetadata>
     */
    private array $mockModels;

    /**
     * Constructor.
     *
     * @param array<string, ModelMetadata> $mockModels
     */
    public function __construct(array $mockModels = [])
    {
        $this->mockModels = $mockModels;
    }

    /**
     * @inheritdoc
     */
    protected function sendListModelsRequest(): array
    {
        return $this->mockModels;
    }
}
