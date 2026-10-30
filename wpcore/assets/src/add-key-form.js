/**
 * WordPress dependencies
 */
// eslint-disable-next-line no-unused-vars
import { createElement, useState } from '@wordpress/element';
import { Button, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Form in the top bar of the WPCore screen that saves a collection key.
 *
 * @param {Object}                             props          Component props.
 * @param {function(string): Promise<boolean>} props.onAdd    Saves a key and resolves with true on success.
 * @param {boolean}                            props.disabled True while another request runs.
 * @return {Element} Form.
 */
export default function AddKeyForm( { onAdd, disabled } ) {
	const [ key, setKey ] = useState( '' );

	const submit = async ( event ) => {
		event.preventDefault();

		if ( '' === key.trim() ) {
			return;
		}

		if ( await onAdd( key.trim() ) ) {
			setKey( '' );
		}
	};

	return (
		<form className="wpcore-add-key" onSubmit={ submit }>
			<div className="wpcore-add-key__row">
				<TextControl
					label={ __( 'Collection key', 'wpcore' ) }
					hideLabelFromVision
					placeholder={ __(
						'Paste a collection key from WPCore.com',
						'wpcore'
					) }
					value={ key }
					onChange={ setKey }
					disabled={ disabled }
					autoComplete="off"
					spellCheck={ false }
				/>
				<Button
					variant="primary"
					type="submit"
					disabled={ disabled || '' === key.trim() }
				>
					{ __( 'Add collection', 'wpcore' ) }
				</Button>
			</div>
		</form>
	);
}
