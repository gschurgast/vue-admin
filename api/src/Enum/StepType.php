<?php

namespace App\Enum;

enum StepType: string
{
    case RESIZE = 'resize';
    case CROP = 'crop';
    case ROTATE = 'rotate';
    case FORMAT_CONVERT = 'format_convert';
    case ADD_BACKGROUND = 'add_background';
    case REMOVE_BACKGROUND = 'remove_background';

    public function label(): string
    {
        return match ($this) {
            self::RESIZE => 'Redimensionner',
            self::CROP => 'Recadrer',
            self::ROTATE => 'Rotation',
            self::FORMAT_CONVERT => 'Convertir le format',
            self::ADD_BACKGROUND => 'Ajouter un arrière-plan',
            self::REMOVE_BACKGROUND => 'Supprimer l\'arrière-plan',
        };
    }

    /**
     * @return string[]
     */
    public static function allCodes(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array{code: string, label: string}
     */
    public function toArray(): array
    {
        return ['code' => $this->value, 'label' => $this->label()];
    }
}
