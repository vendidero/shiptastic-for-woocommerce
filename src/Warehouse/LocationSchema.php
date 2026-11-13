<?php

namespace Vendidero\Shiptastic\Warehouse;

defined( 'ABSPATH' ) || exit;

/**
 * Describes how storage location codes within a warehouse are structured (levels, e.g. Zone → Aisle → Rack → Bin)
 * and converts location codes into a pick sequence: a string which, sorted ascending, orders locations along the
 * walking path through the warehouse.
 *
 * Schema data:
 * - separator: Separates the levels within a code, e.g. `-` for `A-03-2-B`
 * - levels: Ordered list of levels, each with
 *   - name: e.g. Aisle
 *   - type: numeric|alpha|list
 *   - direction: asc|desc
 *   - snake: Reverse the direction for every other value of the previous level (serpentine walking path)
 *   - values: Explicitly ordered values (in case the type is list), e.g. zones or floors
 * - overrides: location code => code to sort as, for locations which do not follow the pattern
 *
 * Without levels, codes are sorted naturally (digit sequences compared numerically).
 */
class LocationSchema {

	const SEGMENT_LENGTH = 6;

	/**
	 * Encoded value used for unknown/invalid segments. Sorts last.
	 */
	const UNKNOWN = 999999;

	/**
	 * Largest encodable value (keeps UNKNOWN last, also when reversing).
	 */
	const MAX = 999998;

	protected $data = array();

	public function __construct( $data = array() ) {
		$this->data = self::sanitize( $data );
	}

	/**
	 * Default schema for new warehouses: aisles, walked in a serpentine path through the racks.
	 *
	 * @return array
	 */
	public static function get_default_data() {
		return apply_filters(
			'woocommerce_shiptastic_default_warehouse_location_schema',
			array(
				'separator' => '-',
				'levels'    => array(
					array(
						'name'      => _x( 'Aisle', 'shipments-location-level', 'shiptastic-for-woocommerce' ),
						'type'      => 'numeric',
						'direction' => 'asc',
						'snake'     => false,
						'values'    => array(),
					),
					array(
						'name'      => _x( 'Rack', 'shipments-location-level', 'shiptastic-for-woocommerce' ),
						'type'      => 'numeric',
						'direction' => 'asc',
						'snake'     => true,
						'values'    => array(),
					),
				),
				'overrides' => array(),
			)
		);
	}

	public static function get_level_types() {
		return array(
			'numeric' => _x( 'Number', 'shipments-location-level-type', 'shiptastic-for-woocommerce' ),
			'alpha'   => _x( 'Letters', 'shipments-location-level-type', 'shiptastic-for-woocommerce' ),
			'list'    => _x( 'List (custom order)', 'shipments-location-level-type', 'shiptastic-for-woocommerce' ),
		);
	}

	public static function get_directions() {
		return array(
			'asc'  => _x( 'Ascending', 'shipments-location-direction', 'shiptastic-for-woocommerce' ),
			'desc' => _x( 'Descending', 'shipments-location-direction', 'shiptastic-for-woocommerce' ),
		);
	}

	/**
	 * @param array $data
	 *
	 * @return array
	 */
	public static function sanitize( $data ) {
		$data = wp_parse_args(
			(array) $data,
			array(
				'separator' => '-',
				'levels'    => array(),
				'values'    => array(),
				'overrides' => array(),
			)
		);

		$separator = (string) $data['separator'];
		$levels    = array();

		foreach ( array_values( (array) $data['levels'] ) as $index => $level ) {
			$level = wp_parse_args(
				(array) $level,
				array(
					'name'      => '',
					'type'      => 'numeric',
					'direction' => 'asc',
					'snake'     => false,
					'values'    => array(),
				)
			);

			/**
			 * Legacy: list values used to be stored for the first level only.
			 */
			if ( 0 === $index && empty( $level['values'] ) && ! empty( $data['values'] ) ) {
				$level['values'] = $data['values'];
			}

			$type   = array_key_exists( $level['type'], self::get_level_types() ) ? $level['type'] : 'numeric';
			$values = array();

			if ( 'list' === $type ) {
				foreach ( (array) $level['values'] as $value ) {
					$value = self::normalize_segment( wc_clean( (string) $value ) );

					if ( '' !== $value && ! in_array( $value, $values, true ) ) {
						$values[] = $value;
					}
				}
			}

			$levels[] = array(
				'name'      => wc_clean( (string) $level['name'] ),
				'type'      => $type,
				'direction' => 'desc' === $level['direction'] ? 'desc' : 'asc',
				'snake'     => count( $levels ) > 0 && wc_string_to_bool( $level['snake'] ),
				'values'    => $values,
			);
		}

		$overrides = array();

		foreach ( (array) $data['overrides'] as $code => $sort_as ) {
			$code    = self::normalize_code( wc_clean( (string) $code ) );
			$sort_as = self::normalize_code( wc_clean( (string) $sort_as ) );

			if ( '' !== $code && '' !== $sort_as && $code !== $sort_as ) {
				$overrides[ $code ] = $sort_as;
			}
		}

		return array(
			'separator' => '' === $separator ? '-' : substr( $separator, 0, 3 ),
			'levels'    => $levels,
			'overrides' => $overrides,
		);
	}

	public function get_data() {
		return $this->data;
	}

	public function get_separator() {
		return $this->data['separator'];
	}

	public function get_levels() {
		return $this->data['levels'];
	}

	/**
	 * Returns the ordered values of a list level.
	 *
	 * @param int $index The level index.
	 *
	 * @return string[]
	 */
	public function get_level_values( $index ) {
		return isset( $this->data['levels'][ $index ] ) ? $this->data['levels'][ $index ]['values'] : array();
	}

	/**
	 * Returns a level's display name, e.g. for messages.
	 *
	 * @param int $index
	 *
	 * @return string
	 */
	public function get_level_name( $index ) {
		$levels = $this->get_levels();

		if ( isset( $levels[ $index ] ) && '' !== $levels[ $index ]['name'] ) {
			return $levels[ $index ]['name'];
		}

		/* translators: %d level number */
		return sprintf( _x( 'Level %d', 'shipments-location-level', 'shiptastic-for-woocommerce' ), $index + 1 );
	}

	public function get_overrides() {
		return $this->data['overrides'];
	}

	public function has_levels() {
		return ! empty( $this->data['levels'] );
	}

	public static function normalize_segment( $segment ) {
		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( trim( $segment ) ) : strtoupper( trim( $segment ) );
	}

	public static function normalize_code( $code ) {
		return self::normalize_segment( preg_replace( '/\s+/', ' ', (string) $code ) );
	}

	/**
	 * @param string $code
	 *
	 * @return string[]
	 */
	protected function split( $code ) {
		return array_map( array( __CLASS__, 'normalize_segment' ), explode( $this->get_separator(), $code ) );
	}

	/**
	 * Returns the ordinal (starting at 1) of a segment for a certain level or null in case the segment is invalid.
	 *
	 * @param string $segment
	 * @param array $level
	 * @param int $index
	 *
	 * @return int|null
	 */
	protected function get_ordinal( $segment, $level, $index ) {
		if ( 'list' === $level['type'] ) {
			$position = array_search( $segment, $level['values'], true );

			return false === $position ? null : $position + 1;
		} elseif ( 'alpha' === $level['type'] ) {
			if ( ! preg_match( '/^[A-Z]+$/', $segment ) ) {
				return null;
			}

			// Bijective base-26: A = 1, Z = 26, AA = 27
			$ordinal = 0;

			foreach ( str_split( $segment ) as $char ) {
				$ordinal = $ordinal * 26 + ( ord( $char ) - 64 );

				if ( $ordinal > self::MAX ) {
					return null;
				}
			}

			return $ordinal;
		} else {
			if ( ! preg_match( '/^\d+$/', $segment ) ) {
				return null;
			}

			$ordinal = (int) ltrim( $segment, '0' );

			return $ordinal > self::MAX ? null : $ordinal;
		}
	}

	protected static function pad( $value ) {
		return str_pad( (string) $value, self::SEGMENT_LENGTH, '0', STR_PAD_LEFT );
	}

	/**
	 * Converts a location code into its pick sequence (sortable string). Empty codes return an empty string.
	 *
	 * @param string $code
	 *
	 * @return string
	 */
	public function get_pick_sequence( $code ) {
		$code = self::normalize_code( $code );

		if ( '' === $code ) {
			return '';
		}

		if ( array_key_exists( $code, $this->get_overrides() ) ) {
			$code = $this->get_overrides()[ $code ];
		}

		if ( ! $this->has_levels() ) {
			return self::get_natural_sequence( $code );
		}

		$segments         = $this->split( $code );
		$sequence         = '';
		$previous_ordinal = null;

		foreach ( $this->get_levels() as $index => $level ) {
			$segment = isset( $segments[ $index ] ) ? $segments[ $index ] : '';

			/**
			 * Missing (trailing) segments sort first within their parent.
			 */
			if ( '' === $segment ) {
				$sequence        .= self::pad( 0 );
				$previous_ordinal = 0;
				continue;
			}

			$ordinal = $this->get_ordinal( $segment, $level, $index );

			if ( is_null( $ordinal ) ) {
				$sequence        .= self::pad( self::UNKNOWN );
				$previous_ordinal = null;
				continue;
			}

			$reverse = 'desc' === $level['direction'];

			/**
			 * Serpentine: walk every other value of the previous level (e.g. every second aisle) the other way round.
			 */
			if ( $level['snake'] && ! is_null( $previous_ordinal ) && $previous_ordinal > 0 && 0 === $previous_ordinal % 2 ) {
				$reverse = ! $reverse;
			}

			$sequence        .= self::pad( $reverse ? self::MAX - $ordinal : $ordinal );
			$previous_ordinal = $ordinal;
		}

		return $sequence;
	}

	/**
	 * Natural sort key: digit sequences are compared numerically, the rest case-insensitively.
	 *
	 * @param string $code
	 *
	 * @return string
	 */
	public static function get_natural_sequence( $code ) {
		$code = self::normalize_code( $code );

		return (string) preg_replace_callback(
			'/\d+/',
			function ( $matches ) {
				$number = ltrim( $matches[0], '0' );

				return str_pad( '' === $number ? '0' : $number, self::SEGMENT_LENGTH * 2, '0', STR_PAD_LEFT );
			},
			$code
		);
	}

	/**
	 * Validates a location code against the schema.
	 *
	 * @param string $code
	 *
	 * @return true|\WP_Error
	 */
	public function validate( $code ) {
		$code = self::normalize_code( $code );

		if ( '' === $code || ! $this->has_levels() || array_key_exists( $code, $this->get_overrides() ) ) {
			return true;
		}

		$segments = $this->split( $code );
		$levels   = $this->get_levels();

		if ( count( $segments ) > count( $levels ) ) {
			/* translators: 1: location code 2: number of levels */
			return new \WP_Error( 'location_too_many_levels', sprintf( _x( 'The storage location %1$s contains more than %2$d levels.', 'shipments', 'shiptastic-for-woocommerce' ), $code, count( $levels ) ) );
		}

		foreach ( $segments as $index => $segment ) {
			if ( is_null( $this->get_ordinal( $segment, $levels[ $index ], $index ) ) ) {
				$level_name = $this->get_level_name( $index );

				/* translators: 1: segment 2: level name 3: location code */
				return new \WP_Error( 'location_invalid_segment', sprintf( _x( '%1$s is not a valid value for %2$s (storage location %3$s).', 'shipments', 'shiptastic-for-woocommerce' ), $segment, $level_name, $code ) );
			}
		}

		return true;
	}

	/**
	 * Returns example codes covering a small grid of the levels (e.g. 2 values of the first level, 3 of the second,
	 * 2 of the third, 1 of deeper levels) to visualize the walking path incl. direction and snake.
	 * List levels use their first values (placeholders A, B, C in case no values are defined yet).
	 *
	 * @return string[]
	 */
	public function get_sample_codes() {
		if ( ! $this->has_levels() ) {
			return array( 'A-1', 'A-2', 'A-10', 'B-1' );
		}

		$counts = array( 2, 3, 2 );
		$codes  = array( array() );

		foreach ( $this->get_levels() as $index => $level ) {
			$count  = isset( $counts[ $index ] ) ? $counts[ $index ] : 1;
			$values = array();

			if ( 'list' === $level['type'] && ! empty( $level['values'] ) ) {
				$values = array_slice( $level['values'], 0, $count );
			} elseif ( 'list' === $level['type'] || 'alpha' === $level['type'] ) {
				$values = array_slice( array( 'A', 'B', 'C' ), 0, $count );
			} else {
				$values = range( 1, $count );
			}

			$next = array();

			foreach ( $codes as $code ) {
				foreach ( $values as $value ) {
					$next[] = array_merge( $code, array( (string) $value ) );
				}
			}

			$codes = $next;
		}

		$separator = $this->get_separator();

		return array_map(
			function ( $segments ) use ( $separator ) {
				return implode( $separator, $segments );
			},
			$codes
		);
	}

	/**
	 * Returns an example code for the schema, e.g. A-03-2-B.
	 *
	 * @return string
	 */
	public function get_example() {
		if ( ! $this->has_levels() ) {
			return 'A-03-2-B';
		}

		$segments = array();

		foreach ( $this->get_levels() as $index => $level ) {
			if ( 'list' === $level['type'] ) {
				$segments[] = ! empty( $level['values'] ) ? $level['values'][0] : 'A';
			} elseif ( 'alpha' === $level['type'] ) {
				$segments[] = 0 === $index % 2 ? 'A' : 'B';
			} else {
				$segments[] = 0 === $index % 2 ? '1' : '03';
			}
		}

		return implode( $this->get_separator(), $segments );
	}
}
