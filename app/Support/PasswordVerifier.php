<?php

namespace App\Support;

use Illuminate\Support\Facades\Hash;
use RuntimeException;

class PasswordVerifier
{
    public static function check(string $plainText, string $storedValue): bool
    {
        if (self::isBcryptHash($storedValue) && password_verify($plainText, $storedValue)) {
            return true;
        }

        try {
            return Hash::check($plainText, $storedValue);
        } catch (RuntimeException $exception) {
            if (! self::isBcryptHash($storedValue)) {
                throw $exception;
            }

            return password_verify($plainText, $storedValue);
        }
    }

    public static function checkHashOrPlainText(string $plainText, string $storedValue): bool
    {
        if (! Hash::isHashed($storedValue)) {
            return hash_equals($storedValue, $plainText);
        }

        return self::check($plainText, $storedValue);
    }

    private static function isBcryptHash(string $storedValue): bool
    {
        return (bool) preg_match('/^\$2[aby]\$\d{2}\$/', $storedValue);
    }
}
