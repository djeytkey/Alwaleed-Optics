<?php
/**
 * Background Convert / Rebuild / Specifics queue (Action Scheduler + admin poll fallback).
 *
 * Wizard Option A: configure every product, then enqueue the batch at Finish.
 *
 * @package WC_Optic_Product
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Optic_Convert_Queue
 */
class WC_Optic_Convert_Queue {

	const OPTION_PREFIX = 'wc_optic_cq_';
	const HOOK          = 'wc_optic_convert_queue_item';
	const GROUP         = 'wc-optic-convert';
	const LOCK_TTL      = 300;

	/**
	 * Hooks.
	 */
	public static function hooks() {
		add_action( self::HOOK, array( __CLASS__, 'handle_action' ), 10, 2 );
	}

	/**
	 * Whether Action Scheduler is available.
	 *
	 * @return bool
	 */
	public static function has_action_scheduler() {
		return function_exists( 'as_enqueue_async_action' );
	}

	/**
	 * Create a batch and schedule processing.
	 *
	 * @param array $items List of { product_id, args, name? }.
	 * @param int   $user_id User id.
	 * @return array{batch_id:string,total:int}|WP_Error
	 */
	public static function create_batch( array $items, $user_id = 0 ) {
		$normalized = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$product_id = isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0;
			$args       = isset( $item['args'] ) && is_array( $item['args'] ) ? $item['args'] : array();
			if ( $product_id < 1 || empty( $args ) ) {
				continue;
			}
			$normalized[] = array(
				'product_id'  => $product_id,
				'name'        => isset( $item['name'] ) ? sanitize_text_field( (string) $item['name'] ) : '',
				'args'        => self::sanitize_args( $args ),
				'status'      => 'pending',
				'message'     => '',
				'child_count' => 0,
			);
		}

		if ( empty( $normalized ) ) {
			return new WP_Error( 'wc_optic_empty_batch', __( 'No products to convert.', 'wc-optic' ) );
		}

		$batch_id = strtolower( wp_generate_password( 12, false, false ) );
		$batch    = array(
			'id'         => $batch_id,
			'user_id'    => absint( $user_id ),
			'created'    => time(),
			'updated'    => time(),
			'status'     => 'queued',
			'items'      => $normalized,
			'total'      => count( $normalized ),
			'done'       => 0,
			'ok'         => 0,
			'error'      => 0,
			'skipped'    => 0,
			'use_as'     => self::has_action_scheduler(),
			'cursor'     => 0,
		);

		self::save_batch( $batch );
		self::schedule_next( $batch_id, 0 );

		return array(
			'batch_id' => $batch_id,
			'total'    => (int) $batch['total'],
		);
	}

	/**
	 * Sanitize convert args stored in the batch.
	 *
	 * @param array $args Raw args.
	 * @return array
	 */
	protected static function sanitize_args( array $args ) {
		$mode = isset( $args['mode'] ) ? sanitize_key( (string) $args['mode'] ) : 'skip_if_has_children';
		if ( ! in_array( $mode, array( 'replace', 'append', 'skip_if_has_children' ), true ) ) {
			$mode = ! empty( $args['replace'] ) ? 'replace' : 'skip_if_has_children';
		}

		return array(
			'division'     => isset( $args['division'] ) ? sanitize_key( (string) $args['division'] ) : '',
			'catalog'      => isset( $args['catalog'] ) && is_array( $args['catalog'] ) ? $args['catalog'] : array(),
			'ranges'       => isset( $args['ranges'] ) && is_array( $args['ranges'] ) ? $args['ranges'] : array(),
			'color_images' => isset( $args['color_images'] ) && is_array( $args['color_images'] ) ? $args['color_images'] : array(),
			'unit_price'   => isset( $args['unit_price'] ) ? (string) wc_format_decimal( $args['unit_price'] ) : '',
			'sale_price'   => isset( $args['sale_price'] ) ? (string) wc_format_decimal( $args['sale_price'] ) : '',
			'stock_qty'    => isset( $args['stock_qty'] ) ? absint( $args['stock_qty'] ) : 0,
			'mode'         => $mode,
		);
	}

	/**
	 * Action Scheduler callback.
	 *
	 * @param string $batch_id Batch id.
	 * @param int    $index    Item index.
	 */
	public static function handle_action( $batch_id, $index = 0 ) {
		self::process_index( (string) $batch_id, (int) $index, true );
	}

	/**
	 * Process one pending item (used by AS and by admin poll tick).
	 *
	 * @param string $batch_id     Batch id.
	 * @param bool   $schedule_next Whether to chain the next AS job.
	 * @return array|WP_Error Status snapshot or error.
	 */
	public static function tick( $batch_id, $schedule_next = true ) {
		$batch = self::get_batch( $batch_id );
		if ( ! $batch ) {
			return new WP_Error( 'wc_optic_missing_batch', __( 'Conversion batch not found.', 'wc-optic' ) );
		}
		if ( in_array( (string) ( $batch['status'] ?? '' ), array( 'complete', 'cancelled' ), true ) ) {
			return self::public_status( $batch );
		}

		$index = isset( $batch['cursor'] ) ? (int) $batch['cursor'] : 0;
		$total = (int) ( $batch['total'] ?? 0 );
		while ( $index < $total ) {
			$status = (string) ( $batch['items'][ $index ]['status'] ?? '' );
			if ( 'pending' === $status || 'running' === $status ) {
				break;
			}
			++$index;
		}

		if ( $index >= $total ) {
			$batch['status']  = 'complete';
			$batch['updated'] = time();
			self::save_batch( $batch );
			return self::public_status( $batch );
		}

		return self::process_index( $batch_id, $index, $schedule_next );
	}

	/**
	 * Process a specific item index.
	 *
	 * @param string $batch_id      Batch id.
	 * @param int    $index         Index.
	 * @param bool   $schedule_next Chain next job.
	 * @return array|WP_Error
	 */
	public static function process_index( $batch_id, $index, $schedule_next = true ) {
		$batch_id = sanitize_key( (string) $batch_id );
		$index    = max( 0, (int) $index );
		$lock_key = 'wc_optic_cq_lock_' . $batch_id;

		if ( get_transient( $lock_key ) ) {
			$batch = self::get_batch( $batch_id );
			return $batch ? self::public_status( $batch ) : new WP_Error( 'wc_optic_missing_batch', __( 'Conversion batch not found.', 'wc-optic' ) );
		}
		set_transient( $lock_key, 1, self::LOCK_TTL );

		try {
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}

			$batch = self::get_batch( $batch_id );
			if ( ! $batch || empty( $batch['items'][ $index ] ) ) {
				return new WP_Error( 'wc_optic_missing_batch', __( 'Conversion batch not found.', 'wc-optic' ) );
			}

			$item = $batch['items'][ $index ];
			if ( in_array( (string) ( $item['status'] ?? '' ), array( 'ok', 'error', 'skip' ), true ) ) {
				$batch['cursor'] = $index + 1;
				self::save_batch( $batch );
				if ( $schedule_next ) {
					self::schedule_next( $batch_id, $index + 1 );
				}
				return self::public_status( $batch );
			}

			$batch['items'][ $index ]['status'] = 'running';
			$batch['status']                      = 'running';
			$batch['cursor']                      = $index;
			$batch['updated']                     = time();
			self::save_batch( $batch );

			$result = WC_Optic_Converter::convert_product(
				(int) $item['product_id'],
				is_array( $item['args'] ?? null ) ? $item['args'] : array()
			);

			$batch = self::get_batch( $batch_id );
			if ( ! $batch ) {
				return new WP_Error( 'wc_optic_missing_batch', __( 'Conversion batch not found.', 'wc-optic' ) );
			}

			if ( is_wp_error( $result ) ) {
				$batch['items'][ $index ]['status']  = 'error';
				$batch['items'][ $index ]['message'] = $result->get_error_message();
				$batch['error']                      = (int) ( $batch['error'] ?? 0 ) + 1;
			} elseif ( ! empty( $result['skipped'] ) ) {
				$batch['items'][ $index ]['status']      = 'skip';
				$batch['items'][ $index ]['message']     = (string) ( $result['message'] ?? '' );
				$batch['items'][ $index ]['child_count'] = (int) ( $result['child_count'] ?? 0 );
				$batch['skipped']                          = (int) ( $batch['skipped'] ?? 0 ) + 1;
			} else {
				$batch['items'][ $index ]['status']      = 'ok';
				$batch['items'][ $index ]['child_count'] = (int) ( $result['child_count'] ?? 0 );
				$batch['items'][ $index ]['message']     = '';
				$batch['ok']                               = (int) ( $batch['ok'] ?? 0 ) + 1;
			}

			$batch['done']    = (int) ( $batch['done'] ?? 0 ) + 1;
			$batch['cursor']  = $index + 1;
			$batch['updated'] = time();

			if ( (int) $batch['done'] >= (int) $batch['total'] ) {
				$batch['status'] = 'complete';
			} else {
				$batch['status'] = 'running';
			}

			self::save_batch( $batch );

			if ( 'complete' !== $batch['status'] && $schedule_next ) {
				self::schedule_next( $batch_id, $index + 1 );
			}

			return self::public_status( $batch );
		} finally {
			delete_transient( $lock_key );
		}
	}

	/**
	 * Schedule next item (AS) or no-op (poll fallback).
	 *
	 * @param string $batch_id Batch id.
	 * @param int    $index    Next index.
	 */
	protected static function schedule_next( $batch_id, $index ) {
		$batch = self::get_batch( $batch_id );
		if ( ! $batch || $index >= (int) $batch['total'] ) {
			return;
		}
		if ( empty( $batch['use_as'] ) || ! self::has_action_scheduler() ) {
			return;
		}
		as_enqueue_async_action(
			self::HOOK,
			array(
				'batch_id' => $batch_id,
				'index'    => (int) $index,
			),
			self::GROUP
		);
	}

	/**
	 * Load batch.
	 *
	 * @param string $batch_id Batch id.
	 * @return array|null
	 */
	public static function get_batch( $batch_id ) {
		$batch_id = sanitize_key( (string) $batch_id );
		if ( '' === $batch_id ) {
			return null;
		}
		$batch = get_option( self::OPTION_PREFIX . $batch_id, null );
		return is_array( $batch ) ? $batch : null;
	}

	/**
	 * Persist batch.
	 *
	 * @param array $batch Batch.
	 */
	protected static function save_batch( array $batch ) {
		$id = sanitize_key( (string) ( $batch['id'] ?? '' ) );
		if ( '' === $id ) {
			return;
		}
		$batch['updated'] = time();
		update_option( self::OPTION_PREFIX . $id, $batch, false );
	}

	/**
	 * Public status payload for AJAX.
	 *
	 * @param string|array $batch_or_id Batch or id.
	 * @return array|WP_Error
	 */
	public static function get_status( $batch_or_id ) {
		$batch = is_array( $batch_or_id ) ? $batch_or_id : self::get_batch( $batch_or_id );
		if ( ! $batch ) {
			return new WP_Error( 'wc_optic_missing_batch', __( 'Conversion batch not found.', 'wc-optic' ) );
		}
		return self::public_status( $batch );
	}

	/**
	 * Shape status for the frontend.
	 *
	 * @param array $batch Batch.
	 * @return array
	 */
	protected static function public_status( array $batch ) {
		$items = array();
		foreach ( (array) ( $batch['items'] ?? array() ) as $item ) {
			$items[] = array(
				'product_id'  => (int) ( $item['product_id'] ?? 0 ),
				'name'        => (string) ( $item['name'] ?? '' ),
				'status'      => (string) ( $item['status'] ?? 'pending' ),
				'message'     => (string) ( $item['message'] ?? '' ),
				'child_count' => (int) ( $item['child_count'] ?? 0 ),
			);
		}

		$total = max( 1, (int) ( $batch['total'] ?? 1 ) );
		$done  = (int) ( $batch['done'] ?? 0 );

		return array(
			'batch_id'   => (string) ( $batch['id'] ?? '' ),
			'status'     => (string) ( $batch['status'] ?? 'queued' ),
			'total'      => (int) ( $batch['total'] ?? 0 ),
			'done'       => $done,
			'ok'         => (int) ( $batch['ok'] ?? 0 ),
			'error'      => (int) ( $batch['error'] ?? 0 ),
			'skipped'    => (int) ( $batch['skipped'] ?? 0 ),
			'percent'    => (int) min( 100, round( ( $done / $total ) * 100 ) ),
			'use_as'     => ! empty( $batch['use_as'] ),
			'items'      => $items,
			'complete'   => 'complete' === (string) ( $batch['status'] ?? '' ),
		);
	}
}
