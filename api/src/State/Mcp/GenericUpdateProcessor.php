<?php

namespace App\State\Mcp;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Generic update processor for MCP tools.
 *
 * Loads the entity by id, then merges mcp_data fields via the Symfony serializer using the
 * resource's denormalizationContext. Null fields are skipped (PATCH-like semantics).
 */
final class GenericUpdateProcessor implements ProcessorInterface
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
        $id = $payload['id'] ?? null;
        if ($id === null) {
            throw new \InvalidArgumentException('Update requires an "id" argument.');
        }
        unset($payload['id']);

        // Drop null fields so the serializer only touches what was explicitly provided.
        $payload = array_filter($payload, static fn ($v) => $v !== null);

        $entity = $this->em->getRepository($class)->find($id);
        if (!$entity) {
            throw new NotFoundHttpException(\sprintf('%s %s not found.', $class, $id));
        }

        if ($payload) {
            $denormContext = $operation->getDenormalizationContext() ?? [];
            $denormContext[AbstractNormalizer::OBJECT_TO_POPULATE] = $entity;
            $denormContext[AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES] = false;
            $this->serializer->deserialize(json_encode($payload), $class, 'json', $denormContext);
        }

        $this->validator->validate($entity);
        $this->em->flush();

        return $entity;
    }
}