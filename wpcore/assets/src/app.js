/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { Button, Notice, Spinner } from '@wordpress/components';
import {
	// eslint-disable-next-line no-unused-vars
	createElement,
	Fragment,
	useCallback,
	useEffect,
	useState,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import AddKeyForm from './add-key-form';
import CollectionCard from './collection-card';
import {
	installInOrder,
	progressCounts,
	selectedPlugins,
} from './install-queue';

const ROUTE = '/wpcore/v1';

/**
 * Returns the sentence that reports the result of an install run.
 *
 * @param {Object} counts Counts from progressCounts().
 * @return {string} Result sentence.
 */
function summaryText( counts ) {
	const parts = [];

	if ( counts.installed > 0 ) {
		parts.push(
			sprintf(
				/* translators: %d: number of plugins. */
				_n(
					'%d plugin installed.',
					'%d plugins installed.',
					counts.installed,
					'wpcore'
				),
				counts.installed
			)
		);
	}

	if ( counts.activated > 0 ) {
		parts.push(
			sprintf(
				/* translators: %d: number of plugins. */
				_n(
					'%d plugin activated.',
					'%d plugins activated.',
					counts.activated,
					'wpcore'
				),
				counts.activated
			)
		);
	}

	if ( counts.failed > 0 ) {
		parts.push(
			sprintf(
				/* translators: %d: number of plugins. */
				_n(
					'%d plugin failed. The error is shown on its row.',
					'%d plugins failed. The errors are shown on their rows.',
					counts.failed,
					'wpcore'
				),
				counts.failed
			)
		);
	}

	if ( 0 === parts.length ) {
		return __( 'The selected plugins were already installed.', 'wpcore' );
	}

	return parts.join( ' ' );
}

/**
 * The WPCore admin screen.
 *
 * @param {Object} props                 Component props, from window.wpcoreAdmin.
 * @param {string} props.exportActiveUrl wpcore.com link that creates a collection from the active plugins.
 * @param {string} props.exportAllUrl    wpcore.com link that creates a collection from all installed plugins.
 * @param {string} props.createUrl       wpcore.com page that creates an empty collection.
 * @return {Element} Admin screen.
 */
export default function App( { exportActiveUrl, exportAllUrl, createUrl } ) {
	const [ data, setData ] = useState( null );
	const [ busy, setBusy ] = useState( true );
	const [ installing, setInstalling ] = useState( false );
	const [ showSummary, setShowSummary ] = useState( false );
	const [ requestError, setRequestError ] = useState( null );
	const [ selected, setSelected ] = useState( () => new Set() );
	const [ progress, setProgress ] = useState( {} );
	const [ icons, setIcons ] = useState( {} );

	/**
	 * Sends a request that returns the collections response, and shows the result.
	 *
	 * @param {Object} options apiFetch options.
	 * @return {Promise<boolean>} True when the request succeeded.
	 */
	const request = useCallback( async ( options ) => {
		setBusy( true );

		try {
			setData( await apiFetch( options ) );
			setRequestError( null );
			return true;
		} catch ( error ) {
			setRequestError( error.message || String( error ) );
			return false;
		} finally {
			setBusy( false );
		}
	}, [] );

	const loadCollections = useCallback(
		( refresh = false ) =>
			request( {
				path: `${ ROUTE }/collections${ refresh ? '?refresh=true' : '' }`,
			} ),
		[ request ]
	);

	useEffect( () => {
		loadCollections();
	}, [ loadCollections ] );

	const collectionKeys = data
		? data.collections.map( ( collection ) => collection.key ).join( ',' )
		: '';

	// Icons load after the collections, so a slow WordPress.org request does not delay the plugin list.
	useEffect( () => {
		if ( '' === collectionKeys ) {
			return;
		}

		apiFetch( { path: `${ ROUTE }/icons` } )
			.then( ( response ) => setIcons( response.icons ) )
			.catch( () => {} );
	}, [ collectionKeys ] );

	const addKey = ( key ) =>
		request( {
			path: `${ ROUTE }/collections`,
			method: 'POST',
			data: { key },
		} );

	const removeKey = ( key ) =>
		request( {
			path: `${ ROUTE }/collections/${ encodeURIComponent( key ) }`,
			method: 'DELETE',
		} );

	const select = ( ids, isSelected ) => {
		setSelected( ( previous ) => {
			const next = new Set( previous );
			ids.forEach( ( id ) =>
				isSelected ? next.add( id ) : next.delete( id )
			);
			return next;
		} );
	};

	/**
	 * Installs plugins one at a time and reloads the collections afterwards.
	 *
	 * @param {Array<Object>} plugins  Plugins with key, slug and name.
	 * @param {boolean}       activate True to activate each plugin.
	 */
	const install = async ( plugins, activate ) => {
		setBusy( true );
		setInstalling( true );
		setShowSummary( false );
		setProgress(
			Object.fromEntries(
				plugins.map( ( plugin ) => [
					plugin.slug,
					{ status: 'queued' },
				] )
			)
		);

		await installInOrder(
			plugins,
			( plugin ) =>
				apiFetch( {
					path: `${ ROUTE }/install`,
					method: 'POST',
					data: { key: plugin.key, slug: plugin.slug, activate },
				} ),
			( slug, state ) =>
				setProgress( ( previous ) => ( {
					...previous,
					[ slug ]: state,
				} ) )
		);

		setSelected( new Set() );
		await loadCollections();
		setInstalling( false );
		setShowSummary( true );
	};

	const selectedList = data
		? selectedPlugins( data.collections, selected )
		: [];
	const counts = progressCounts( progress );
	const hasKeys =
		data &&
		( data.collections.length > 0 || data.not_found_keys.length > 0 );
	const hasCustom =
		data &&
		data.collections.some( ( collection ) =>
			collection.plugins.some(
				( plugin ) => 'wordpress.org' !== plugin.source_type
			)
		);

	return (
		<div className="wpcore-admin">
			<header className="wpcore-header">
				<div>
					<h1>{ __( 'WPCore', 'wpcore' ) }</h1>
					<p>
						{ __(
							'Install the plugins from your WPCore.com collections.',
							'wpcore'
						) }
					</p>
				</div>
				{ data && data.collections.length > 0 && (
					<Button
						variant="secondary"
						icon="update"
						disabled={ busy }
						onClick={ () => loadCollections( true ) }
					>
						{ __( 'Refresh from WPCore.com', 'wpcore' ) }
					</Button>
				) }
			</header>

			{ requestError && (
				<Notice
					status="error"
					onRemove={ () => setRequestError( null ) }
				>
					{ requestError }
				</Notice>
			) }

			{ data && data.error && (
				<Notice status="warning" isDismissible={ false }>
					{ sprintf(
						/* translators: %s: error message. */
						__(
							'Some collections could not be loaded: %s',
							'wpcore'
						),
						data.error.message
					) }
				</Notice>
			) }

			{ showSummary && counts.total > 0 && (
				<Notice
					status={ counts.failed > 0 ? 'warning' : 'success' }
					onRemove={ () => setShowSummary( false ) }
				>
					{ summaryText( counts ) }
				</Notice>
			) }

			<AddKeyForm onAdd={ addKey } disabled={ busy } />

			{ ! data && busy && (
				<div className="wpcore-card wpcore-loading">
					<Spinner />
					{ __( 'Loading your collections…', 'wpcore' ) }
				</div>
			) }

			{ data && ! hasKeys && (
				<div className="wpcore-card wpcore-empty">
					<span
						className="dashicons dashicons-portfolio"
						aria-hidden="true"
					/>
					<h2>{ __( 'No collections yet', 'wpcore' ) }</h2>
					<p>
						{ __(
							'Add a collection key above to list its plugins here.',
							'wpcore'
						) }
					</p>
					<Button
						variant="secondary"
						href={ createUrl }
						target="_blank"
						rel="noreferrer"
					>
						{ __( 'Create a collection on WPCore.com', 'wpcore' ) }
					</Button>
				</div>
			) }

			{ data &&
				data.not_found_keys.map( ( key ) => (
					<div key={ key } className="wpcore-card wpcore-missing">
						<span
							className="dashicons dashicons-warning"
							aria-hidden="true"
						/>
						<p>
							{ sprintf(
								/* translators: %s: collection key. */
								__(
									'No collection on WPCore.com has the key %s.',
									'wpcore'
								),
								key
							) }
						</p>
						<Button
							variant="link"
							isDestructive
							disabled={ busy }
							onClick={ () => removeKey( key ) }
						>
							{ __( 'Remove key', 'wpcore' ) }
						</Button>
					</div>
				) ) }

			{ data &&
				data.collections.map( ( collection ) => (
					<CollectionCard
						key={ collection.key }
						collection={ collection }
						icons={ icons }
						selected={ selected }
						onSelect={ select }
						onInstall={ install }
						progress={ progress }
						onRemove={ () => removeKey( collection.key ) }
						disabled={ busy }
					/>
				) ) }

			{ hasCustom && (
				<p className="description">
					{ __(
						'Custom ZIP plugins download from the file URL saved on WPCore.com. They do not come from the WordPress.org plugin directory.',
						'wpcore'
					) }
				</p>
			) }

			<section className="wpcore-card wpcore-export">
				<div>
					<h2>
						{ __( 'Export plugins to a collection', 'wpcore' ) }
					</h2>
					<p>
						{ __(
							'Create a WPCore.com collection from the plugins on this site.',
							'wpcore'
						) }
					</p>
				</div>
				<div className="wpcore-export__actions">
					<Button
						variant="secondary"
						href={ exportActiveUrl }
						target="_blank"
					>
						{ __( 'Export active plugins', 'wpcore' ) }
					</Button>
					<Button
						variant="secondary"
						href={ exportAllUrl }
						target="_blank"
					>
						{ __( 'Export all plugins', 'wpcore' ) }
					</Button>
				</div>
			</section>

			{ ( installing || selectedList.length > 0 ) && (
				<div
					className="wpcore-selection-bar"
					role="region"
					aria-label={ __( 'Selected plugins', 'wpcore' ) }
				>
					{ installing ? (
						<div className="wpcore-selection-bar__progress">
							<Spinner />
							<span aria-live="polite">
								{ sprintf(
									/* translators: 1: number of finished plugins, 2: number of plugins in this run. */
									__( 'Installing %1$d of %2$d…', 'wpcore' ),
									Math.min(
										counts.finished + 1,
										counts.total
									),
									counts.total
								) }
							</span>
							<span className="wpcore-meter" aria-hidden="true">
								<span
									style={ {
										width: `${
											( counts.finished /
												Math.max( counts.total, 1 ) ) *
											100
										}%`,
									} }
								/>
							</span>
						</div>
					) : (
						<>
							<strong>
								{ sprintf(
									/* translators: %d: number of selected plugins. */
									_n(
										'%d plugin selected',
										'%d plugins selected',
										selectedList.length,
										'wpcore'
									),
									selectedList.length
								) }
							</strong>
							<Button
								variant="tertiary"
								disabled={ busy }
								onClick={ () => setSelected( new Set() ) }
							>
								{ __( 'Clear selection', 'wpcore' ) }
							</Button>
							<span className="wpcore-selection-bar__actions">
								<Button
									variant="secondary"
									disabled={ busy }
									onClick={ () =>
										install( selectedList, false )
									}
								>
									{ __( 'Install', 'wpcore' ) }
								</Button>
								<Button
									variant="primary"
									disabled={ busy }
									onClick={ () =>
										install( selectedList, true )
									}
								>
									{ __( 'Install and activate', 'wpcore' ) }
								</Button>
							</span>
						</>
					) }
				</div>
			) }
		</div>
	);
}
