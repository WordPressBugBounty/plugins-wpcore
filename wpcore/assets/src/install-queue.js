/**
 * Selection and install order for the admin screen.
 */

/**
 * Returns each plugin of the collections once, in collection order.
 * A slug listed in more than one collection keeps the entry of the first collection, and that collection's key installs it.
 *
 * @param {Array<Object>} collections Collections from GET /wpcore/v1/collections.
 * @return {Array<Object>} Plugins with key, the collection that installs the plugin, and collections, the names of every collection that lists it.
 */
export function mergedPlugins( collections ) {
	const plugins = new Map();

	for ( const collection of collections ) {
		for ( const plugin of collection.plugins ) {
			if ( ! plugins.has( plugin.slug ) ) {
				plugins.set( plugin.slug, {
					...plugin,
					key: collection.key,
					collections: [],
				} );
			}

			plugins.get( plugin.slug ).collections.push( collection.name );
		}
	}

	return [ ...plugins.values() ];
}

/**
 * Returns the selected plugins in collection order, each slug once.
 *
 * @param {Array<Object>} collections Collections from GET /wpcore/v1/collections.
 * @param {Set<string>}   selected    Selected slugs.
 * @return {Array<{key: string, slug: string, name: string}>} Plugins to install.
 */
export function selectedPlugins( collections, selected ) {
	return mergedPlugins( collections )
		.filter( ( plugin ) => selected.has( plugin.slug ) )
		.map( ( { key, slug, name } ) => ( { key, slug, name } ) );
}

/**
 * Installs plugins one at a time. Each install writes to the plugins folder, so requests do not run in parallel.
 * A failed install does not stop the next one.
 *
 * @param {Array<Object>}                     plugins    Plugins from selectedPlugins().
 * @param {function(Object): Promise<Object>} installOne Installs one plugin and resolves with the REST response.
 * @param {function(string, Object): void}    onProgress Called with the slug and { status: 'installing' | 'done' | 'error', result, message }.
 * @return {Promise<void>} Resolves after the last plugin.
 */
export async function installInOrder( plugins, installOne, onProgress ) {
	for ( const plugin of plugins ) {
		onProgress( plugin.slug, { status: 'installing' } );

		try {
			const result = await installOne( plugin );
			onProgress( plugin.slug, { status: 'done', result } );
		} catch ( error ) {
			onProgress( plugin.slug, {
				status: 'error',
				message:
					error && error.message ? error.message : String( error ),
			} );
		}
	}
}

/**
 * Counts the plugins of one collection in each installed state.
 *
 * @param {Array<Object>} plugins Plugins of one collection from GET /wpcore/v1/collections.
 * @return {{active: number, inactive: number, not_installed: number}} Number of plugins per status.
 */
export function statusCounts( plugins ) {
	const counts = { active: 0, inactive: 0, not_installed: 0 };

	for ( const plugin of plugins ) {
		if ( plugin.status in counts ) {
			counts[ plugin.status ]++;
		}
	}

	return counts;
}

/**
 * Counts the results of an install run.
 *
 * @param {Object} progress Install progress keyed by slug, with the states that installInOrder() reports.
 * @return {{total: number, finished: number, installed: number, activated: number, failed: number}} Counts.
 */
export function progressCounts( progress ) {
	const counts = {
		total: 0,
		finished: 0,
		installed: 0,
		activated: 0,
		failed: 0,
	};

	for ( const state of Object.values( progress ) ) {
		counts.total++;

		if ( 'error' === state.status ) {
			counts.finished++;
			counts.failed++;
		}

		if ( 'done' === state.status ) {
			counts.finished++;
			counts.installed += state.result.installed ? 1 : 0;
			counts.activated += state.result.activated ? 1 : 0;
		}
	}

	return counts;
}
