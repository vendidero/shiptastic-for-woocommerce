<?php
defined( 'ABSPATH' ) || exit;

$fields = wc_stc_get_shipment_setting_address_fields();
$default_fields = wc_stc_get_shipment_setting_default_address_fields();
$address_fields_to_render = array(
	'company',
	'address_1',
	'address_2',
	'city',
	'postcode',
	'country',
);
?>

<h1><?php echo esc_html_x( 'Welcome! To get started, tell us something about yourself.', 'shipments', 'shiptastic-for-woocommerce' ); ?></h1>

<?php foreach( $address_fields_to_render as $field ) :

	?>
	<fieldset>
		<label for="<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $default_fields[ $field ] ); ?></label>
		<?php if ( 'country' !== $field ) : ?>
			<input type="text" id="<?php echo esc_attr( $field ); ?>" name="<?php echo esc_attr( $field ); ?>" value="<?php echo esc_attr( $fields[ $field ] ); ?>" />
		<?php else: ?>
		<?php endif; ?>
	</fieldset>
<?php endforeach; ?>
