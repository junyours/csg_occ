<?php

namespace App\Domain\ValueObjects;

use App\Domain\Exceptions\InvalidQrPayloadException;
use Carbon\Carbon;

final class QrPayload
{
    private const DELIMITER = '|';

    // Single-byte format tag prefixed to every token. Lets decrypt() reject
    // tokens from the old Crypt::encryptString() format (or anything else)
    // by a cheap length/prefix check before touching openssl_decrypt.
    private const FORMAT_VERSION = "\x01";

    private const CIPHER = 'aes-256-gcm';

    private const IV_LENGTH = 12; // recommended nonce size for GCM

    private const TAG_LENGTH = 16;

    // 1 (version) + 12 (iv) + 16 (tag) + at least 1 byte of ciphertext.
    private const MIN_DECODED_LENGTH = 1 + self::IV_LENGTH + self::TAG_LENGTH + 1;

    public function __construct(
        public readonly string $studentNumber,
        public readonly int $qrVersion,
        public readonly Carbon $issuedAt,
    ) {}

    public static function forStudent(string $studentNumber, int $qrVersion, ?Carbon $issuedAt = null): self
    {
        return new self($studentNumber, $qrVersion, $issuedAt ?? Carbon::now('UTC'));
    }

    /**
     * Encrypt this payload into the string that gets embedded in the QR code.
     *
     * Uses raw AES-256-GCM via openssl_encrypt() instead of Laravel's
     * Crypt::encryptString(): no JSON envelope, no field names, no separate
     * HMAC (GCM's tag already authenticates the ciphertext). The key is
     * derived from APP_KEY so this stays keyed off the same secret Laravel
     * already manages, without reusing it directly for a different cipher.
     *
     * Layout before encoding: [version(1)][iv(12)][tag(16)][ciphertext(n)].
     * Base64url (no padding) keeps the QR alphanumeric-friendly.
     */
    public function encrypt(): string
    {
        $raw = implode(self::DELIMITER, [
            $this->studentNumber,
            $this->qrVersion,
            $this->issuedAt->timestamp,
        ]);

        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $raw,
            self::CIPHER,
            self::deriveKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH,
        );

        if ($ciphertext === false) {
            throw new InvalidQrPayloadException('QR token could not be encrypted.');
        }

        $bytes = self::FORMAT_VERSION.$iv.$tag.$ciphertext;

        return self::base64UrlEncode($bytes);
    }

    /**
     * Decrypt a scanned QR string back into a QrPayload.
     *
     * @throws InvalidQrPayloadException if the token is forged, tampered with,
     *         encrypted under a different key, or not in the expected shape.
     */
    public static function decrypt(string $token): self
    {
        $bytes = self::base64UrlDecode($token);

        if ($bytes === null || strlen($bytes) < self::MIN_DECODED_LENGTH || $bytes[0] !== self::FORMAT_VERSION) {
            throw new InvalidQrPayloadException('QR token could not be decrypted.');
        }

        $iv = substr($bytes, 1, self::IV_LENGTH);
        $tag = substr($bytes, 1 + self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($bytes, 1 + self::IV_LENGTH + self::TAG_LENGTH);

        $raw = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            self::deriveKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );

        if ($raw === false) {
            throw new InvalidQrPayloadException('QR token could not be decrypted.');
        }

        $parts = explode(self::DELIMITER, $raw);

        if (count($parts) !== 3 || $parts[0] === '' || ! ctype_digit($parts[1]) || ! ctype_digit($parts[2])) {
            throw new InvalidQrPayloadException('QR token payload is malformed.');
        }

        [$studentNumber, $qrVersion, $issuedAtTimestamp] = $parts;

        return new self(
            $studentNumber,
            (int) $qrVersion,
            Carbon::createFromTimestamp((int) $issuedAtTimestamp, 'UTC'),
        );
    }

    /**
     * Derive a dedicated 32-byte AES-256 key from APP_KEY so raw QR
     * encryption doesn't reuse the exact key bytes Laravel's own
     * Crypt facade uses for everything else.
     */
    private static function deriveKey(): string
    {
        $appKey = config('app.key');

        if (! is_string($appKey) || $appKey === '') {
            throw new InvalidQrPayloadException('APP_KEY is not configured.');
        }

        if (str_starts_with($appKey, 'base64:')) {
            $appKey = base64_decode(substr($appKey, 7), true) ?: $appKey;
        }

        return hash_hmac('sha256', 'qr-payload-v1', $appKey, true);
    }

    private static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $token): ?string
    {
        $padded = strtr($token, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);

        $decoded = base64_decode($padded, true);

        return $decoded === false ? null : $decoded;
    }
}
