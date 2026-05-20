<?php

namespace App\Agent\Mcp;

use ApiPlatform\Mcp\State\ToolProvider;
use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operation\Factory\OperationMetadataFactoryInterface;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\State\ProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Calls API Platform MCP tools in-process (no JSON-RPC roundtrip).
 *
 * Replicates the runtime semantics of ApiPlatform\Mcp\Server\Handler::handle without
 * requiring a real Mcp\Schema request or SessionInterface, so any Symfony service
 * (e.g. Symfony AI agent tools) can invoke the same tools exposed at /_mcp.
 */
final class McpInProcessClient
{
    public function __construct(
        #[Autowire(service: 'api_platform.mcp.metadata.operation.mcp_factory')]
        private readonly OperationMetadataFactoryInterface $operationMetadataFactory,
        #[Autowire(service: 'api_platform.state_provider.locator')]
        private readonly ProviderInterface $provider,
        #[Autowire(service: 'api_platform.mcp.state_processor.write')]
        private readonly ProcessorInterface $processor,
        #[Autowire(service: 'api_platform.serializer')]
        private readonly SerializerInterface $serializer,
        private readonly RequestStack $requestStack,
    ) {}

    /**
     * Invokes the tool and returns the raw result (entity, Paginator, etc.).
     *
     * @param array<string,mixed> $arguments
     */
    public function call(string $toolName, array $arguments = []): mixed
    {
        $operation = $this->operationMetadataFactory->create($toolName);
        if (!$operation instanceof HttpOperation) {
            throw new \RuntimeException(\sprintf('MCP tool "%s" not found.', $toolName));
        }

        $uriVariables = [];
        foreach ($operation->getUriVariables() ?? [] as $key => $link) {
            if (isset($arguments[$key])) {
                $uriVariables[$key] = $arguments[$key];
            }
        }

        // ReadProvider reads filters from the current request's _api_filters attribute.
        // We override it with our tool arguments so SearchFilter, pagination, etc. apply
        // exactly as in the HTTP path.
        $filters = array_diff_key($arguments, $uriVariables);
        $request = $this->requestStack->getCurrentRequest();
        $previousFilters = null;
        if ($request) {
            $previousFilters = $request->attributes->get('_api_filters');
            $request->attributes->set('_api_filters', $filters);
        }

        $context = [
            'request' => $request,
            'uri_variables' => $uriVariables,
            'resource_class' => $operation->getClass(),
            'mcp_data' => $arguments,
            'filters' => $filters,
        ];

        if (null === $operation->canNegotiateContent()) {
            $operation = $operation->withContentNegotiation(false);
        }
        if (null === $operation->canValidate()) {
            $operation = $operation->withValidate(false);
        }
        if (null === $operation->canRead()) {
            $operation = $operation->withRead(true);
        }
        if (null === $operation->getProvider()) {
            $operation = $operation->withProvider(ToolProvider::class);
        }
        if (null === $operation->canDeserialize()) {
            $operation = $operation->withDeserialize(false);
        }
        if (null === $operation->canWrite()) {
            $operation = $operation->withWrite(true);
        }
        if (null === $operation->canSerialize()) {
            $operation = $operation->withSerialize(false);
        }

        try {
            $body = $this->provider->provide($operation, $uriVariables, $context);

            return $this->processor->process($body, $operation, $uriVariables, $context);
        } finally {
            if ($request) {
                if ($previousFilters === null) {
                    $request->attributes->remove('_api_filters');
                } else {
                    $request->attributes->set('_api_filters', $previousFilters);
                }
            }
        }
    }

    /**
     * Invokes the tool and returns a JSON string suitable as an LLM tool result.
     *
     * @param array<string,mixed> $arguments
     */
    public function callAsJson(string $toolName, array $arguments = []): string
    {
        $result = $this->call($toolName, $arguments);

        if ($result === null) {
            return 'null';
        }
        if (is_string($result)) {
            return $result;
        }

        $operation = $this->operationMetadataFactory->create($toolName);
        $serializerContext = $operation?->getNormalizationContext() ?? [];
        $serializerContext['operation'] = $operation;
        if ($operation?->getClass()) {
            $serializerContext['resource_class'] = $operation->getClass();
        }

        $normalized = $this->serializer->normalize($result, 'jsonld', $serializerContext);
        return json_encode($normalized, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}