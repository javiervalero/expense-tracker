<?php

declare(strict_types=1);

namespace App\Auth\Password;

/** Why a password was refused (A11): the stable code is the reason of the field error. */
enum PasswordViolation: string
{
    case InvalidEncoding = 'invalid_encoding';
    case TooShort = 'too_short';
    case TooLong = 'too_long';
    case ControlCharacter = 'control_character';
}
