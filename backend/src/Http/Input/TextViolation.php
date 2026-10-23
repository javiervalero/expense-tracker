<?php

declare(strict_types=1);

namespace App\Http\Input;

/** Why a free-text field was refused (B4): the stable code is the reason of the field error. */
enum TextViolation: string
{
    case InvalidEncoding = 'invalid_encoding';
    case Empty = 'empty';
    case TooLong = 'too_long';
    case ControlCharacter = 'control_character';
    case BidiControl = 'bidi_control';
    case InvisibleCharacter = 'invisible_character';
}
