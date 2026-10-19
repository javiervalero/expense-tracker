<?php

declare(strict_types=1);

namespace App\Auth;

/** Why an address was refused (A10): the stable code is the reason of the field error. */
enum InvalidEmail: string
{
    case Empty = 'empty';
    case TooLong = 'too_long';
    case ControlCharacter = 'control_character';
    case NonAscii = 'non_ascii';
    case Malformed = 'malformed';
}
