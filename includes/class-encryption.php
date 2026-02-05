<?php
/**
 * Utilidades de encriptación para credenciales.
 *
 * @package Maxima_Integrations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Maxima_Integrations_Crypto {
	/**
	 * Cifra un valor usando AES-256-CBC.
	 *
	 * @param string $plaintext Valor a cifrar.
	 * @return string|null
	 */
	public static function encrypt( $plaintext ) {
		if ( '' === $plaintext ) {
			return null;
		}

		$key = self::get_encryption_key();
		if ( ! $key ) {
			return null;
		}

		$iv_length = openssl_cipher_iv_length( 'aes-256-cbc' );
		$iv        = random_bytes( $iv_length );
		$cipher    = openssl_encrypt( $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $cipher ) {
			return null;
		}

		return base64_encode( $iv . $cipher );
	}

	/**
	 * Descifra un valor cifrado.
	 *
	 * @param string $ciphertext Valor cifrado.
	 * @return string|null
	 */
	public static function decrypt( $ciphertext ) {
		if ( '' === $ciphertext ) {
			return null;
		}

		$key = self::get_encryption_key();
		if ( ! $key ) {
			return null;
		}

		$decoded = base64_decode( $ciphertext, true );
		if ( false === $decoded ) {
			return null;
		}

		$iv_length = openssl_cipher_iv_length( 'aes-256-cbc' );
		$iv        = substr( $decoded, 0, $iv_length );
		$cipher    = substr( $decoded, $iv_length );
		$plain     = openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

		return false === $plain ? null : $plain;
	}

	/**
	 * Genera la clave de cifrado a partir de constantes de WordPress.
	 *
	 * @return string|null
	 */
	private static function get_encryption_key() {
		$source = AUTH_KEY;
		if ( defined( 'SECURE_AUTH_KEY' ) && SECURE_AUTH_KEY ) {
			$source .= SECURE_AUTH_KEY;
		}

		if ( ! $source ) {
			return null;
		}

		return hash( 'sha256', $source, true );
	}
}
