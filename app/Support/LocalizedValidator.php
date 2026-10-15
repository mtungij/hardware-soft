<?php

namespace App\Support;

use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

class LocalizedValidator extends Validator
{
    public function getDisplayableAttribute($attribute)
    {
        $display = parent::getDisplayableAttribute($attribute);
        if ($this->translator->getLocale() !== 'sw') {
            return $display;
        }

        $attributes = $this->translator->get('validation.attributes', [], 'sw');
        if (is_array($attributes) && in_array($display, $attributes, true)) {
            return $display;
        }

        $field = preg_replace('/_id$/', '', basename(str_replace('.', '/', $attribute)));
        $english = Str::headline($field);
        $translated = UiText::translate($english);

        return $translated !== $english ? $translated : $this->translator->get('validation.field');
    }
}
