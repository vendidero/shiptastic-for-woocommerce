<?php

namespace Vendidero\Shiptastic\BulkFulfillments;

defined( 'ABSPATH' ) || exit;

/**
 * Plans and executes the steps of a workflow for a queue item.
 *
 * - Step plan: the workflow's steps in order, dependencies (Action::get_requires()) not done by any workflow are
 *   injected before the step requiring them. Shipment scope steps are expanded per (unshipped) package shipment.
 * - Done state is read from the shared `StepLog` (repeatable actions only count when done by the same workflow).
 * - Background steps run directly, interactive steps return the view data and process submitted data.
 * - Once no step is left, the item is released (pending for other workflows, or done when shipped).
 */
class Runner {

	/**
	 * Max number of steps executed within one call (protects against misbehaving actions).
	 */
	const MAX_STEPS_PER_CALL = 50;

	/**
	 * @param QueueItem $item
	 *
	 * @return \Vendidero\Shiptastic\SimpleShipment[] Unshipped shipments of the item's package.
	 */
	public static function get_package_shipments( $item ) {
		$order_shipment = $item->get_shipment_order();
		$shipments      = array();

		if ( ! $order_shipment ) {
			return $shipments;
		}

		foreach ( $order_shipment->get_simple_shipments() as $shipment ) {
			if ( ! $shipment->is_shipped() && QueueSync::get_package_key( $shipment->get_location(), $shipment->get_shipping_method() ) === $item->get_package_key() ) {
				$shipments[ $shipment->get_id() ] = $shipment;
			}
		}

		return $shipments;
	}

	/**
	 * @param QueueItem $item
	 *
	 * @return array action => shipment_id => entry
	 */
	protected static function get_log( $item ) {
		$log = array();

		foreach ( StepLog::get_entries( $item->get_id() ) as $entry ) {
			$log[ $entry['action'] ][ $entry['shipment_id'] ] = $entry;
		}

		return $log;
	}

	/**
	 * @param array $log
	 * @param string $action_name
	 * @param int $shipment_id
	 * @param Workflow $workflow
	 *
	 * @return bool
	 */
	protected static function is_done( $log, $action_name, $shipment_id, $workflow ) {
		if ( ! isset( $log[ $action_name ][ $shipment_id ] ) ) {
			return false;
		}

		$entry  = $log[ $action_name ][ $shipment_id ];
		$action = ActionRegistry::get( $action_name );

		if ( ! in_array( $entry['status'], array( 'done', 'skipped' ), true ) ) {
			return false;
		}

		if ( $action && $action::is_repeatable() && $entry['workflow_id'] !== $workflow->get_id() ) {
			return false;
		}

		return true;
	}

	/**
	 * Plans the steps of a workflow for a queue item.
	 *
	 * @param QueueItem $item
	 * @param Workflow $workflow
	 *
	 * @return Step[]
	 */
	public static function get_steps( $item, $workflow ) {
		$log       = self::get_log( $item );
		$shipments = self::get_package_shipments( $item );
		$steps     = array();
		$planned   = array();

		$plan = function ( $action_name, $settings, $injected, $depth = 0 ) use ( &$plan, &$steps, &$planned, $log, $shipments, $workflow ) {
			$action = ActionRegistry::get( $action_name );

			if ( ! $action || isset( $planned[ $action_name ] ) || $depth > 10 ) {
				return;
			}

			$planned[ $action_name ] = true;

			foreach ( $action::get_requires() as $required ) {
				if ( isset( $planned[ $required ] ) || self::is_done( $log, $required, 0, $workflow ) ) {
					continue;
				}

				$required_settings = $workflow->get_step_settings( $required );
				$plan( $required, is_null( $required_settings ) ? array() : $required_settings, is_null( $required_settings ), $depth + 1 );
			}

			$settings = (array) $settings;
			$mode     = $workflow->get_step_mode( $action_name, $settings, $injected );
			$scope    = ( isset( $settings['scope'] ) && $action::supports_scope( $settings['scope'] ) ) ? $settings['scope'] : $action::get_default_scope();
			$targets  = 'shipment' === $scope && ! empty( $shipments ) ? array_keys( $shipments ) : array( 0 );

			foreach ( $targets as $shipment_id ) {
				$steps[] = new Step(
					array(
						'action'      => $action_name,
						'shipment_id' => $shipment_id,
						'mode'        => $mode,
						'settings'    => array_merge( $settings, array( 'scope' => $scope ) ),
						'injected'    => $injected,
						'is_done'     => self::is_done( $log, $action_name, $shipment_id, $workflow ),
					)
				);
			}
		};

		foreach ( $workflow->get_steps() as $step ) {
			$plan( $step['action'], $step['settings'], false );
		}

		/**
		 * Filter to adjust the planned steps of a workflow for a queue item.
		 *
		 * @param Step[]    $steps
		 * @param QueueItem $item
		 * @param Workflow  $workflow
		 *
		 * @package Vendidero/Shiptastic
		 */
		return apply_filters( 'woocommerce_shiptastic_fulfillment_steps', $steps, $item, $workflow );
	}

	/**
	 * @param QueueItem $item
	 * @param Workflow $workflow
	 *
	 * @return Step|null
	 */
	public static function get_next_step( $item, $workflow ) {
		foreach ( self::get_steps( $item, $workflow ) as $step ) {
			if ( ! $step->is_done ) {
				return $step;
			}
		}

		return null;
	}

	protected static function refresh_order_cache( $order_id ) {
		if ( $cache = \Vendidero\Shiptastic\Caches\Helper::get_cache_object( 'shipment-orders' ) ) {
			$cache->remove( $order_id );
		}
	}

	/**
	 * Executes the workflow for a locked queue item: runs background steps until an interactive step needs input,
	 * an error occurs or no step is left (the item is released then).
	 *
	 * @param int $queue_id
	 * @param Workflow $workflow
	 * @param string $token The lock token.
	 * @param array $args source (interactive|background|scheduled), step (key of the step the data belongs to), data
	 *
	 * @return array status (completed|needs_input|error), step (Step|null), message, code, view_data, version
	 */
	public static function execute( $queue_id, $workflow, $token, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'source' => 'interactive',
				'step'   => '',
				'data'   => null,
			)
		);

		$result = array(
			'status'    => 'error',
			'step'      => null,
			'message'   => '',
			'code'      => '',
			'view_data' => array(),
			'version'   => 0,
		);

		for ( $i = 0; $i < self::MAX_STEPS_PER_CALL; $i++ ) {
			$verified = Queue::verify_lock( $queue_id, $token );

			if ( is_wp_error( $verified ) ) {
				return array_merge(
					$result,
					array(
						'message' => $verified->get_error_message(),
						'code'    => $verified->get_error_code(),
					)
				);
			}

			$item = Queue::get( $queue_id );
			self::refresh_order_cache( $item->get_order_id() );

			$result['version'] = $item->get_version();
			$step              = self::get_next_step( $item, $workflow );

			if ( ! $step ) {
				Queue::release( $queue_id, $token );

				return array_merge( $result, array( 'status' => 'completed' ) );
			}

			$action  = $step->get_action();
			$context = new StepContext( $item, $workflow, $step, $args['source'], $token );

			$result['step'] = $step;

			if ( 'background' === $step->mode ) {
				$step_result = $action->run( $context );
			} elseif ( 'interactive' !== $args['source'] ) {
				return array_merge(
					$result,
					array(
						'status'  => 'needs_input',
						/* translators: %s action title */
						'message' => sprintf( _x( '%s requires user interaction.', 'shipments', 'shiptastic-for-woocommerce' ), $action::get_title() ),
						'code'    => 'requires_interaction',
					)
				);
			} elseif ( ! is_null( $args['data'] ) && $args['step'] === $step->get_key() ) {
				$step_result  = $action->process( $context, (array) $args['data'] );
				$args['data'] = null;
			} else {
				return array_merge(
					$result,
					array(
						'status'    => 'needs_input',
						'view_data' => $action->get_view_data( $context ),
					)
				);
			}

			if ( ! is_a( $step_result, '\Vendidero\Shiptastic\BulkFulfillments\StepResult' ) ) {
				$step_result = StepResult::error( _x( 'The action returned an invalid result.', 'shipments', 'shiptastic-for-woocommerce' ) );
			}

			StepLog::record(
				$queue_id,
				$step->action,
				array(
					'status'      => $step_result->get_status(),
					'shipment_id' => $step->shipment_id,
					'data'        => $step_result->get_data(),
					'workflow_id' => $workflow->get_id(),
					'error'       => $step_result->is_error() ? $step_result->get_message() : '',
				)
			);

			if ( $step_result->is_error() ) {
				return array_merge(
					$result,
					array(
						'message' => $step_result->get_message(),
						'code'    => $step_result->get_code(),
					)
				);
			}

			self::maybe_record_rollup( $item, $workflow, $step );
			Queue::bump_version( $queue_id, $token );
		}

		return array_merge(
			$result,
			array(
				'message' => _x( 'Too many steps executed.', 'shipments', 'shiptastic-for-woocommerce' ),
				'code'    => 'too_many_steps',
			)
		);
	}

	/**
	 * Shipment scope actions are tracked per shipment. Once all package shipments are done, an order scope entry is
	 * recorded as well (used to tell whether the item still has open steps for a workflow when claiming).
	 *
	 * @param QueueItem $item
	 * @param Workflow $workflow
	 * @param Step $step
	 */
	protected static function maybe_record_rollup( $item, $workflow, $step ) {
		if ( ! $step->shipment_id ) {
			return;
		}

		$log = self::get_log( $item );

		foreach ( array_keys( self::get_package_shipments( $item ) ) as $shipment_id ) {
			if ( ! self::is_done( $log, $step->action, $shipment_id, $workflow ) ) {
				return;
			}
		}

		StepLog::record(
			$item->get_id(),
			$step->action,
			array(
				'status'      => 'done',
				'workflow_id' => $workflow->get_id(),
			)
		);
	}

	/**
	 * Processes a chunk of queue items in the background.
	 *
	 * - scheduled: errors are handled per the workflow's on_error setting (continue: item to the exception inbox,
	 *   next item; stop: item to the inbox, workflow schedule paused).
	 * - background (started by a user): stops at the first failed item and keeps it locked for the user, who decides
	 *   to retry, skip (inbox) or abort.
	 *
	 * @param Workflow $workflow
	 * @param array $args source (background|scheduled), limit, user_id
	 *
	 * @return array claimed, processed, completed, errors, stopped, failed (queue id), message, token
	 */
	public static function process_chunk( $workflow, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'source'  => 'scheduled',
				'limit'   => null,
				'user_id' => get_current_user_id(),
			)
		);

		$source = in_array( $args['source'], array( 'background', 'scheduled' ), true ) ? $args['source'] : 'scheduled';
		$items  = Queue::claim(
			$workflow,
			array(
				'source'  => $source,
				'limit'   => $args['limit'],
				'user_id' => $args['user_id'],
			)
		);

		$result = array(
			'claimed'   => count( $items ),
			'processed' => 0,
			'completed' => 0,
			'errors'    => 0,
			'stopped'   => false,
			'failed'    => 0,
			'message'   => '',
			'token'     => empty( $items ) ? '' : $items[0]->get_lock_token(),
		);

		foreach ( $items as $item ) {
			$execution = self::execute( $item->get_id(), $workflow, $result['token'], array( 'source' => $source ) );

			++$result['processed'];

			if ( 'completed' === $execution['status'] ) {
				++$result['completed'];
				continue;
			}

			++$result['errors'];

			if ( 'background' === $source ) {
				// Keep the item locked for the user.
				$result['stopped'] = true;
				$result['failed']  = $item->get_id();
				$result['message'] = $execution['message'];
				break;
			}

			Queue::mark_error( $item->get_id(), $result['token'], $execution['message'] );

			if ( 'stop' === $workflow->get_on_error() ) {
				$result['stopped'] = true;
				$result['failed']  = $item->get_id();
				$result['message'] = $execution['message'];

				/* translators: 1: order number 2: error message */
				Scheduler::pause( $workflow, sprintf( _x( 'Order #%1$s: %2$s', 'shipments', 'shiptastic-for-woocommerce' ), $item->get_order() ? $item->get_order()->get_order_number() : $item->get_order_id(), $execution['message'] ) );
				break;
			}
		}

		// Release items not processed (e.g. after stopping).
		foreach ( Queue::get_by_token( $result['token'] ) as $locked ) {
			if ( $locked->get_id() !== $result['failed'] || 'background' !== $source ) {
				Queue::release( $locked->get_id(), $result['token'] );
			}
		}

		/**
		 * Action that fires after a chunk of queue items has been processed in the background.
		 *
		 * @param array    $result
		 * @param Workflow $workflow
		 * @param string   $source
		 *
		 * @package Vendidero/Shiptastic
		 */
		do_action( 'woocommerce_shiptastic_fulfillment_chunk_processed', $result, $workflow, $source );

		return $result;
	}

	/**
	 * Moves a locked item to the exception inbox (e.g. the user chose to skip a failed item).
	 *
	 * @param int $queue_id
	 * @param string $token
	 * @param string $message
	 *
	 * @return true|\WP_Error
	 */
	public static function skip( $queue_id, $token, $message = '' ) {
		if ( empty( $message ) ) {
			$message = _x( 'Skipped by user.', 'shipments', 'shiptastic-for-woocommerce' );
		}

		return Queue::mark_error( $queue_id, $token, $message );
	}
}
