<?php
/**
 * Address-book autocomplete datalists.
 *
 * Renders two native <datalist> elements (emails + names) the signer/recipient
 * inputs can reference via list="comsign-contact-emails" / "comsign-contact-names".
 * Native datalists are keyboard- and screen-reader-accessible out of the box.
 *
 * @package ComSign
 *
 * @var array $contacts Contact rows for the current account scope.
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $contacts ) ) {
	return;
}
?>
<datalist id="comsign-contact-emails">
	<?php foreach ( $contacts as $contact ) : ?>
		<?php if ( '' !== (string) $contact->email ) : ?>
			<option value="<?php echo esc_attr( $contact->email ); ?>"><?php echo esc_html( $contact->name ); ?></option>
		<?php endif; ?>
	<?php endforeach; ?>
</datalist>
<datalist id="comsign-contact-names">
	<?php foreach ( $contacts as $contact ) : ?>
		<?php if ( '' !== (string) $contact->name ) : ?>
			<option value="<?php echo esc_attr( $contact->name ); ?>"></option>
		<?php endif; ?>
	<?php endforeach; ?>
</datalist>
