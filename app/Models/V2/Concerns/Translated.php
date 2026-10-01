<?php

namespace App\Models\V2\Concerns;

/** $model->t('title') returns title_ar / title_en for the current locale (falls back to English). */
trait Translated
{
    public function t(string $field): ?string
    {
        $loc = app()->getLocale() === 'ar' ? 'ar' : 'en';
        $v = $this->{$field . '_' . $loc} ?? null;

        return $v !== null && $v !== '' ? $v : ($this->{$field . '_en'} ?? null);
    }
}
