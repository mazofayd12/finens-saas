<?php
/**
 * SocialSecurity: Encryption, OAuth State Validation, PKCE, and Security Sanitization
 */

class SocialSecurity {
    /**
     * Encrypt sensitive credentials using AES-256-CBC with HMAC-SHA256 integrity (Encrypt-then-MAC)
     */
    public static function encrypt(string $plainText, ?string $customKey = null): string {
        if ($plainText === '') {
            return '';
        }

        $secret = $customKey ?: (defined('AUTH_SECRET_KEY') ? AUTH_SECRET_KEY : 'finens_default_social_key_2026');
        $encKey = hash_hkdf('sha256', $secret, 32, 'social_encryption', 'enc_salt');
        $macKey = hash_hkdf('sha256', $secret, 32, 'social_authentication', 'mac_salt');

        $iv = random_bytes(16);
        $cipherText = openssl_encrypt($plainText, 'aes-256-cbc', $encKey, OPENSSL_RAW_DATA, $iv);
        if ($cipherText === false) {
            throw new RuntimeException('Failed to encrypt social token: ' . openssl_error_string());
        }

        $hmac = hash_hmac('sha256', $iv . $cipherText, $macKey, true);
        return base64_encode($iv . $hmac . $cipherText);
    }

    /**
     * Decrypt sensitive credentials and verify HMAC integrity
     */
    public static function decrypt(?string $cipherText, ?string $customKey = null): ?string {
        if (empty($cipherText)) {
            return null;
        }

        $raw = base64_decode($cipherText, true);
        if ($raw === false || strlen($raw) < 48) { // 16 bytes IV + 32 bytes HMAC + at least 1 byte ciphertext
            return null;
        }

        $secret = $customKey ?: (defined('AUTH_SECRET_KEY') ? AUTH_SECRET_KEY : 'finens_default_social_key_2026');
        $encKey = hash_hkdf('sha256', $secret, 32, 'social_encryption', 'enc_salt');
        $macKey = hash_hkdf('sha256', $secret, 32, 'social_authentication', 'mac_salt');

        $iv = substr($raw, 0, 16);
        $hmac = substr($raw, 16, 32);
        $encrypted = substr($raw, 48);

        $expectedHmac = hash_hmac('sha256', $iv . $encrypted, $macKey, true);
        if (!hash_equals($expectedHmac, $hmac)) {
            return null; // Tampered or invalid ciphertext
        }

        $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', $encKey, OPENSSL_RAW_DATA, $iv);
        return $decrypted !== false ? $decrypted : null;
    }

    /**
     * Generate secure signed OAuth State token with 15-minute expiration
     */
    public static function generateOAuthState(int $tenantId, string $platform): string {
        $timestamp = time();
        $nonce = bin2hex(random_bytes(12));
        $data = "{$tenantId}|{$platform}|{$timestamp}|{$nonce}";

        $secret = defined('AUTH_SECRET_KEY') ? AUTH_SECRET_KEY : 'finens_social_oauth_salt';
        $signature = hash_hmac('sha256', $data, $secret);

        $state = rtrim(strtr(base64_encode("{$data}|{$signature}"), '+/', '-_'), '=');
        
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['oauth_state_' . $platform] = $state;
            $_SESSION['oauth_state_time_' . $platform] = $timestamp;
            $_SESSION['oauth_tenant_id'] = $tenantId;
        }

        return $state;
    }

    /**
     * Validate OAuth State token (prevents CSRF and replay attacks)
     */
    public static function validateOAuthState(string $state, int $expectedTenantId, string $expectedPlatform): bool {
        if (empty($state)) {
            return false;
        }

        $padded = str_pad(strtr($state, '-_', '+/'), strlen($state) % 4 === 0 ? strlen($state) : strlen($state) + (4 - strlen($state) % 4), '=', STR_PAD_RIGHT);
        $decoded = base64_decode($padded, true);
        if (!$decoded) {
            return false;
        }

        $parts = explode('|', $decoded);
        if (count($parts) !== 5) {
            return false;
        }

        [$tenantId, $platform, $timestamp, $nonce, $signature] = $parts;

        // Verify expiration (max 15 minutes)
        if (time() - (int)$timestamp > 900) {
            return false;
        }

        // Verify expected tenant and platform
        if ((int)$tenantId !== $expectedTenantId || strtolower($platform) !== strtolower($expectedPlatform)) {
            return false;
        }

        // Verify HMAC signature
        $data = "{$tenantId}|{$platform}|{$timestamp}|{$nonce}";
        $secret = defined('AUTH_SECRET_KEY') ? AUTH_SECRET_KEY : 'finens_social_oauth_salt';
        $expectedSignature = hash_hmac('sha256', $data, $secret);

        if (!hash_equals($expectedSignature, $signature)) {
            return false;
        }

        // Check against session if available
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['oauth_state_' . $platform])) {
            $sessionState = $_SESSION['oauth_state_' . $platform];
            unset($_SESSION['oauth_state_' . $platform]);
            unset($_SESSION['oauth_state_time_' . $platform]);
            if (!hash_equals($sessionState, $state)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Generate PKCE code verifier and code challenge for platforms requiring it (TikTok, etc.)
     */
    public static function generatePkce(): array {
        $verifierBytes = random_bytes(32);
        $verifier = rtrim(strtr(base64_encode($verifierBytes), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['oauth_pkce_verifier'] = $verifier;
        }

        return [
            'code_verifier' => $verifier,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256'
        ];
    }

    /**
     * Retrieve and clear stored PKCE code verifier from session
     */
    public static function getPkceVerifier(): ?string {
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['oauth_pkce_verifier'])) {
            $val = $_SESSION['oauth_pkce_verifier'];
            unset($_SESSION['oauth_pkce_verifier']);
            return $val;
        }
        return null;
    }

    /**
     * Recursively sanitize payload by redacting sensitive keys before logging or displaying
     */
    public static function sanitizePayload(mixed $data): string {
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return json_encode(self::sanitizeArray($decoded), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            }
            return $data;
        }

        if (is_array($data)) {
            return json_encode(self::sanitizeArray($data), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }

        return json_encode($data);
    }

    private static function sanitizeArray(array $arr): array {
        $sensitiveKeys = [
            'access_token', 'refresh_token', 'token', 'client_secret',
            'app_secret', 'secret', 'password', 'code_verifier',
            'code', 'auth_token', 'authorization'
        ];

        foreach ($arr as $key => $value) {
            $keyLower = strtolower((string)$key);
            if (in_array($keyLower, $sensitiveKeys, true)) {
                $arr[$key] = '[REDACTED_SECRET]';
            } elseif (is_array($value)) {
                $arr[$key] = self::sanitizeArray($value);
            }
        }

        return $arr;
    }

    /**
     * Resolves the current application base URL for public callbacks and media access
     */
    public static function getAppBaseUrl(?PDO $db = null): string {
        if ($db) {
            try {
                $stmt = $db->query("SELECT setting_value FROM social_settings WHERE tenant_id IS NULL AND setting_key = 'social_app_public_url' LIMIT 1");
                $custom = $stmt->fetchColumn();
                if (!empty($custom) && filter_var($custom, FILTER_VALIDATE_URL)) {
                    return rtrim($custom, '/');
                }
            } catch (Exception $e) {}
        }

        // Auto-detect from environment
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                   (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ||
                   (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

        $protocol = $isHttps ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        
        // Strip script path to get directory
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $dir = rtrim(dirname($script), '/\\');
        if ($dir === '.' || $dir === '') {
            $dir = '';
        }

        return rtrim($protocol . $host . $dir, '/');
    }
}
