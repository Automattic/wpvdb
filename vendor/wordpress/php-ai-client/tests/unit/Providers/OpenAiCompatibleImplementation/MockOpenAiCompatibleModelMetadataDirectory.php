<?php

declare(strict_types=1);

namespace WordPress\AiClient\Tests\unit\Providers\OpenAiCompatibleImplementation;

use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleModelMetadataDirectory;

/**
 * Mock class for testing AbstractOpenAiCompatibleModelMetadataDirectory.
 */
class MockOpenAiCompatibleModelMetadataDirectory extends AbstractOpenAiCompatibleModelMetadataDirectory
{
    /**
     * @var HttpTransporterInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private $mockHttpTransporter;

    /**
     * @var RequestAuthenticationInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private $mockRequestAuthentication;

    /**
     * @var array<string, ModelMetadata>
     */
    private array $mockModels;

    /**
     * @var callable
     */
    private $modelMetadataStubFactory;

    /**
     * Constructor.
     *
     * @param HttpTransporterInterface&\PHPUnit\Framework\MockObject\MockObject $mockHttpTransporter
     * @param RequestAuthenticationInterface&\PHPUnit\Framework\MockObject\MockObject $mockRequestAuthentication
     * @param callable $modelMetadataStubFactory
     * @param array<string, ModelMetadata> $mockModels
     */
    public function __construct(
        $mockHttpTransporter,
        $mockRequestAuthentication,
        callable $modelMetadataStubFactory,
        array $mockModels = []
    ) {
        $this->mockHttpTransporter = $mockHttpTransporter;
        $this->mockRequestAuthentication = $mockRequestAuthentication;
        $this->modelMetadataStubFactory = $modelMetadataStubFactory;
        $this->mockModels = $mockModels;
    }

    /**
     * @inheritdoc
     */
    public function getHttpTransporter(): HttpTransporterInterface
    {
        return $this->mockHttpTransporter;
    }

    /**
     * @inheritdoc
     */
    public function getRequestAuthentication(): RequestAuthenticationInterface
    {
        return $this->mockRequestAuthentication;
    }

    /**
     * @inheritdoc
     */
    protected function createRequest(
        HttpMethodEnum $method,
        string $path,
        array $headers = [],
        $data = null
    ): Request {
        return new Request($method, 'https://example.com/' . $path, $headers, $data);
    }

    /**
     * @inheritdoc
     */
    protected function parseResponseToModelMetadataList(Response $response): array
    {
        $data = $response->getData();
        $modelsMetadata = [];
        if (isset($data['data']) && is_array($data['data'])) {
            foreach ($data['data'] as $modelData) {
                if (isset($modelData['id']) && is_string($modelData['id'])) {
                    $factory = $this->modelMetadataStubFactory;
                    $modelMetadata = $factory($modelData['id']);
                    $modelsMetadata[] = $modelMetadata;
                }
            }
        }
        return $modelsMetadata;
    }
}
