<?php

namespace Vendidero\Shiptastic\BulkFulfillments;

defined( 'ABSPATH' ) || exit;

/**
 * A run of a workflow: an interactive run (staff works through the orders of a workflow within the fulfillment app,
 * source interactive) or a run started by an extension (e.g. automation runs processed in the background). All
 * orders claimed by a run share the run's lock token, step results (e.g. files of the export) are attributed to it.
 *
 * Statuses: running, paused, stopped (stopped on an error), completed, cancelled.
 */
class Run {

	const STATUSES = array( 'running', 'paused', 'stopped', 'completed', 'cancelled' );

	const ACTIVE_STATUSES = array( 'running', 'paused', 'stopped' );

	protected $data = array();

	public function __construct( $row = null ) {
		$this->data = array(
			'id'               => 0,
			'workflow_id'      => 0,
			'mode'             => 'autonomous',
			'source'           => 'manual',
			'status'           => 'running',
			'user_id'          => 0,
			'total'            => 0,
			'processed'        => 0,
			'succeeded'        => 0,
			'failed'           => 0,
			'current_queue_id' => 0,
			'current_step'     => '',
			'lock_token'       => '',
			'message'          => '',
			// Export file (zip) of files created by the steps (see RunExport), relative to the export directory.
			'export'           => '',
			'export_files'     => 0,
			'dismissed'        => false,
			'date_created'     => null,
			'date_modified'    => null,
			'date_finished'    => null,
		);

		if ( is_object( $row ) ) {
			foreach ( (array) $row as $column => $value ) {
				$key = preg_replace( '/^run_/', '', $column );
				$key = preg_replace( '/_gmt$/', '', $key );

				if ( array_key_exists( $key, $this->data ) ) {
					$this->data[ $key ] = $value;
				}
			}

			foreach ( array( 'id', 'workflow_id', 'user_id', 'total', 'processed', 'succeeded', 'failed', 'current_queue_id', 'export_files' ) as $int ) {
				$this->data[ $int ] = absint( $this->data[ $int ] );
			}

			$this->data['dismissed'] = (bool) $this->data['dismissed'];
			$this->data['message']   = (string) $this->data['message'];
		}
	}

	public function __get( $key ) {
		return array_key_exists( $key, $this->data ) ? $this->data[ $key ] : null;
	}

	public function get_id() {
		return $this->data['id'];
	}

	public function get_data() {
		return $this->data;
	}

	public function is_active() {
		return in_array( $this->data['status'], self::ACTIVE_STATUSES, true );
	}

	public function is_finished() {
		return in_array( $this->data['status'], array( 'completed', 'cancelled' ), true );
	}

	/**
	 * @return Workflow|null
	 */
	public function get_workflow() {
		$workflow = new Workflow( $this->data['workflow_id'] );

		return $workflow->get_id() ? $workflow : null;
	}

	protected static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * @param array $args workflow_id, mode (autonomous|supervised|interactive), source (manual|scheduled|interactive),
	 *                    user_id, total, lock_token
	 *
	 * @return Run
	 */
	public static function create( $args ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'workflow_id' => 0,
				'mode'        => 'autonomous',
				'source'      => 'manual',
				'user_id'     => get_current_user_id(),
				'total'       => 0,
				'lock_token'  => '',
			)
		);

		$interactive = 'interactive' === $args['source'];

		$wpdb->insert(
			$wpdb->stc_fulfillment_runs,
			array(
				'run_workflow_id'       => absint( $args['workflow_id'] ),
				'run_mode'              => $interactive ? 'interactive' : ( 'supervised' === $args['mode'] ? 'supervised' : 'autonomous' ),
				'run_source'            => in_array( $args['source'], array( 'scheduled', 'interactive' ), true ) ? $args['source'] : 'manual',
				'run_lock_token'        => (string) $args['lock_token'],
				'run_status'            => 'running',
				'run_user_id'           => absint( $args['user_id'] ),
				'run_total'             => absint( $args['total'] ),
				'run_date_created_gmt'  => self::now(),
				'run_date_modified_gmt' => self::now(),
			)
		);

		return self::get( $wpdb->insert_id );
	}

	/**
	 * @param string $token Queue lock token.
	 *
	 * @return Run|null The active run owning the lock token (interactive runs share one token for all their claims).
	 */
	public static function get_by_lock_token( $token ) {
		global $wpdb;

		if ( empty( $token ) ) {
			return null;
		}

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->stc_fulfillment_runs} WHERE run_lock_token = %s AND run_status IN ('running','paused','stopped') ORDER BY run_id DESC LIMIT 1", $token ) );

		return $row ? new self( $row ) : null;
	}

	/**
	 * Marks the run as alive (e.g. heartbeat of an interactive run).
	 *
	 * @param int $run_id
	 */
	public static function touch( $run_id ) {
		global $wpdb;

		$wpdb->update( $wpdb->stc_fulfillment_runs, array( 'run_date_modified_gmt' => self::now() ), array( 'run_id' => absint( $run_id ) ) );
	}

	public function is_interactive() {
		return 'interactive' === $this->data['source'];
	}

	/**
	 * @param int $run_id
	 *
	 * @return Run|null
	 */
	public static function get( $run_id ) {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->stc_fulfillment_runs} WHERE run_id = %d", absint( $run_id ) ) );

		return $row ? new self( $row ) : null;
	}

	/**
	 * @param int $run_id
	 * @param array $changes key => value (keys without prefix), counters may be passed as ['increment' => [...]]
	 *
	 * @return Run|null
	 */
	public static function update( $run_id, $changes ) {
		global $wpdb;

		$set    = array();
		$values = array();

		foreach ( (array) $changes as $key => $value ) {
			if ( 'increment' === $key ) {
				foreach ( (array) $value as $counter ) {
					if ( in_array( $counter, array( 'processed', 'succeeded', 'failed', 'total' ), true ) ) {
						$set[] = "run_{$counter} = run_{$counter} + 1";
					}
				}
				continue;
			}

			$column = in_array( $key, array( 'date_finished' ), true ) ? "run_{$key}_gmt" : "run_{$key}";

			if ( 'status' === $key && ! in_array( $value, self::STATUSES, true ) ) {
				continue;
			}

			if ( 'status' === $key && in_array( $value, array( 'completed', 'cancelled' ), true ) ) {
				$set[]    = 'run_date_finished_gmt = %s';
				$values[] = self::now();
			}

			$set[]    = "{$column} = " . ( is_int( $value ) || is_bool( $value ) ? '%d' : '%s' );
			$values[] = is_bool( $value ) ? (int) $value : $value;
		}

		if ( ! empty( $set ) ) {
			$set[]    = 'run_date_modified_gmt = %s';
			$values[] = self::now();
			$values[] = absint( $run_id );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Columns are fixed.
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->stc_fulfillment_runs} SET " . implode( ', ', $set ) . ' WHERE run_id = %d', $values ) );
		}

		return self::get( $run_id );
	}

	/**
	 * @param array $args user_id, workflow_id, status (array), dismissed (bool|null), limit
	 *
	 * @return Run[] Newest first.
	 */
	public static function query( $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'user_id'         => 0,
				'workflow_id'     => 0,
				'status'          => array(),
				'dismissed'       => null,
				'limit'           => 20,
				// Sources to include/exclude, e.g. automation runs only: exclude_source => interactive.
				'source'          => array(),
				'exclude_source'  => array(),
				'modified_before' => '',
			)
		);
		$where = array( '1=1' );

		if ( ! empty( $args['source'] ) ) {
			$where[] = "run_source IN ('" . implode( "','", array_map( 'esc_sql', (array) $args['source'] ) ) . "')";
		}

		if ( ! empty( $args['exclude_source'] ) ) {
			$where[] = "run_source NOT IN ('" . implode( "','", array_map( 'esc_sql', (array) $args['exclude_source'] ) ) . "')";
		}

		if ( ! empty( $args['modified_before'] ) ) {
			$where[] = $wpdb->prepare( 'run_date_modified_gmt < %s', $args['modified_before'] );
		}

		if ( $args['user_id'] ) {
			$where[] = $wpdb->prepare( 'run_user_id = %d', $args['user_id'] );
		}

		if ( $args['workflow_id'] ) {
			$where[] = $wpdb->prepare( 'run_workflow_id = %d', $args['workflow_id'] );
		}

		if ( ! empty( $args['status'] ) ) {
			$statuses = array_values( array_intersect( (array) $args['status'], self::STATUSES ) );
			$where[]  = empty( $statuses ) ? '1=0' : "run_status IN ('" . implode( "','", array_map( 'esc_sql', $statuses ) ) . "')";
		}

		if ( ! is_null( $args['dismissed'] ) ) {
			$where[] = $wpdb->prepare( 'run_dismissed = %d', $args['dismissed'] ? 1 : 0 );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Conditions are prepared fragments.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->stc_fulfillment_runs} WHERE " . implode( ' AND ', $where ) . ' ORDER BY run_id DESC LIMIT %d', absint( $args['limit'] ) ) );

		return array_map(
			function ( $row ) {
				return new self( $row );
			},
			$rows
		);
	}
}
