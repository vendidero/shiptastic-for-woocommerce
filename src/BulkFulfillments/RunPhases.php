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
 *   cancelled runs, skipped in case no order was completed, e.g. a carrier manifest). Run level, runs automatically.
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
			self::AFTER_SHIPMENT => _x( 'Per shipment', 'shipments-phase', 'shiptastic-for-woocommerce' ),
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
			self::ITEM           => _x( 'Runs once for each order: before the per shipment steps in case it is listed before them, after all shipments otherwise.', 'shipments', 'shiptastic-for-woocommerce' ),
			self::AFTER_SHIPMENT => _x( 'Runs for each shipment after its other steps, before the next shipment.', 'shipments', 'shiptastic-for-woocommerce' ),
			self::AFTER_ITEM     => _x( 'Runs for each order after all its other steps.', 'shipments', 'shiptastic-for-woocommerce' ),
			self::AFTER_QUEUE    => _x( 'Runs once at the end of the run for the orders completed within it, e.g. a carrier manifest.', 'shipments', 'shiptastic-for-woocommerce' ),
		);
	}

	/**
	 * Title + description of the item phase for shipment scope steps (each shipment instead of each order).
	 *
	 * @return array title, description
	 */
	public static function get_item_shipment_texts() {
		return array(
			'title'       => _x( 'Per shipment', 'shipments-phase', 'shiptastic-for-woocommerce' ),
			'description' => _x( 'Runs for each shipment of the order, one shipment after another together with the other per shipment steps, e.g. pack shipment 1 and create its label, then shipment 2.', 'shipments', 'shiptastic-for-woocommerce' ),
		);
	}

	/**
	 * The choices of when a step of an action runs (workflow editor), combining phase and scope, in execution order:
	 * start of batch, per order, per shipment, after each order, end of run. The item phase is offered per supported
	 * scope, after_shipment is not offered (per shipment steps already run shipment by shipment, saved after_shipment
	 * steps are presented as per shipment).
	 *
	 * @param string $action Action class name.
	 *
	 * @return array[] value (phase or item:{scope}), phase, scope, label, description, run_level
	 */
	public static function get_choices( $action ) {
		$descriptions = self::get_descriptions();
		$choices      = array();

		foreach ( array_keys( self::get_phases() ) as $phase ) {
			if ( self::AFTER_SHIPMENT === $phase || ! $action::supports_phase( $phase ) ) {
				continue;
			}

			if ( self::ITEM === $phase ) {
				foreach ( array( 'order', 'shipment' ) as $scope ) {
					if ( ! $action::supports_scope( $scope ) ) {
						continue;
					}

					$shipment  = self::get_item_shipment_texts();
					$choices[] = array(
						'value'       => self::ITEM . ':' . $scope,
						'phase'       => self::ITEM,
						'scope'       => $scope,
						'label'       => 'shipment' === $scope ? $shipment['title'] : self::get_title( self::ITEM ),
						'description' => 'shipment' === $scope ? $shipment['description'] : $descriptions[ self::ITEM ],
						'run_level'   => false,
					);
				}
			} else {
				$choices[] = array(
					'value'       => $phase,
					'phase'       => $phase,
					'scope'       => self::get_fixed_scope( $phase ),
					'label'       => self::get_title( $phase ),
					'description' => $descriptions[ $phase ],
					'run_level'   => self::is_run_level( $phase ),
				);
			}
		}

		// E.g. an action supporting after_shipment only.
		if ( empty( $choices ) && $action::supports_phase( self::AFTER_SHIPMENT ) ) {
			$shipment  = self::get_item_shipment_texts();
			$choices[] = array(
				'value'       => self::AFTER_SHIPMENT,
				'phase'       => self::AFTER_SHIPMENT,
				'scope'       => 'shipment',
				'label'       => $shipment['title'],
				'description' => $descriptions[ self::AFTER_SHIPMENT ],
				'run_level'   => false,
			);
		}

		return $choices;
	}

	/**
	 * @param string $action Action class name.
	 *
	 * @return string The default choice (see get_choices()): the default phase, combined with the default scope.
	 */
	public static function get_default_choice( $action ) {
		$phase = $action::get_default_phase();

		if ( self::ITEM === $phase ) {
			return self::ITEM . ':' . $action::get_default_scope();
		}

		return $phase;
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
