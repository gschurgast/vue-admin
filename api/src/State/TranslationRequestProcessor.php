<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\TranslationRequest;
use App\Enum\Locale;
use App\Service\TranslationService;

class TranslationRequestProcessor implements ProcessorInterface
{
    public function __construct(
        private TranslationService $translationService
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TranslationRequest
    {
        if (!$data instanceof TranslationRequest) {
            throw new \InvalidArgumentException('Expected TranslationRequest');
        }

        if (empty($data->text) || empty($data->sourceLocale) || empty($data->targetLocale)) {
            throw new \InvalidArgumentException(sprintf(
                'Text, sourceLocale and targetLocale are required. Received: text=%s, sourceLocale=%s, targetLocale=%s',
                $data->text ?? 'null',
                $data->sourceLocale ?? 'null',
                $data->targetLocale ?? 'null'
            ));
        }

        // Validate source locale
        $sourceLocale = Locale::tryFrom($data->sourceLocale);
        if (!$sourceLocale) {
            throw new \InvalidArgumentException('Invalid source locale: ' . $data->sourceLocale);
        }

        // Validate target locale
        $targetLocale = Locale::tryFrom($data->targetLocale);
        if (!$targetLocale) {
            throw new \InvalidArgumentException('Invalid target locale: ' . $data->targetLocale);
        }

        // Translate to the single target locale
        $translation = $this->translationService->translateToSingle(
            $data->text,
            $sourceLocale,
            $targetLocale
        );

        $data->translation = $translation;

        return $data;
    }
}
