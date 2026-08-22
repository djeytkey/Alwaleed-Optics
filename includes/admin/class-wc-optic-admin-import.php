<?php
/**
 * Per-tab Excel/CSV import with downloadable templates.
 *
 * @package WC_Optic_Product
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Optic_Admin_Import
 */
class WC_Optic_Admin_Import {

	const OPTION_LOGS = 'wc_optic_import_logs';

	/** Template column headers (row 1). */
	const TEMPLATE_HEADERS = array( 'Name', 'SKU Fragment' );

	/**
	 * Hooks.
	 */
	public static function hooks() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_download_template' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Styles on import page.
	 *
	 * @param string $hook Hook.
	 */
	public static function enqueue( $hook ) {
		if ( WC_Optic_Admin_Menu::IMPORT_SCREEN !== $hook ) {
			return;
		}
		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style(
			'wc-optic-admin',
			WC_OPTIC_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			WC_OPTIC_VERSION
		);
	}

	/**
	 * Stream template file for active catalog tab.
	 */
	public static function maybe_download_template() {
		if ( ! isset( $_GET['page'] ) || 'wc-optic-import' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( empty( $_GET['wc_optic_download_template'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'wc-optic' ) );
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! WC_Optic_Catalog::is_valid_type( $tab ) ) {
			wp_die( esc_html__( 'Invalid catalog type.', 'wc-optic' ) );
		}

		check_admin_referer( 'wc_optic_template_' . $tab );

		self::output_template( $tab );
		exit;
	}

	/**
	 * Output XLSX or CSV template (headers on row 1, data from row 2).
	 *
	 * @param string $term_type Catalog type slug.
	 */
	protected static function output_template( $term_type ) {
		$label    = self::tab_label( $term_type );
		$basename = 'optic-import-' . $term_type;

		if ( class_exists( '\PhpOffice\PhpSpreadsheet\Spreadsheet' ) ) {
			$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
			$sheet       = $spreadsheet->getActiveSheet();
			$sheet->setTitle( substr( $label, 0, 31 ) );
			$sheet->fromArray( self::TEMPLATE_HEADERS, null, 'A1' );
			$sheet->getStyle( 'A1:B1' )->getFont()->setBold( true );

			header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
			header( 'Content-Disposition: attachment; filename="' . $basename . '.xlsx"' );
			header( 'Cache-Control: max-age=0' );

			$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx( $spreadsheet );
			$writer->save( 'php://output' );
			return;
		}

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $basename . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		if ( false !== $out ) {
			fputcsv( $out, self::TEMPLATE_HEADERS );
			fclose( $out );
		}
	}

	/**
	 * Append log entry.
	 *
	 * @param array $entry Entry.
	 */
	public static function add_log( array $entry ) {
		$logs = get_option( self::OPTION_LOGS, array() );
		if ( ! is_array( $logs ) ) {
			$logs = array();
		}
		$entry['time'] = current_time( 'mysql' );
		array_unshift( $logs, $entry );
		$logs = array_slice( $logs, 0, 100 );
		update_option( self::OPTION_LOGS, $logs, false );
	}

	/**
	 * Render import UI with one tab per catalog list.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$active = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'section'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! WC_Optic_Catalog::is_valid_type( $active ) ) {
			$active = 'section';
		}

		$result = null;
		if (
			! empty( $_POST['wc_optic_import_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wc_optic_import_nonce'] ) ), 'wc_optic_import_' . $active )
		) {
			$result = self::process_import( $active );
		}

		$settings_url = admin_url( 'admin.php?page=wc-optic-settings' );

		echo '<div class="wrap woocommerce wc-optic-import-wrap">';
		echo '<h1>' . esc_html__( 'Optic catalog import', 'wc-optic' ) . '</h1>';
		echo '<p><a href="' . esc_url( $settings_url ) . '">&larr; ' . esc_html__( 'Back to Optic Settings', 'wc-optic' ) . '</a></p>';

		if ( is_array( $result ) ) {
			$cls = $result['error'] ? 'notice-error' : 'notice-success';
			echo '<div class="notice ' . esc_attr( $cls ) . ' is-dismissible"><p>' . esc_html( $result['message'] ) . '</p></div>';
		}

		echo '<h2 class="nav-tab-wrapper">';
		foreach ( WC_Optic_Catalog::TYPES as $type ) {
			$url   = admin_url( 'admin.php?page=wc-optic-import&tab=' . $type );
			$class = $active === $type ? 'nav-tab nav-tab-active' : 'nav-tab';
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">' . esc_html( self::tab_label( $type ) ) . '</a>';
		}
		echo '</h2>';

		$template_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'                        => 'wc-optic-import',
					'tab'                         => $active,
					'wc_optic_download_template' => '1',
				),
				admin_url( 'admin.php' )
			),
			'wc_optic_template_' . $active
		);

		echo '<div class="wc-optic-import-panel card">';
		echo '<h2>' . esc_html( self::tab_label( $active ) ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Use the template: row 1 contains headers (Name, SKU Fragment). Enter your data starting from row 2.', 'wc-optic' ) . '</p>';

		echo '<p class="wc-optic-import-template">';
		echo '<a class="button" href="' . esc_url( $template_url ) . '">';
		echo '<span class="dashicons dashicons-download" style="vertical-align:middle;margin-top:3px;"></span> ';
		echo esc_html__( 'Download template', 'wc-optic' );
		echo '</a>';
		if ( ! class_exists( '\PhpOffice\PhpSpreadsheet\Spreadsheet' ) ) {
			echo ' <span class="description">' . esc_html__( '(CSV — run composer install in the plugin folder for XLSX templates)', 'wc-optic' ) . '</span>';
		}
		echo '</p>';

		echo '<form method="post" enctype="multipart/form-data" class="wc-optic-import-form">';
		wp_nonce_field( 'wc_optic_import_' . $active, 'wc_optic_import_nonce' );
		echo '<input type="hidden" name="wc_optic_term_type" value="' . esc_attr( $active ) . '" />';

		echo '<table class="form-table" role="presentation"><tbody><tr>';
		echo '<th scope="row"><label for="wc_optic_file">' . esc_html__( 'Excel / CSV file', 'wc-optic' ) . '</label></th>';
		echo '<td>';
		echo '<input type="file" name="wc_optic_file" id="wc_optic_file" accept=".xlsx,.csv,.txt" required />';
		echo '<p class="description">' . esc_html__( 'Accepted formats: .xlsx, .csv', 'wc-optic' ) . '</p>';
		echo '</td></tr></tbody></table>';

		submit_button( __( 'Import into this list', 'wc-optic' ), 'primary', 'submit', false );
		echo '</form>';
		echo '</div>';

		$logs = get_option( self::OPTION_LOGS, array() );
		if ( is_array( $logs ) && $logs ) {
			echo '<h2>' . esc_html__( 'Recent import logs', 'wc-optic' ) . '</h2>';
			echo '<ul class="wc-optic-import-logs">';
			foreach ( array_slice( $logs, 0, 20 ) as $log ) {
				echo '<li><code>' . esc_html( isset( $log['time'] ) ? $log['time'] : '' ) . '</code> — ';
				echo esc_html( isset( $log['message'] ) ? $log['message'] : '' );
				echo '</li>';
			}
			echo '</ul>';
		}

		echo '</div>';
	}

	/**
	 * Tab label (matches Optic Settings).
	 *
	 * @param string $type Type.
	 * @return string
	 */
	protected static function tab_label( $type ) {
		return WC_Optic_Catalog::get_type_label( $type );
	}

	/**
	 * Process uploaded file for one catalog tab.
	 *
	 * @param string $term_type Active tab / catalog type.
	 * @return array{error:bool,message:string}
	 */
	protected static function process_import( $term_type ) {
		if ( ! WC_Optic_Catalog::is_valid_type( $term_type ) ) {
			return array(
				'error'   => true,
				'message' => __( 'Invalid catalog type.', 'wc-optic' ),
			);
		}

		if ( empty( $_FILES['wc_optic_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['wc_optic_file']['tmp_name'] ) ) {
			return array(
				'error'   => true,
				'message' => __( 'No file uploaded.', 'wc-optic' ),
			);
		}

		$file = $_FILES['wc_optic_file']['tmp_name'];
		$name = isset( $_FILES['wc_optic_file']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['wc_optic_file']['name'] ) ) : 'import';

		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		$rows = array();
		if ( 'csv' === $ext || 'txt' === $ext ) {
			$rows = self::parse_csv( $file );
		} elseif ( 'xlsx' === $ext ) {
			$rows = self::parse_xlsx( $file );
			if ( is_wp_error( $rows ) ) {
				return array(
					'error'   => true,
					'message' => $rows->get_error_message(),
				);
			}
		} else {
			return array(
				'error'   => true,
				'message' => __( 'Unsupported file type. Use .xlsx or .csv.', 'wc-optic' ),
			);
		}

		if ( count( $rows ) < 1 ) {
			return array(
				'error'   => true,
				'message' => __( 'The file is empty.', 'wc-optic' ),
			);
		}

		// Row 1 = headers; data from row 2 onward.
		$data_rows = array_slice( $rows, 1 );

		$inserted = 0;
		$skipped  = 0;
		$list_lbl = self::tab_label( $term_type );

		foreach ( $data_rows as $r ) {
			if ( ! is_array( $r ) ) {
				++$skipped;
				continue;
			}

			$nm = isset( $r[0] ) ? sanitize_text_field( trim( (string) $r[0] ) ) : '';
			$frag = isset( $r[1] ) ? WC_Optic_Catalog::sanitize_sku_fragment( $r[1] ) : '';

			if ( '' === $nm && '' === $frag ) {
				continue;
			}

			if ( '' === $nm || '' === $frag ) {
				++$skipped;
				continue;
			}

			$slug = WC_Optic_Catalog::sanitize_slug( $nm );
			if ( '' === $slug ) {
				$slug = 'item-' . strtolower( wp_unique_id() );
			}

			if ( WC_Optic_Catalog::get_by_slug( $term_type, $slug ) ) {
				++$skipped;
				continue;
			}

			$res = WC_Optic_Catalog::insert( $term_type, $nm, $slug, $frag, 0 );
			if ( $res ) {
				++$inserted;
			} else {
				++$skipped;
			}
		}

		$msg = sprintf(
			/* translators: 1: list name, 2: inserted count, 3: skipped count */
			__( '%1$s: %2$d imported, %3$d skipped (empty, duplicate, or invalid).', 'wc-optic' ),
			$list_lbl,
			$inserted,
			$skipped
		);

		self::add_log(
			array(
				'message' => $msg . ' — ' . $name,
				'type'    => $term_type,
			)
		);

		return array(
			'error'   => false,
			'message' => $msg,
		);
	}

	/**
	 * Parse CSV to 0-indexed row arrays.
	 *
	 * @param string $path Path.
	 * @return array<int, array<int, string>>
	 */
	protected static function parse_csv( $path ) {
		$rows = array();
		if ( ( $h = fopen( $path, 'r' ) ) !== false ) {
			while ( ( $data = fgetcsv( $h, 10000, ',' ) ) !== false ) {
				$rows[] = $data;
			}
			fclose( $h );
		}
		return $rows;
	}

	/**
	 * Parse XLSX via PhpSpreadsheet.
	 *
	 * @param string $path Path.
	 * @return array<int, array<int, string>>|WP_Error
	 */
	protected static function parse_xlsx( $path ) {
		if ( ! class_exists( '\PhpOffice\PhpSpreadsheet\IOFactory' ) ) {
			return new WP_Error(
				'wc_optic_xlsx',
				__( 'XLSX support requires Composer dependencies. Run composer install in the plugin folder, or use CSV.', 'wc-optic' )
			);
		}
		try {
			$spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load( $path );
			$sheet       = $spreadsheet->getActiveSheet();
			$rows        = array();
			foreach ( $sheet->getRowIterator() as $row ) {
				$cells = array();
				foreach ( $row->getCellIterator() as $cell ) {
					$cells[] = (string) $cell->getValue();
				}
				$rows[] = $cells;
			}
			return $rows;
		} catch ( Exception $e ) {
			return new WP_Error( 'wc_optic_xlsx_read', $e->getMessage() );
		}
	}
}
