<?php

namespace App\Services\Localization;

use App\Models\AppSetting;

final class LocalePreferences
{
    public const SPANISH = 'es-NI';

    public const ENGLISH = 'en';

    private const SETTING_KEY = 'app_locale';

    public function preference(): string
    {
        return AppSetting::query()->whereKey(self::SETTING_KEY)->value('value') === self::ENGLISH
            ? self::ENGLISH
            : self::SPANISH;
    }

    public function update(string $locale): void
    {
        $locale = $locale === self::ENGLISH ? self::ENGLISH : self::SPANISH;

        AppSetting::query()->updateOrCreate(
            ['key' => self::SETTING_KEY],
            ['value' => $locale],
        );

        app()->setLocale($locale);
    }

    public function apply(): string
    {
        $locale = $this->preference();
        app()->setLocale($locale);

        return $locale;
    }
}
