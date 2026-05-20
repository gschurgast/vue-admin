<?php

namespace App\State\Mcp;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Generic create processor for MCP tools.
 *
 * Deserializes mcp_data into a fresh entity using the resource's denormalizationContext.
 * Going through the JSON encoder forces the full normalizer chain (BackedEnumNormalizer,
 * scalar normalizers, API Platform IRI resolution for relations).
 */
final class GenericCreateProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validator,
        private readonly SerializerInterface $serializer,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): object
    {
        $class = $operation->getClass();
        if (!$class || !class_exists($class)) {
            throw new \LogicException('McpTool requires a resource class.');
        }

        $payload = (array) ($context['mcp_data'] ?? []);
        $denormContext = $operation->getDenormalizationContext() ?? [];
        $denormContext[AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES] = false;

        $entity = $this->serializer->deserialize(json_encode($payload), $class, 'json', $denormContext);

        $this->validator->validate($entity);
        $this->em->persist($entity);
        $this->em->flush();

        return $entity;
    }
}