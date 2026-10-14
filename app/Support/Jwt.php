<?php

namespace App\Support;

class Jwt
{
    public static function issue(string $subject, string $role): string
    {
        $now = time();
        $payload = ['sub' => $subject, 'role' => $role, 'iat' => $now, 'exp' => $now + 28800];

        return self::encode(['alg' => 'HS256', 'typ' => 'JWT']).'.'.self::encode($payload).'.'.self::sign($payload);
    }

    public static function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$header, $payload, $signature] = $parts;
        $expected = hash_hmac('sha256', $header.'.'.$payload, (string) env('JWT_SECRET'), true);
        if (! hash_equals(self::base64UrlEncode($expected), $signature)) {
            return null;
        }

        $claims = json_decode(self::base64UrlDecode($payload), true);
        if (! is_array($claims) || ($claims['exp'] ?? 0) < time()) {
            return null;
        }

        return $claims;
    }

    private static function sign(array $payload): string
    {
        $header = self::encode(['alg' => 'HS256', 'typ' => 'JWT']);
        $body = self::encode($payload);

        return self::base64UrlEncode(hash_hmac('sha256', $header.'.'.$body, (string) env('JWT_SECRET'), true));
    }

    private static function encode(array $value): string
    {
        return self::base64UrlEncode(json_encode($value, JSON_UNESCAPED_SLASHES));
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/'));
    }
}
