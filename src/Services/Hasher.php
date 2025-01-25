<?php

namespace Pixelated\Streamline\Services;

class Hasher
{
    private const int DEFAULT_HASH_LENGTH = 20;

    /**
     * @throws \Random\RandomException
     */
    public function generate(): string
    {
        return base64_encode(password_hash($this->getHash(self::DEFAULT_HASH_LENGTH), PASSWORD_BCRYPT));
    }

    /**
     * Copy from Laravel Str::random()
     * @throws \Random\RandomException
     * @noinspection DuplicatedCode
     */
    protected function getHash(int $length): string
    {
        $hash       = '';
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!$%&()-?';

        for ($i = 0; $i < $length; $i++) {
            $index = random_int(0, strlen($characters) - 1);
            $hash  .= $characters[$index];
        }

        return $hash;
    }
}
