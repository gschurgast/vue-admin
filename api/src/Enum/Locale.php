<?php

namespace App\Enum;

enum Locale: string
{
    case EN_US = 'en_US';
    case EN_GB = 'en_GB';
    case FR_FR = 'fr_FR';
    case DE_DE = 'de_DE';
    case ES_ES = 'es_ES';
    case IT_IT = 'it_IT';
    case PT_PT = 'pt_PT';
    case PT_BR = 'pt_BR';
    case DA_DK = 'da_DK';
    case NB_NO = 'nb_NO';
    case SV_SE = 'sv_SE';
    case PL_PL = 'pl_PL';
    case ZH_CN = 'zh_CN';
    case ZH_TW = 'zh_TW';
    case JA_JP = 'ja_JP';
    case AR_SA = 'ar_SA';
    case HE_IL = 'he_IL';

    public function label(): string
    {
        return match($this) {
            self::EN_US => 'English (US)',
            self::EN_GB => 'English (UK)',
            self::FR_FR => 'Français',
            self::DE_DE => 'Deutsch',
            self::ES_ES => 'Español',
            self::IT_IT => 'Italiano',
            self::PT_PT => 'Português',
            self::PT_BR => 'Português (BR)',
            self::DA_DK => 'Dansk',
            self::NB_NO => 'Norsk',
            self::SV_SE => 'Svenska',
            self::PL_PL => 'Polski',
            self::ZH_CN => '中文 (简体)',
            self::ZH_TW => '中文 (繁體)',
            self::JA_JP => '日本語',
            self::AR_SA => 'العربية',
            self::HE_IL => 'עברית',
        };
    }

    public function flag(): string
    {
        return match($this) {
            self::EN_US => '🇺🇸',
            self::EN_GB => '🇬🇧',
            self::FR_FR => '🇫🇷',
            self::DE_DE => '🇩🇪',
            self::ES_ES => '🇪🇸',
            self::IT_IT => '🇮🇹',
            self::PT_PT => '🇵🇹',
            self::PT_BR => '🇧🇷',
            self::DA_DK => '🇩🇰',
            self::NB_NO => '🇳🇴',
            self::SV_SE => '🇸🇪',
            self::PL_PL => '🇵🇱',
            self::ZH_CN => '🇨🇳',
            self::ZH_TW => '🇹🇼',
            self::JA_JP => '🇯🇵',
            self::AR_SA => '🇸🇦',
            self::HE_IL => '🇮🇱',
        };
    }

    public static function allCodes(): array
    {
        return array_map(fn($case) => $case->value, self::cases());
    }

    public static function toArray(): array
    {
        return array_map(fn($case) => [
            'code' => $case->value,
            'label' => $case->label(),
            'flag' => $case->flag(),
        ], self::cases());
    }
}
