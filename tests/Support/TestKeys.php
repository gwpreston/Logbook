<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * Makes the signing keys the tests need (spec.md §9, Phase 54.21, K220, #644). Every key passes
 * `tests/Fixtures/openssl.cnf` as its configuration, on every OS, because `openssl_pkey_new()`
 * returns false when PHP finds no `openssl.cnf` of its own (a WinGet PHP on Windows). Making keys
 * only here means a new test can't forget the file.
 */
final class TestKeys
{
    /** The minimal configuration: a `[ req ]` section naming an empty distinguished-name section. */
    public const string CONFIG = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'Fixtures'
        . DIRECTORY_SEPARATOR . 'openssl.cnf';

    /** A 2048-bit RSA key, for RS256 and PS256 tokens. */
    public static function rsa(): OpenSSLAsymmetricKey
    {
        return self::make(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA], 'an RSA');
    }

    /** A P-256 key, for ES256 tokens. */
    public static function ec(): OpenSSLAsymmetricKey
    {
        return self::make(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'], 'an EC');
    }

    /**
     * @param array<string, int|string> $options
     */
    private static function make(array $options, string $kind): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['config' => self::CONFIG] + $options);

        // OpenSSL still queues its start-up errors about a missing default openssl.cnf (and its
        // random-seed file) even when this config works; empty the queue so a later
        // openssl_error_string() in a test reads only its own errors.
        while (openssl_error_string() !== false) {
        }

        if ($key === false) {
            throw new RuntimeException(sprintf('openssl could not make %s key', $kind));
        }

        return $key;
    }
}
