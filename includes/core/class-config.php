<?php
/**
 * Config loader — reads JSON config files from the config/ directory.
 *
 * In v2, config files provide schema defaults, generation settings, and
 * seed data for the importer. The shelter_program CPT is the live source
 * of truth for program definitions.
 *
 * @package Shelter_Events\Core
 */

declare( strict_types=1 );

namespace Shelter_Events\Core;

final class Config {

	private static array $cache = [];
	private static string $dir = '';

	public static function init( string $config_dir ): void {
		self::$dir   = trailingslashit( $config_dir );
		self::$cache = [];
	}

	public static function get( string $file ): array {
		if ( ! isset( self::$cache[ $file ] ) ) {
			$path = self::$dir . $file . '.json';

			if ( ! file_exists( $path ) ) {
				self::$cache[ $file ] = [];
				return [];
			}

			$raw     = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$decoded = json_decode( $raw, true, 512, JSON_THROW_ON_ERROR );
			self::$cache[ $file ] = is_array( $decoded ) ? $decoded : [];
		}

		return self::$cache[ $file ];
	}

	public static function get_item( string $file, string $key, mixed $default = null ): mixed {
		$config = self::get( $file );
		return $config[ $key ] ?? $default;
	}

	public static function dot( string $file, string $path, mixed $default = null ): mixed {
		$config   = self::get( $file );
		$segments = explode( '.', $path );
		$current  = $config;

		foreach ( $segments as $segment ) {
			if ( ! is_array( $current ) || ! array_key_exists( $segment, $current ) ) {
				return $default;
			}
			$current = $current[ $segment ];
		}

		return $current;
	}

	public static function flush(): void {
		self::$cache = [];
	}
}
