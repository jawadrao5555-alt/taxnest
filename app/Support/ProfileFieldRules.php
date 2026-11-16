<?php
namespace App\Support;

/** Format validation only, not identity verification or consent to send messages. */
final class ProfileFieldRules
{
    public static function phone(): array
    {
        return ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9][0-9 ()-]*$/D', function ($attribute, $value, $fail) {
            $digits = preg_replace('/\D/', '', (string) $value);
            if (strlen($digits) < 7 || strlen($digits) > 15) $fail(__('profile_fields.invalid_phone'));
        }];
    }
    public static function cnic(): array
    {
        return ['nullable', 'string', 'regex:/^(?:[0-9]{13}|[0-9]{5}-[0-9]{7}-[0-9])$/D'];
    }
}
