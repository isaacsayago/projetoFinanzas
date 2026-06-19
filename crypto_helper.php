<?php
/**
 * Funções de criptografia para dados sensíveis de cartões.
 * Algoritmo: AES-256-CBC via OpenSSL.
 */

// Chave de 32 bytes para AES-256. Em produção, mover para variável de ambiente.
if (!defined('FINANZAS_ENCRYPTION_KEY')) {
    define('FINANZAS_ENCRYPTION_KEY', 'F1n4nz4s#S3cur3K3y!2024@Pr0j3ct$');
}

define('FINANZAS_CIPHER', 'aes-256-cbc');

/**
 * Criptografa um texto plano.
 * @param string $plaintext Texto a criptografar
 * @return array ['ciphertext' => string, 'iv' => string] Dados binários
 */
function encryptData(string $plaintext): array {
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length(FINANZAS_CIPHER));
    $ciphertext = openssl_encrypt($plaintext, FINANZAS_CIPHER, FINANZAS_ENCRYPTION_KEY, OPENSSL_RAW_DATA, $iv);
    return ['ciphertext' => $ciphertext, 'iv' => $iv];
}

/**
 * Descriptografa dados criptografados.
 * @param string $ciphertext Dados criptografados (binário)
 * @param string $iv Vetor de inicialização (binário)
 * @return string|false Texto plano ou false em caso de falha
 */
function decryptData(string $ciphertext, string $iv) {
    return openssl_decrypt($ciphertext, FINANZAS_CIPHER, FINANZAS_ENCRYPTION_KEY, OPENSSL_RAW_DATA, $iv);
}

/**
 * Mascara um número de cartão, exibindo apenas os últimos 4 dígitos.
 * @param string|null $ultimos4 Últimos 4 dígitos
 * @return string Ex: "**** **** **** 1234"
 */
function maskCardNumber(?string $ultimos4): string {
    if (!$ultimos4) return '**** **** **** ****';
    return '**** **** **** ' . $ultimos4;
}
