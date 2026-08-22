<?php
/**
 * Cart item data — optic summary outputs without variation label (dt).
 *
 * @package WC_Optic_Product
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit;

?>
<dl class="variation">
	<?php foreach ( $item_data as $data ) : ?>
		<?php
		$key = ! empty( $data['key'] ) ? $data['key'] : ( ! empty( $data['name'] ) ? $data['name'] : '' );
		if ( 'optic-line' === $key ) :
			?>
			<dd class="variation-optic-line"><?php echo wp_kses_post( $data['display'] ); ?></dd>
		<?php else : ?>
			<dt class="<?php echo esc_attr( sanitize_html_class( 'variation-' . $key ) ); ?>"><?php echo wp_kses_post( $key ); ?>:</dt>
			<dd class="<?php echo esc_attr( sanitize_html_class( 'variation-' . $key ) ); ?>"><?php echo wp_kses_post( wpautop( $data['display'] ) ); ?></dd>
		<?php endif; ?>
	<?php endforeach; ?>
</dl>
