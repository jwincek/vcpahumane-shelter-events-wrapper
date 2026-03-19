<?php
/**
 * Config-driven taxonomy registration.
 *
 * Reads taxonomies.json and registers custom taxonomies against TEC's
 * tribe_events post type, seeding default terms on first run.
 *
 * @package Shelter_Events\Core
 */

declare( strict_types=1 );

namespace Shelter_Events\Core;

final class Taxonomy_Registry {

	/**
	 * Hook into WordPress init.
	 */
	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register_taxonomies' ], 11 ); // After TEC registers its post type.
		add_action( 'init', [ __CLASS__, 'seed_default_terms' ], 12 );
	}

	/**
	 * Register taxonomies from config.
	 */
	public static function register_taxonomies(): void {
		$taxonomies = Config::get_item( 'taxonomies', 'taxonomies', [] );

		foreach ( $taxonomies as $slug => $definition ) {
			if ( taxonomy_exists( $slug ) ) {
				// Already registered — just attach to our post types.
				foreach ( $definition['post_types'] as $pt ) {
					register_taxonomy_for_object_type( $slug, $pt );
				}
				continue;
			}

			register_taxonomy(
				$slug,
				$definition['post_types'],
				$definition['args']
			);
		}
	}

	/**
	 * Insert default terms if they don't already exist.
	 */
	public static function seed_default_terms(): void {
		$taxonomies = Config::get_item( 'taxonomies', 'taxonomies', [] );

		foreach ( $taxonomies as $slug => $definition ) {
			if ( empty( $definition['default_terms'] ) ) {
				continue;
			}

			foreach ( $definition['default_terms'] as $term_def ) {
				if ( term_exists( $term_def['slug'], $slug ) ) {
					continue;
				}

				wp_insert_term( $term_def['name'], $slug, [
					'slug'        => $term_def['slug'],
					'description' => $term_def['description'] ?? '',
				] );
			}
		}
	}
}
