<?php
/**
 * Config loader — reads JSON config files from the config/ directory.
 *
 * Mirrors the Petstablished\Core\Config pattern: a static singleton that
 * caches decoded JSON keyed by filename, with dot-notation access.
 *
 * @package Shelter_Events\Core
 */

declare( strict_types=1 );

namespace Shelter_Events\Core;

final class Config {

	/** @var array<string, array<string, mixed>> Cached config keyed by file basename (without .json). */
	private static array $cache = [];

	/** @var string Absolute path to the config/ directory. */
	private static string $dir = '';

	/**
	 * Initialize the config loader.
	 *
	 * @param string $config_dir Absolute path to the config directory (with trailing slash).
	 */
	public static function init( string $config_dir ): void {
		self::$dir   = trailingslashit( $config_dir );
		self::$cache = [];
	}

	/**
	 * Get an entire config file as an associative array.
	 *
	 * @param string $file Basename without extension (e.g. 'events', 'taxonomies').
	 * @return array<string, mixed>
	 */
	public static function get( string $file ): array {
		if ( ! isset( self::$cache[ $file ] ) ) {
			$path = self::$dir . $file . '.json';

			if ( ! file_exists( $path ) ) {
				self::$cache[ $file ] = [];
				return [];
			}

			$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$decoded = json_decode( $raw, true, 512, JSON_THROW_ON_ERROR );
			self::$cache[ $file ] = is_array( $decoded ) ? $decoded : [];
		}

		return self::$cache[ $file ];
	}

	/**
	 * Get a specific top-level key from a config file with an optional default.
	 *
	 * @param string $file    Config filename (without .json).
	 * @param string $key     Top-level key.
	 * @param mixed  $default Fallback value.
	 * @return mixed
	 */
	public static function get_item( string $file, string $key, mixed $default = null ): mixed {
		$config = self::get( $file );
		return $config[ $key ] ?? $default;
	}

	/**
	 * Retrieve a deeply nested value using dot notation.
	 *
	 * Example: Config::dot( 'events', 'programs.bingo.recurrence.days' )
	 *
	 * @param string $file Config filename.
	 * @param string $path Dot-separated path.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function dot( string $file, string $path, mixed $default = null ): mixed {
		$config  = self::get( $file );
		$segments = explode( '.', $path );

		$current = $config;
		foreach ( $segments as $segment ) {
			if ( ! is_array( $current ) || ! array_key_exists( $segment, $current ) ) {
				return $default;
			}
			$current = $current[ $segment ];
		}

		return $current;
	}

	/**
	 * Flush the config cache (useful in tests).
	 */
	public static function flush(): void {
		self::$cache = [];
	}
}
