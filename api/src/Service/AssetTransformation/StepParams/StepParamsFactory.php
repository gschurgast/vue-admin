<?php

declare(strict_types=1);

namespace App\Service\AssetTransformation\StepParams;

use App\Entity\AssetTransformation\TransformationStep;
use App\Enum\StepType;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Hydrates a TransformationStep's params array into the matching readonly DTO
 * and validates it (Assert constraints). Used by both the Doctrine lifecycle
 * listener (write path — fixtures, API, console) and the PipelineRunner
 * (read path — Plan 03 controller).
 */
final class StepParamsFactory
{
    public function __construct(
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
    ) {
    }

    /**
     * @throws ValidationFailedException   On any Assert violation (422 surface).
     * @throws UnsupportedStepTypeException On a StepType not yet wired.
     */
    public function fromStep(TransformationStep $step): object
    {
        $type = $step->getType();
        if ($type === null) {
            throw new \LogicException('TransformationStep::$type must be set before validation.');
        }

        $class = match ($type) {
            StepType::RESIZE            => ResizeStepParams::class,
            StepType::CROP              => CropStepParams::class,
            StepType::ROTATE            => RotateStepParams::class,
            StepType::FORMAT_CONVERT    => FormatConvertStepParams::class,
            StepType::ADD_BACKGROUND    => AddBackgroundStepParams::class,
            StepType::REMOVE_BACKGROUND => RemoveBackgroundStepParams::class, // Phase 4 (BiRefNet, BGREMOVE-06).
            default                     => throw new UnsupportedStepTypeException($type),
        };

        /** @var DenormalizerInterface $denormalizer */
        $denormalizer = $this->serializer;

        $params = $step->getParams() ?? [];

        // Strict-fields: any unknown key (e.g. SSRF-prone `url` on add_background)
        // raises a denormalisation exception that the listener re-wraps as 422.
        $dto = $denormalizer->denormalize(
            $params,
            $class,
            null,
            [AbstractObjectNormalizer::ALLOW_EXTRA_ATTRIBUTES => false],
        );

        $violations = $this->validator->validate($dto);
        if (count($violations) > 0) {
            throw new ValidationFailedException($dto, $violations);
        }

        return $dto;
    }
}
