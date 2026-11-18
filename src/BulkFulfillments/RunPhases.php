<?php

namespace Vendidero\Shiptastic\BulkFulfillments;

defined( 'ABSPATH' ) || exit;

/**
 * Phases a workflow step runs in (step setting `phase`, actions declare the phases they support via
 * Action::get_phases()):
 *
 * - before_queue (start of batch): once per batch of orders claimed by a run, before the orders are worked on
 *   (e.g. one picking list for the batch). Run level, runs automatically.
 * - item (per order): the regular steps, once per order or per shipment.
 * - after_shipment (after each shipment): once per shipment, right after the shipment's steps (shipments are
 *   processed one after another: Pack 1 → Print label 1 → Pack 2 → Print label 2).
 * - after_item (after each order): once per order, after all its other steps.
 * - after_queue (end of run): once when the run ends, over the orders completed within the run (also for stopped or
 *   cancelled runs, skipped in case no order was completed, e.g. a shipment list). Run level, runs automatically.
 */
class RunPhases {

	const BEFORE_QUEUE = 'before_queue';

	const ITEM = 'item';

	const AFTER_SHIPMENT = 'after_shipment';

	const AFTER_ITEM = 'after_item';

	const AFTER_QUEUE = 'after_queue';

	/**
	 * @return array phase => title, in execution order
	 */
	public static function get_phases() {
		return array(
			self::BEFORE_QUEUE   => _x( 'Start of batch', 'shipments-phase', 'shiptastic-for-woocommerce' ),
			self::ITEM           => _x( 'Per order', 'shipments-phase', 'shiptastic-for-woocommerce' ),
			self::AFTER_SHIPMENT => _x( 'After each shipment', 'shipments-phase', 'shiptastic-for-woocommerce' ),
			self::AFTER_ITEM     => _x( 'After each order', 'shipments-phase', 'shiptastic-for-woocommerce' ),
			self::AFTER_QUEUE    => _x( 'End of run', 'shipments-phase', 'shiptastic-for-woocommerce' ),
		);
	}

	/**
	 * @return array phase => description (workflow editor)
	 */
	public static function get_descriptions() {
		return array(
			self::BEFORE_QUEUE   => _x( 'Runs once for each batch of orders before they are worked on, e.g. one picking list for the batch.', 'shipments', 'shiptastic-for-woocommerce' ),
			self::ITEM           => _x( 'Runs for each order (or each shipment).', 'shipments', 'shiptastic-for-woocommerce' ),
			self::AFTER_SHIPMENT => _x( 'Runs for each shipment right after its steps, before the next shipment.', 'shipments', 'shiptastic-for-woocommerce' ),
			self::AFTER_ITEM     => _x( 'Runs for each order after all its other steps.', 'shipments', 'shiptastic-for-woocommerce' ),
			self::AFTER_QUEUE    => _x( 'Runs once at the end of the run for the orders completed within it, e.g. a shipment list.', 'shipments', 'shiptastic-for-woocommerce' ),
		);
	}

	public static function exists( $phase ) {
		return array_key_exists( $phase, self::get_phases() );
	}

	public static function get_title( $phase ) {
		$phases = self::get_phases();

		return isset( $phases[ $phase ] ) ? $phases[ $phase ] : $phase;
	}

	/**
	 * @param string $phase
	 *
	 * @return bool Whether steps of the phase run once per run/batch (not per order).
	 */
	public static function is_run_level( $phase ) {
		return in_array( $phase, array( self::BEFORE_QUEUE, self::AFTER_QUEUE ), true );
	}

	/**
	 * @param string $phase
	 *
	 * @return int Position of the phase within the execution order.
	 */
	public static function get_rank( $phase ) {
		$rank = array_search( $phase, array_keys( self::get_phases() ), true );

		return false === $rank ? 1 : $rank;
	}

	/**
	 * @param string $phase
	 *
	 * @return string|null The scope steps of the phase run in (null: no fixed scope, run level phases: run).
	 */
	public static function get_fixed_scope( $phase ) {
		if ( self::AFTER_SHIPMENT === $phase ) {
			return 'shipment';
		} elseif ( self::AFTER_ITEM === $phase ) {
			return 'order';
		} elseif ( self::is_run_level( $phase ) ) {
			return 'run';
		}

		return null;
	}
}
