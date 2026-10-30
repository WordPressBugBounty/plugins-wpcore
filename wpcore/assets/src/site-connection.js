/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	ExternalLink,
	Modal,
	Notice,
	TextControl,
} from '@wordpress/components';
import {
	// eslint-disable-next-line no-unused-vars
	createElement,
	Fragment,
	useEffect,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

const ROUTE = '/wpcore/v1/site-connection';

/**
 * Returns the label and tone of the connection status badge.
 *
 * @param {Object} status Connection status from the site-connection route.
 * @return {Object} Label and the wpcore-status tone.
 */
function badge( status ) {
	if ( ! status.connected ) {
		return { label: __( 'Not connected', 'wpcore' ), tone: 'neutral' };
	}

	if ( status.last_push_error ) {
		return { label: __( 'Last report failed', 'wpcore' ), tone: 'error' };
	}

	return { label: __( 'Connected', 'wpcore' ), tone: 'success' };
}

/**
 * Connection to the Sites page on WPCore.com, in the right column of the WPCore screen.
 *
 * The box shows a status badge, the connection key form or the connection details.
 * The connection key comes from the site page on WPCore.com. The site sends no data until a key is saved.
 * Connecting switches the screen to the collections assigned on WPCore.com. Disconnecting switches it back to the saved keys.
 *
 * @param {Object}           props              Component props.
 * @param {function(): void} props.onModeChange Called after the site connects or disconnects.
 * @return {Element} Site connection box.
 */
export default function SiteConnection( { onModeChange } ) {
	const [ status, setStatus ] = useState( null );
	const [ key, setKey ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ confirming, setConfirming ] = useState( false );

	useEffect( () => {
		apiFetch( { path: ROUTE } )
			.then( setStatus )
			.catch( ( caught ) =>
				setError( caught.message || String( caught ) )
			);
	}, [] );

	/**
	 * Sends a request that returns the connection status, and shows the result.
	 *
	 * @param {Object} options apiFetch options.
	 * @return {Promise<boolean>} True when the request succeeded.
	 */
	const request = async ( options ) => {
		setBusy( true );

		try {
			setStatus( await apiFetch( options ) );
			setError( null );
			return true;
		} catch ( caught ) {
			setError( caught.message || String( caught ) );
			return false;
		} finally {
			setBusy( false );
		}
	};

	const connect = async ( event ) => {
		event.preventDefault();

		if ( '' === key.trim() ) {
			return;
		}

		if (
			await request( {
				path: ROUTE,
				method: 'POST',
				data: { key: key.trim() },
			} )
		) {
			setKey( '' );
			onModeChange();
		}
	};

	const current = status ? badge( status ) : null;

	return (
		<>
			<section
				className="wpcore-card wpcore-site"
				aria-labelledby="wpcore-site-title"
			>
				<div className="wpcore-site__header">
					<h2 id="wpcore-site-title">
						{ __( 'Site connection', 'wpcore' ) }
					</h2>
					{ current && (
						<span
							className={ `wpcore-status is-${ current.tone }` }
						>
							{ current.label }
						</span>
					) }
				</div>

				{ error && (
					<Notice status="error" onRemove={ () => setError( null ) }>
						{ error }
					</Notice>
				) }

				{ status && ! status.https && (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'This site does not use HTTPS. The connection key and the reports travel without encryption, so anyone on the network path can read them. With the key, they can send false reports for this site.',
							'wpcore'
						) }
					</Notice>
				) }

				{ status && ! status.connected && (
					<form onSubmit={ connect }>
						<p>
							{ __(
								"Add this site on the Sites page of WPCore.com and paste its connection key here. WPCore.com then shows this site's WordPress, PHP, database and web server versions, and its plugins and themes. The plugin sends this data only after you save a key. A connected site lists the collections assigned to it on WPCore.com instead of the collection keys saved here.",
								'wpcore'
							) }
						</p>
						<div className="wpcore-add-key__row">
							<TextControl
								label={ __( 'Connection key', 'wpcore' ) }
								hideLabelFromVision
								placeholder={ __(
									'Paste the connection key',
									'wpcore'
								) }
								value={ key }
								onChange={ setKey }
								disabled={ busy }
								autoComplete="off"
								spellCheck={ false }
							/>
							<Button
								variant="primary"
								type="submit"
								disabled={ busy || '' === key.trim() }
							>
								{ __( 'Connect', 'wpcore' ) }
							</Button>
						</div>
					</form>
				) }

				{ status && status.connected && (
					<>
						<p>
							{ __(
								'This site is connected to WPCore.com.',
								'wpcore'
							) }{ ' ' }
							<ExternalLink href={ status.site_url }>
								{ __(
									'Open the site page on WPCore.com',
									'wpcore'
								) }
							</ExternalLink>
						</p>
						<p className="description">
							{ status.last_push_at
								? sprintf(
										/* translators: %s: date and time. */
										__(
											'Last report sent from this site: %s',
											'wpcore'
										),
										new Date(
											status.last_push_at
										).toLocaleString()
									)
								: __(
										'No report sent from this site yet.',
										'wpcore'
									) }
						</p>
						{ status.last_push_error && (
							<Notice status="warning" isDismissible={ false }>
								{ sprintf(
									/* translators: %s: error message. */
									__(
										'The last report failed: %s',
										'wpcore'
									),
									status.last_push_error
								) }
							</Notice>
						) }
						<p className="description">
							{ __(
								'This site sends a report after plugins or themes change. WPCore.com also requests a report regularly.',
								'wpcore'
							) }
						</p>
						<div className="wpcore-site__actions">
							<Button
								variant="secondary"
								disabled={ busy }
								onClick={ () =>
									request( {
										path: `${ ROUTE }/report`,
										method: 'POST',
									} )
								}
							>
								{ __( 'Send a report now', 'wpcore' ) }
							</Button>
							<Button
								variant="tertiary"
								isDestructive
								disabled={ busy }
								onClick={ () => setConfirming( true ) }
							>
								{ __( 'Disconnect', 'wpcore' ) }
							</Button>
						</div>
					</>
				) }
			</section>

			{ confirming && (
				<Modal
					title={ __( 'Disconnect from WPCore.com?', 'wpcore' ) }
					onRequestClose={ () => setConfirming( false ) }
					className="wpcore-modal"
				>
					<p>
						{ __(
							'This site deletes its connection key and stops sending reports. WPCore.com marks the site as disconnected, stops requesting reports and keeps the last report until you delete the site there. The WPCore screen then lists the collections whose keys are saved on this site, instead of the collections assigned on WPCore.com.',
							'wpcore'
						) }
					</p>
					<div className="wpcore-modal__actions">
						<Button
							variant="tertiary"
							onClick={ () => setConfirming( false ) }
						>
							{ __( 'Cancel', 'wpcore' ) }
						</Button>
						<Button
							variant="primary"
							isDestructive
							onClick={ async () => {
								setConfirming( false );

								if (
									await request( {
										path: ROUTE,
										method: 'DELETE',
									} )
								) {
									onModeChange();
								}
							} }
						>
							{ __( 'Disconnect', 'wpcore' ) }
						</Button>
					</div>
				</Modal>
			) }
		</>
	);
}
