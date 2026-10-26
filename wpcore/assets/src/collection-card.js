/**
 * WordPress dependencies
 */
import { Button, ExternalLink, Modal } from '@wordpress/components';
// eslint-disable-next-line no-unused-vars
import { createElement, Fragment, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { rowId, statusCounts } from './install-queue';
import PluginRow from './plugin-row';

/**
 * Bar that shows the share of active and installed plugins in a collection.
 *
 * @param {Object} props        Component props.
 * @param {Object} props.counts Counts from statusCounts().
 * @param {number} props.total  Number of plugins in the collection.
 * @return {Element} Progress bar.
 */
function CoverageBar( { counts, total } ) {
	const percent = ( count ) => `${ ( count / total ) * 100 }%`;

	return (
		<div className="wpcore-coverage" aria-hidden="true">
			<span
				className="wpcore-coverage__active"
				style={ { width: percent( counts.active ) } }
			/>
			<span
				className="wpcore-coverage__inactive"
				style={ { width: percent( counts.inactive ) } }
			/>
		</div>
	);
}

/**
 * Card with the plugins of one collection.
 *
 * @param {Object}                                     props            Component props.
 * @param {Object}                                     props.collection Collection from the collections response.
 * @param {Object<string, string>}                     props.icons      WordPress.org icon URLs keyed by slug.
 * @param {Set<string>}                                props.selected   Selected row IDs.
 * @param {function(string[], boolean): void}          props.onSelect   Called with row IDs and true to select or false to clear.
 * @param {function(Object[], boolean): Promise<void>} props.onInstall  Installs plugins. Called with the plugins and true to activate them.
 * @param {Object}                                     props.progress   Install progress keyed by slug.
 * @param {function(): void}                           props.onRemove   Removes the collection key.
 * @param {boolean}                                    props.disabled   True while a request runs.
 * @return {Element} Collection card.
 */
export default function CollectionCard( {
	collection,
	icons,
	selected,
	onSelect,
	onInstall,
	progress,
	onRemove,
	disabled,
} ) {
	const [ confirming, setConfirming ] = useState( false );
	const name = decodeEntities( collection.name );
	const total = collection.plugins.length;
	const counts = statusCounts( collection.plugins );
	const installedCount = counts.active + counts.inactive;
	const selectableIds = collection.plugins
		.filter( ( plugin ) => 'active' !== plugin.status )
		.map( ( plugin ) => rowId( collection.key, plugin.slug ) );
	const selectedCount = selectableIds.filter( ( id ) =>
		selected.has( id )
	).length;
	const selectAllId = `wpcore-select-all-${ collection.key }`;
	const allSelected =
		selectableIds.length > 0 && selectedCount === selectableIds.length;

	return (
		<section className="wpcore-card wpcore-collection">
			<header className="wpcore-collection__header">
				<div className="wpcore-collection__title">
					<h2>{ name }</h2>
					<div className="wpcore-collection__links">
						<ExternalLink href={ collection.url }>
							{ __( 'View on WPCore.com', 'wpcore' ) }
						</ExternalLink>
						<Button
							variant="link"
							isDestructive
							onClick={ () => setConfirming( true ) }
							disabled={ disabled }
						>
							{ __( 'Remove', 'wpcore' ) }
						</Button>
					</div>
				</div>
				{ total > 0 && (
					<div className="wpcore-collection__summary">
						<p>
							<strong>
								{ sprintf(
									/* translators: 1: number of installed plugins, 2: number of plugins in the collection. */
									__( '%1$d of %2$d installed', 'wpcore' ),
									installedCount,
									total
								) }
							</strong>
							<span className="wpcore-legend is-active">
								{ sprintf(
									/* translators: %d: number of active plugins. */
									__( '%d active', 'wpcore' ),
									counts.active
								) }
							</span>
							<span className="wpcore-legend is-inactive">
								{ sprintf(
									/* translators: %d: number of installed plugins that are not active. */
									__( '%d inactive', 'wpcore' ),
									counts.inactive
								) }
							</span>
							<span className="wpcore-legend is-missing">
								{ sprintf(
									/* translators: %d: number of plugins that are not installed. */
									__( '%d not installed', 'wpcore' ),
									counts.not_installed
								) }
							</span>
						</p>
						<CoverageBar counts={ counts } total={ total } />
					</div>
				) }
			</header>

			{ 0 === total && (
				<p className="wpcore-collection__empty">
					{ __( 'This collection has no plugins.', 'wpcore' ) }
				</p>
			) }

			{ total > 0 && (
				<div className="wpcore-collection__bar">
					<span className="wpcore-collection__select-all">
						<input
							id={ selectAllId }
							type="checkbox"
							checked={ allSelected }
							disabled={ disabled || 0 === selectableIds.length }
							onChange={ ( event ) =>
								onSelect( selectableIds, event.target.checked )
							}
						/>
						<label htmlFor={ selectAllId }>
							{ 0 === selectableIds.length
								? __( 'All plugins are active', 'wpcore' )
								: __( 'Select all', 'wpcore' ) }
						</label>
						{ selectedCount > 0 && (
							<span className="wpcore-collection__selected">
								{ sprintf(
									/* translators: %d: number of selected plugins in this collection. */
									_n(
										'%d selected',
										'%d selected',
										selectedCount,
										'wpcore'
									),
									selectedCount
								) }
							</span>
						) }
					</span>
					{ counts.not_installed > 0 && (
						<Button
							variant="secondary"
							disabled={ disabled }
							onClick={ () =>
								onInstall(
									collection.plugins
										.filter(
											( plugin ) =>
												'not_installed' ===
												plugin.status
										)
										.map( ( plugin ) => ( {
											key: collection.key,
											slug: plugin.slug,
											name: plugin.name,
										} ) ),
									true
								)
							}
						>
							{ sprintf(
								/* translators: %d: number of plugins that are not installed. */
								__(
									'Install and activate missing (%d)',
									'wpcore'
								),
								counts.not_installed
							) }
						</Button>
					) }
				</div>
			) }

			{ total > 0 && (
				<ul className="wpcore-plugins">
					{ collection.plugins.map( ( plugin ) => {
						const id = rowId( collection.key, plugin.slug );

						return (
							<PluginRow
								key={ id }
								plugin={ plugin }
								icon={ icons[ plugin.slug ] }
								progress={ progress[ plugin.slug ] }
								selected={ selected.has( id ) }
								onSelect={ ( isSelected ) =>
									onSelect( [ id ], isSelected )
								}
								onInstall={ ( activate ) =>
									onInstall(
										[
											{
												key: collection.key,
												slug: plugin.slug,
												name: plugin.name,
											},
										],
										activate
									)
								}
								disabled={ disabled }
							/>
						);
					} ) }
				</ul>
			) }

			{ confirming && (
				<Modal
					title={ __( 'Remove this collection?', 'wpcore' ) }
					onRequestClose={ () => setConfirming( false ) }
					className="wpcore-modal"
				>
					<p>
						{ sprintf(
							/* translators: %s: collection name. */
							__(
								'%s will no longer be listed on this screen. Plugins from it stay installed. You can add the key again later.',
								'wpcore'
							),
							name
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
							onClick={ () => {
								setConfirming( false );
								onRemove();
							} }
						>
							{ __( 'Remove collection', 'wpcore' ) }
						</Button>
					</div>
				</Modal>
			) }
		</section>
	);
}
