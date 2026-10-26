/**
 * WordPress dependencies
 */
// eslint-disable-next-line no-unused-vars
import { createElement, createRoot } from '@wordpress/element';
import domReady from '@wordpress/dom-ready';

/**
 * Internal dependencies
 */
import App from './app';
import './admin.scss';

domReady( () => {
	const container = document.getElementById( 'wpcore-admin' );

	if ( container ) {
		createRoot( container ).render( <App { ...window.wpcoreAdmin } /> );
	}
} );
