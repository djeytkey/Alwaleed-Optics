<?php
/**
 * QR codes for internal optic SKUs (admin only).
 *
 * @package WC_Optic_Product
 */

defined( 'ABSPATH' ) || exit;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * Class WC_Optic_QR
 */
class WC_Optic_QR {

	/**
	 * Whether the QR library is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( QRCode::class );
	}

	/**
	 * Build QR markup for the given SKU text (PNG data-URI or inline SVG).
	 *
	 * @param string $text SKU or payload to encode.
	 * @return array{type:string, content:string} Empty content on failure.
	 */
	public static function get_markup( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text || ! self::is_available() ) {
			return array(
				'type'    => '',
				'content' => '',
			);
		}

		try {
			if ( extension_loaded( 'gd' ) ) {
				$options = new QROptions(
					array(
						'outputType'    => QRCode::OUTPUT_IMAGE_PNG,
						'imageBase64'   => true,
						'scale'         => 4,
						'versionMin'    => 1,
						'versionMax'    => 10,
						'quietzoneSize' => 1,
					)
				);

				return array(
					'type'    => 'img',
					'content' => (string) ( new QRCode( $options ) )->render( $text ),
				);
			}

			$options = new QROptions(
				array(
					'outputType'    => QRCode::OUTPUT_MARKUP_SVG,
					'scale'         => 4,
					'versionMin'    => 1,
					'versionMax'    => 10,
					'quietzoneSize' => 1,
				)
			);

			return array(
				'type'    => 'svg',
				'content' => (string) ( new QRCode( $options ) )->render( $text ),
			);
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'wc-optic: QR generation failed — ' . $e->getMessage() );
			return array(
				'type'    => '',
				'content' => '',
			);
		}
	}

	/**
	 * Render one admin QR block (label optional).
	 *
	 * @param string $sku   Internal SKU.
	 * @param string $label Short label (e.g. OD, OS).
	 * @param int    $size  QR image size in pixels.
	 * @return string Safe HTML or empty.
	 */
	public static function render_admin_block( $sku, $label = '', $size = 120 ) {
		$sku = trim( (string) $sku );
		if ( '' === $sku ) {
			return '';
		}

		$markup = self::get_markup( $sku );
		if ( '' === $markup['content'] ) {
			return '';
		}

		$size  = max( 80, min( 256, (int) $size ) );
		$html  = '<div class="wc-optic-qr" style="--wc-optic-qr-size:' . $size . 'px">';
		if ( '' !== trim( (string) $label ) ) {
			$html .= '<span class="wc-optic-qr__label">' . esc_html( $label ) . '</span>';
		}

		$alt = sprintf(
			/* translators: %s: internal SKU */
			__( 'QR code for SKU %s', 'wc-optic' ),
			$sku
		);

		if ( 'img' === $markup['type'] ) {
			$html .= '<img class="wc-optic-qr__img" src="' . esc_attr( $markup['content'] ) . '" width="' . $size . '" height="' . $size . '" alt="' . esc_attr( $alt ) . '" />';
		} else {
			$html .= '<span class="wc-optic-qr__svg" role="img" aria-label="' . esc_attr( $alt ) . '">';
			$html .= wp_kses(
				$markup['content'],
				array(
					'svg'  => array(
						'xmlns'   => true,
						'viewbox' => true,
						'width'   => true,
						'height'  => true,
						'class'   => true,
						'style'   => true,
					),
					'path' => array(
						'd'           => true,
						'fill'        => true,
						'fill-rule'   => true,
						'clip-rule'   => true,
						'stroke'      => true,
						'stroke-width' => true,
					),
					'rect' => array(
						'x'      => true,
						'y'      => true,
						'width'  => true,
						'height' => true,
						'fill'   => true,
					),
				)
			);
			$html .= '</span>';
		}

		$html .= '<code class="wc-optic-qr__sku">' . esc_html( $sku ) . '</code>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Render QR blocks for an order/cart optic payload.
	 *
	 * @param array $payload Optic line payload.
	 * @return string Safe HTML or empty.
	 */
	public static function render_admin_blocks_for_payload( array $payload ) {
		$left_sku  = isset( $payload['left']['sku'] ) ? trim( (string) $payload['left']['sku'] ) : '';
		$right_sku = isset( $payload['right']['sku'] ) ? trim( (string) $payload['right']['sku'] ) : '';
		$same      = ! empty( $payload['same_power'] ) || ( $left_sku && $left_sku === $right_sku );

		$blocks = array();

		if ( $same ) {
			$sku = $left_sku ? $left_sku : $right_sku;
			if ( $sku ) {
				$blocks[] = self::render_admin_block( $sku );
			}
		} else {
			if ( $right_sku ) {
				$blocks[] = self::render_admin_block( $right_sku, __( 'OD', 'wc-optic' ) );
			}
			if ( $left_sku ) {
				$blocks[] = self::render_admin_block( $left_sku, __( 'OS', 'wc-optic' ) );
			}
		}

		$blocks = array_filter( $blocks );
		if ( empty( $blocks ) ) {
			return '';
		}

		return '<div class="wc-optic-qr-list">' . implode( '', $blocks ) . '</div>';
	}

	/**
	 * Render QR blocks for all enabled child configs on a product.
	 *
	 * @param WC_Product $product Product.
	 * @return string Safe HTML or empty.
	 */
	public static function render_admin_blocks_for_product( WC_Product $product ) {
		if ( 'optic_product' !== $product->get_type() ) {
			return '';
		}

		$configs = WC_Optic_SKU::get_enabled_child_configs( $product );
		$blocks  = array();

		foreach ( $configs as $config ) {
			$sku = isset( $config['sku'] ) ? trim( (string) $config['sku'] ) : '';
			if ( ! $sku ) {
				continue;
			}
			$label = WC_Optic_SKU::child_display_label( $config, (string) $product->get_meta( '_optic_division', true ) );
			$blocks[] = self::render_admin_block( $sku, $label );
		}

		$blocks = array_filter( $blocks );
		if ( empty( $blocks ) ) {
			return '';
		}

		return '<div class="wc-optic-qr-list wc-optic-qr-list--product">' . implode( '', $blocks ) . '</div>';
	}
}
