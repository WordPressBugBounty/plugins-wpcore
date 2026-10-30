/**
 * WordPress dependencies
 */
import { Button, ExternalLink, Spinner } from '@wordpress/components';
// eslint-disable-next-line no-unused-vars
import { createElement, Fragment, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __, sprintf } from '@wordpress/i18n';

const AVATAR_COLORS = 8;

/**
 * Returns the installed state of a plugin. A finished install reports the state in its response
 * before the screen reloads the collections.
 *
 * @param {Object} plugin   Plugin from the collections response.
 * @param {Object} progress Progress from installInOrder(), if any.
 * @return {string} not_installed, inactive or active.
 */
function currentStatus( plugin, progress ) {
	if ( progress && 'done' === progress.status && progress.result.status ) {
		return progress.result.status;
	}

	return plugin.status;
}

/**
 * Returns the badge of a plugin row: the install progress of this screen, or the installed state from the REST route.
 *
 * @param {string} status   Installed state from currentStatus().
 * @param {Object} progress Progress from installInOrder(), if any.
 * @return {{tone: string, label: string, icon: string}} Badge tone, text and Dashicon name.
 */
function badge( status, progress ) {
	if ( progress && 'queued' === progress.status ) {
		return {
			tone: 'neutral',
			label: __( 'Queued', 'wpcore' ),
			icon: 'clock',
		};
	}

	if ( progress && 'installing' === progress.status ) {
		return {
			tone: 'progress',
			label:
				'not_installed' === status
					? __( 'Installing…', 'wpcore' )
					: __( 'Activating…', 'wpcore' ),
			icon: '',
		};
	}

	if ( progress && 'error' === progress.status ) {
		return {
			tone: 'error',
			label: __( 'Failed', 'wpcore' ),
			icon: 'warning',
		};
	}

	if ( 'active' === status ) {
		return {
			tone: 'success',
			label: __( 'Active', 'wpcore' ),
			icon: 'yes-alt',
		};
	}

	if ( 'inactive' === status ) {
		return {
			tone: 'info',
			label: __( 'Installed, inactive', 'wpcore' ),
			icon: 'marker',
		};
	}

	return {
		tone: 'neutral',
		label: __( 'Not installed', 'wpcore' ),
		icon: 'marker',
	};
}

/**
 * Returns the text that states what the last install run did to a plugin.
 *
 * @param {Object} progress Progress from installInOrder(), if any.
 * @return {string} Text, or an empty string when the last run did not change the plugin.
 */
function changeText( progress ) {
	if ( ! progress || 'done' !== progress.status ) {
		return '';
	}

	if ( progress.result.installed && progress.result.activated ) {
		return __( 'Installed and activated just now', 'wpcore' );
	}

	if ( progress.result.installed ) {
		return __( 'Installed just now', 'wpcore' );
	}

	if ( progress.result.activated ) {
		return __( 'Activated just now', 'wpcore' );
	}

	return '';
}

/**
 * Returns an avatar colour index for a slug. The same slug always gets the same colour.
 *
 * @param {string} slug Plugin slug.
 * @return {number} Index from 0 to AVATAR_COLORS - 1.
 */
function avatarColor( slug ) {
	let hash = 0;

	for ( let i = 0; i < slug.length; i++ ) {
		hash = ( hash * 31 + slug.charCodeAt( i ) ) % 997;
	}

	return hash % AVATAR_COLORS;
}

/**
 * Plugin icon from WordPress.org, or the first letter of the name on a coloured square when there is no icon
 * or the image does not load.
 *
 * @param {Object} props      Component props.
 * @param {string} props.slug Plugin slug.
 * @param {string} props.name Plugin name with HTML entities decoded.
 * @param {string} props.icon Icon URL, if any.
 * @return {Element} Avatar.
 */
function Avatar( { slug, name, icon } ) {
	const [ failed, setFailed ] = useState( false );

	if ( icon && ! failed ) {
		return (
			<img
				className="wpcore-plugin__avatar is-image"
				src={ icon }
				alt=""
				width="36"
				height="36"
				loading="lazy"
				onError={ () => setFailed( true ) }
			/>
		);
	}

	return (
		<span
			className={ `wpcore-plugin__avatar is-color-${ avatarColor(
				slug
			) }` }
			aria-hidden="true"
		>
			{ name.charAt( 0 ).toUpperCase() }
		</span>
	);
}

/**
 * One plugin of a collection: checkbox, name, source, installed state and the next action.
 *
 * @param {Object}                  props           Component props.
 * @param {Object}                  props.plugin    Plugin from the collections response. On the All tab it has collections, the names of every collection that lists it.
 * @param {string}                  props.icon      WordPress.org icon URL, if any.
 * @param {Object}                  props.progress  Progress from installInOrder(), if any.
 * @param {boolean}                 props.selected  True when the row is selected.
 * @param {function(boolean): void} props.onSelect  Called with true to select the row or false to clear it.
 * @param {function(boolean): void} props.onInstall Installs the plugin. Called with true to activate it.
 * @param {boolean}                 props.disabled  True while a request runs.
 * @return {Element} List item.
 */
export default function PluginRow( {
	plugin,
	icon,
	progress,
	selected,
	onSelect,
	onInstall,
	disabled,
} ) {
	const name = decodeEntities( plugin.name );
	const status = currentStatus( plugin, progress );
	const state = badge( status, progress );
	const change = changeText( progress );
	const classes = [ 'wpcore-plugin' ];

	if ( change ) {
		classes.push( 'is-changed' );
	}

	if ( progress && 'error' === progress.status ) {
		classes.push( 'is-failed' );
	}

	if ( selected ) {
		classes.push( 'is-selected' );
	}

	return (
		<li className={ classes.join( ' ' ) }>
			<span className="wpcore-plugin__check">
				{ 'active' !== status && (
					<input
						type="checkbox"
						aria-label={ name }
						checked={ selected }
						disabled={ disabled }
						onChange={ ( event ) =>
							onSelect( event.target.checked )
						}
					/>
				) }
			</span>
			<Avatar slug={ plugin.slug } name={ name } icon={ icon } />
			<span className="wpcore-plugin__main">
				<span className="wpcore-plugin__name">{ name }</span>
				<span className="wpcore-plugin__meta">
					{ 'wordpress.org' === plugin.source_type ? (
						<ExternalLink
							href={ `https://wordpress.org/plugins/${ plugin.slug }/` }
						>
							{ __( 'WordPress.org', 'wpcore' ) }
						</ExternalLink>
					) : (
						<span className="wpcore-plugin__custom">
							{ __( 'Custom ZIP', 'wpcore' ) }
						</span>
					) }
					<code>{ plugin.slug }</code>
				</span>
				{ plugin.collections && plugin.collections.length > 1 && (
					<span className="wpcore-plugin__in">
						{ sprintf(
							/* translators: %s: collection names separated by commas. */
							__( 'In %s', 'wpcore' ),
							plugin.collections
								.map( ( collection ) =>
									decodeEntities( collection )
								)
								.join( ', ' )
						) }
					</span>
				) }
				{ change && (
					<span className="wpcore-plugin__change">
						<span
							className="dashicons dashicons-yes"
							aria-hidden="true"
						/>
						{ change }
					</span>
				) }
				{ progress && 'error' === progress.status && (
					<span className="wpcore-plugin__error">
						{ progress.message }
					</span>
				) }
			</span>
			<span className={ `wpcore-status is-${ state.tone }` }>
				{ 'progress' === state.tone ? (
					<Spinner />
				) : (
					<span
						className={ `dashicons dashicons-${ state.icon }` }
						aria-hidden="true"
					/>
				) }
				{ state.label }
			</span>
			<span className="wpcore-plugin__action">
				{ 'not_installed' === status && (
					<Button
						variant="secondary"
						disabled={ disabled }
						onClick={ () => onInstall( false ) }
					>
						{ __( 'Install', 'wpcore' ) }
					</Button>
				) }
				{ 'inactive' === status && (
					<Button
						variant="secondary"
						disabled={ disabled }
						onClick={ () => onInstall( true ) }
					>
						{ __( 'Activate', 'wpcore' ) }
					</Button>
				) }
			</span>
		</li>
	);
}
