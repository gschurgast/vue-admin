<?php

namespace App\Service;

use App\Enum\Locale;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;

class TranslationService
{
    private const SYSTEM_PROMPT = 'You are a professional translator. Return only the translated text, no explanations or quotes.';
    private const MODEL = 'gpt-4o-mini';

    public function __construct(
        private PlatformInterface $platform
    ) {}

    /**
     * Translate text from source locale to a single target locale
     */
    public function translateToSingle(string $text, Locale $sourceLocale, Locale $targetLocale): string
    {
        if (empty($text)) {
            return '';
        }

        if ($sourceLocale === $targetLocale) {
            return $text;
        }

        $prompt = sprintf(
            'Translate the following text from %s to %s. Return ONLY the translated text, nothing else. Text to translate: "%s"',
            $sourceLocale->label(),
            $targetLocale->label(),
            $text
        );

        $messages = new MessageBag();
        $messages->add(Message::forSystem(self::SYSTEM_PROMPT));
        $messages->add(Message::ofUser($prompt));

        $response = $this->platform->invoke(
            model: self::MODEL,
            input: $messages
        );

        $content = $response->asText();

        // Clean up any surrounding quotes
        return trim($content, " \t\n\r\0\x0B\"'");
    }

    /**
     * Get all available locales
     */
    public function getAvailableLocales(): array
    {
        return Locale::cases();
    }
}
