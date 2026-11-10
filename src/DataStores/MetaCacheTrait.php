<?php

namespace Vendidero\Shiptastic\DataStores;

defined( 'ABSPATH' ) || exit;

/**
 * Prevents loading meta data twice for custom data stores. By default, internal props are read via `get_metadata()`
 * (which loads the WP meta cache) while `WC_Data::read_meta_data()` queries the meta table again to retrieve meta ids.
 *
 * This trait loads all meta rows (including meta ids) at once, seeds the WP meta cache and
 * re-uses the rows while reading the object meta data. Allows priming meta data for multiple objects within a single query.
 */
trait MetaCacheTrait {

	/**
	 * Raw meta rows, grouped by meta type and object id.
	 *
	 * @var array
	 */
	protected static $raw_meta_rows = array();

	/**
	 * Meta types for which invalidation hooks have been registered.
	 *
	 * @var array
	 */
	protected static $meta_invalidation_hooks = array();

	/**
	 * Make sure primed meta rows are discarded as soon as meta data of an object changes.
	 *
	 * @param string $meta_type
	 */
	protected static function register_meta_invalidation_hooks( $meta_type ) {
		if ( isset( self::$meta_invalidation_hooks[ $meta_type ] ) ) {
			return;
		}

		self::$meta_invalidation_hooks[ $meta_type ] = true;

		$invalidate = function ( $meta_id, $object_id ) use ( $meta_type ) {
			unset( self::$raw_meta_rows[ $meta_type ][ absint( $object_id ) ] );
		};

		foreach ( array( 'added', 'updated', 'deleted' ) as $action ) {
			add_action( "{$action}_{$meta_type}_meta", $invalidate, 1, 2 );
		}
	}

	/**
	 * Load meta data for multiple objects at once.
	 *
	 * @param int[] $object_ids
	 */
	public function prime_meta_cache( $object_ids ) {
		global $wpdb;

		$meta_type  = $this->meta_type;

		self::register_meta_invalidation_hooks( $meta_type );

		$object_ids = array_unique( array_filter( array_map( 'absint', (array) $object_ids ) ) );

		if ( ! isset( self::$raw_meta_rows[ $meta_type ] ) ) {
			self::$raw_meta_rows[ $meta_type ] = array();
		}

		$object_ids = array_values(
			array_filter(
				$object_ids,
				function ( $object_id ) use ( $meta_type ) {
					return ! isset( self::$raw_meta_rows[ $meta_type ][ $object_id ] ) || false === wp_cache_get( $object_id, "{$meta_type}_meta" );
				}
			)
		);

		if ( empty( $object_ids ) ) {
			return;
		}

		$db_info = $this->get_db_info();
		$rows    = $wpdb->get_results(
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			"SELECT {$db_info['object_id_field']} as object_id, {$db_info['meta_id_field']} as meta_id, meta_key, meta_value
			FROM {$db_info['table']}
			WHERE {$db_info['object_id_field']} IN (" . implode( ',', $object_ids ) . ")
			ORDER BY {$db_info['meta_id_field']} ASC"
			// phpcs:enable
		);

		$raw_rows   = array_fill_keys( $object_ids, array() );
		$meta_cache = array_fill_keys( $object_ids, array() );

		foreach ( $rows as $row ) {
			$object_id = absint( $row->object_id );

			if ( ! isset( $raw_rows[ $object_id ] ) ) {
				continue;
			}

			$raw_rows[ $object_id ][] = (object) array(
				'meta_id'    => $row->meta_id,
				'meta_key'   => $row->meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $row->meta_value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			);

			if ( ! isset( $meta_cache[ $object_id ][ $row->meta_key ] ) ) {
				$meta_cache[ $object_id ][ $row->meta_key ] = array();
			}

			$meta_cache[ $object_id ][ $row->meta_key ][] = $row->meta_value;
		}

		foreach ( $object_ids as $object_id ) {
			/**
			 * Always override the meta cache as the rows were just retrieved from DB.
			 */
			wp_cache_set( $object_id, $meta_cache[ $object_id ], "{$meta_type}_meta" );

			self::$raw_meta_rows[ $meta_type ][ $object_id ] = $raw_rows[ $object_id ];
		}
	}

	/**
	 * Read meta data rows for an object. Re-uses primed rows (once) in case the WP meta cache
	 * has not been invalidated in the meantime (e.g. by adding/updating/deleting meta).
	 *
	 * @param \WC_Data $data_object
	 *
	 * @return array
	 */
	public function read_meta( &$data_object ) {
		$meta_type = $this->meta_type;
		$object_id = absint( $data_object->get_id() );

		if ( $object_id && isset( self::$raw_meta_rows[ $meta_type ][ $object_id ] ) ) {
			$raw_meta_data = self::$raw_meta_rows[ $meta_type ][ $object_id ];
			unset( self::$raw_meta_rows[ $meta_type ][ $object_id ] );

			if ( false !== wp_cache_get( $object_id, "{$meta_type}_meta" ) ) {
				return $this->filter_raw_meta_data( $data_object, $raw_meta_data );
			}
		}

		return parent::read_meta( $data_object );
	}

	/**
	 * Remove primed meta rows for an object, e.g. after updating the object.
	 *
	 * @param int $object_id
	 */
	protected function clear_primed_meta( $object_id ) {
		unset( self::$raw_meta_rows[ $this->meta_type ][ absint( $object_id ) ] );
		unset( self::$primed_rows[ $this->meta_type ][ absint( $object_id ) ] );
	}

	/**
	 * Main table rows which have already been retrieved, e.g. via a query, to
	 * prevent re-querying the same row while constructing the object. Request-local only.
	 *
	 * @var array
	 */
	protected static $primed_rows = array();

	/**
	 * Prime main table rows (e.g. retrieved via a query) to be used (once) while reading the corresponding objects.
	 *
	 * @param object[] $rows
	 * @param string $id_field
	 */
	public function prime_rows( $rows, $id_field ) {
		if ( ! isset( self::$primed_rows[ $this->meta_type ] ) ) {
			self::$primed_rows[ $this->meta_type ] = array();
		}

		foreach ( (array) $rows as $row ) {
			if ( is_object( $row ) && isset( $row->{$id_field} ) ) {
				self::$primed_rows[ $this->meta_type ][ absint( $row->{$id_field} ) ] = $row;
			}
		}
	}

	/**
	 * Retrieve (and remove) a primed main table row.
	 *
	 * @param int $object_id
	 *
	 * @return object|false
	 */
	protected function get_primed_row( $object_id ) {
		$object_id = absint( $object_id );

		if ( isset( self::$primed_rows[ $this->meta_type ][ $object_id ] ) ) {
			$row = self::$primed_rows[ $this->meta_type ][ $object_id ];
			unset( self::$primed_rows[ $this->meta_type ][ $object_id ] );

			return $row;
		}

		return false;
	}
}
