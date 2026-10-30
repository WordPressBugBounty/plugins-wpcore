<?php
/**
 * WP-CLI command.
 *
 * @package WPCore
 */

namespace WPCore\Cli;

use WP_CLI;
use WP_Error;
use WPCore\Api\Client;
use WPCore\Api\Collection;
use WPCore\Api\CollectionPlugin;
use WPCore\Collections\Keys;
use WPCore\Installer\Installer;
use WPCore\Installer\PluginStatus;
use WPCore\Sites\Connection;
use WPCore\Sites\Pusher;

/**
 * Installs and lists the plugins in WPCore collections.
 *
 * On a site that is not connected to WPCore.com, the collection keys come from the command line. The command does not read
 * or change the saved keys in wpcore_keys. On a connected site, the command takes no keys and uses the collections assigned
 * to the site on WPCore.com.
 */
final class Command {

	/**
	 * Flag values that mean true. Plugin 1.x used --activate=yes, --network=yes and --force=yes.
	 */
	const TRUE_VALUES = array( 'yes', 'true', 'on', '1' );

	/**
	 * Flag values that mean false.
	 */
	const FALSE_VALUES = array( 'no', 'false', 'off', '0', '' );

	/**
	 * API client.
	 *
	 * @var Client
	 */
	private $client;

	/**
	 * Plugin installer.
	 *
	 * @var Installer
	 */
	private $installer;

	/**
	 * Creates the command. WP-CLI creates it with no arguments.
	 *
	 * @param Client|null    $client    API client.
	 * @param Installer|null $installer Plugin installer.
	 */
	public function __construct( ?Client $client = null, ?Installer $installer = null ) {
		$this->client    = $client ?? new Client();
		$this->installer = $installer ?? new Installer();
	}

	/**
	 * Registers the wpcore command with WP-CLI.
	 */
	public static function register(): void {
		WP_CLI::add_command( 'wpcore', self::class );
	}

	/**
	 * Installs the plugins in one or more WPCore collections.
	 *
	 * A site connected to WPCore.com installs the collections assigned to it on its site page, and takes no keys.
	 * A plugin listed in more than one collection is installed once. A plugin that is already installed is not downloaded again, unless --force is given.
	 *
	 * ## OPTIONS
	 *
	 * [<key>...]
	 * : Collection keys. The key is shown at the top of each collection page on wpcore.com. Leave out on a connected site.
	 *
	 * [--key=<key>]
	 * : A collection key, or several keys separated by commas. Plugin 1.x used this form. Leave out on a connected site.
	 *
	 * [--activate]
	 * : Activate the plugins after installing them.
	 *
	 * [--activate-network]
	 * : Activate the plugins for the whole multisite network.
	 *
	 * [--network]
	 * : The same as --activate-network. Plugin 1.x used this form.
	 *
	 * [--force]
	 * : Download the installed plugins again and replace their files.
	 *
	 * [--yes]
	 * : Install without asking for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpcore install 6ib8eOIBndO5u0DTrmOt --activate
	 *     wp wpcore install --key=6ib8eOIBndO5u0DTrmOt --activate=yes
	 *     wp wpcore install --activate
	 *
	 * @param string[]             $args       Collection keys.
	 * @param array<string, mixed> $assoc_args Options.
	 */
	public function install( array $args, array $assoc_args ): void {
		$connection = Connection::get();
		$options    = self::install_options( $args, $assoc_args, null === $connection );

		if ( is_wp_error( $options ) ) {
			WP_CLI::error( $options->get_error_message() );
			return;
		}

		if ( null !== $connection && array() !== $options['keys'] ) {
			WP_CLI::error( self::site_mode_message() );
			return;
		}

		if ( $options['network'] && ! is_multisite() ) {
			WP_CLI::error( __( 'Network activation needs a multisite network.', 'wpcore' ) );
			return;
		}

		$plugins = self::unique_plugins( null === $connection ? $this->collections( $options['keys'] ) : $this->site_collections() );

		if ( array() === $plugins ) {
			WP_CLI::error( __( 'The collections contain no plugins.', 'wpcore' ) );
			return;
		}

		self::print_plugins( $plugins, 'table' );
		$this->confirm( $plugins, $options, $assoc_args );

		$installed = 0;
		$activated = 0;
		$failed    = 0;

		foreach ( $plugins as $item ) {
			$plugin = $item['plugin'];
			$result = $this->installer->install( $plugin, $options['activate'], $options['network'], $options['force'] );

			if ( is_wp_error( $result ) ) {
				++$failed;
				/* translators: 1: plugin name, 2: error message. */
				WP_CLI::warning( sprintf( __( '%1$s: %2$s', 'wpcore' ), self::plain_name( $plugin ), $result->get_error_message() ) );
				continue;
			}

			$installed += $result['installed'] ? 1 : 0;
			$activated += $result['activated'] ? 1 : 0;

			WP_CLI::log( self::outcome( $plugin, $result, $options['activate'] ) );
		}

		/* translators: 1: number of installed plugins, 2: number of activated plugins, 3: number of failed plugins. */
		$summary = sprintf( __( 'Installed: %1$d. Activated: %2$d. Failed: %3$d.', 'wpcore' ), $installed, $activated, $failed );

		if ( $failed > 0 ) {
			WP_CLI::error( $summary );
			return;
		}

		WP_CLI::success( $summary );
	}

	/**
	 * Lists the plugins in one or more WPCore collections and their status on this site.
	 *
	 * A site connected to WPCore.com lists the collections assigned to it on its site page, and takes no keys.
	 *
	 * ## OPTIONS
	 *
	 * [<key>...]
	 * : Collection keys. Leave out on a connected site.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpcore list 6ib8eOIBndO5u0DTrmOt
	 *
	 * @subcommand list
	 *
	 * @param string[]             $args       Collection keys.
	 * @param array<string, mixed> $assoc_args Options.
	 */
	public function list_( array $args, array $assoc_args ): void {
		$connection = Connection::get();
		$keys       = self::keys( $args, array(), null === $connection );

		if ( is_wp_error( $keys ) ) {
			WP_CLI::error( $keys->get_error_message() );
			return;
		}

		if ( null !== $connection && array() !== $keys ) {
			WP_CLI::error( self::site_mode_message() );
			return;
		}

		$plugins = array();

		foreach ( null === $connection ? $this->collections( $keys ) : $this->site_collections() as $collection ) {
			foreach ( $collection->plugins() as $plugin ) {
				$plugins[] = array(
					'collection' => $collection,
					'plugin'     => $plugin,
				);
			}
		}

		self::print_plugins( $plugins, isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table' );
	}

	/**
	 * Reads the keys and options of the install command from the 2.0.0 and 1.x argument forms.
	 *
	 * --network and --activate-network both activate the plugins for the network, as --network=yes did in 1.x.
	 *
	 * @param string[]             $args          Positional arguments.
	 * @param array<string, mixed> $assoc_args    Options.
	 * @param bool                 $keys_required True when no keys is an error. A connected site takes no keys.
	 * @return array{keys: string[], activate: bool, network: bool, force: bool}|WP_Error
	 */
	public static function install_options( array $args, array $assoc_args, bool $keys_required = true ) {
		$keys = self::keys( $args, $assoc_args, $keys_required );

		if ( is_wp_error( $keys ) ) {
			return $keys;
		}

		$flags = array();

		foreach ( array( 'activate', 'activate-network', 'network', 'force' ) as $name ) {
			$flags[ $name ] = self::flag( $assoc_args, $name );

			if ( null === $flags[ $name ] ) {
				return new WP_Error(
					'wpcore_invalid_flag',
					/* translators: %s: option name. */
					sprintf( __( 'The --%s option takes no value, or yes or no.', 'wpcore' ), $name )
				);
			}
		}

		$network = $flags['activate-network'] || $flags['network'];

		return array(
			'keys'     => $keys,
			'activate' => $flags['activate'] || $network,
			'network'  => $network,
			'force'    => $flags['force'],
		);
	}

	/**
	 * Returns the collection keys from the positional arguments and the --key option, without repeats.
	 *
	 * @param string[]             $args          Positional arguments.
	 * @param array<string, mixed> $assoc_args    Options.
	 * @param bool                 $keys_required True when no keys is an error.
	 * @return string[]|WP_Error
	 */
	private static function keys( array $args, array $assoc_args, bool $keys_required ) {
		$values = $args;

		if ( isset( $assoc_args['key'] ) ) {
			if ( ! is_string( $assoc_args['key'] ) ) {
				return new WP_Error( 'wpcore_invalid_key', __( 'The --key option needs a collection key.', 'wpcore' ) );
			}

			$values = array_merge( $values, explode( ',', $assoc_args['key'] ) );
		}

		$keys = array();

		foreach ( $values as $value ) {
			$value = trim( (string) $value );

			if ( '' === $value ) {
				continue;
			}

			if ( ! Keys::is_valid( $value ) ) {
				return new WP_Error(
					'wpcore_invalid_key',
					/* translators: %s: the value given as a collection key. */
					sprintf( __( '"%s" is not a collection key. A key contains only letters, digits, hyphens and underscores.', 'wpcore' ), $value )
				);
			}

			$keys[] = $value;
		}

		$keys = Keys::normalize( $keys );

		if ( $keys_required && array() === $keys ) {
			return new WP_Error( 'wpcore_no_keys', __( 'Give at least one collection key.', 'wpcore' ) );
		}

		return $keys;
	}

	/**
	 * Returns the value of a flag.
	 *
	 * A missing flag is false. A flag with no value is true. A value in TRUE_VALUES or FALSE_VALUES is read without letter case.
	 *
	 * @param array<string, mixed> $assoc_args Options.
	 * @param string               $name       Flag name.
	 * @return bool|null Null for any other value.
	 */
	private static function flag( array $assoc_args, string $name ): ?bool {
		if ( ! isset( $assoc_args[ $name ] ) ) {
			return false;
		}

		$value = $assoc_args[ $name ];

		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$value = strtolower( trim( (string) $value ) );

		if ( in_array( $value, self::TRUE_VALUES, true ) ) {
			return true;
		}

		return in_array( $value, self::FALSE_VALUES, true ) ? false : null;
	}

	/**
	 * Requests the collections of the keys. Prints a warning for each key that matches no collection.
	 *
	 * Stops the command when the request fails or no key matches a collection.
	 *
	 * @param string[] $keys Collection keys.
	 * @return array<string, Collection> Collections keyed by key, in key order.
	 */
	private function collections( array $keys ): array {
		$collections = $this->client->get_collections( $keys );

		if ( is_wp_error( $collections ) ) {
			WP_CLI::error( $collections->get_error_message() );
			return array();
		}

		foreach ( $keys as $key ) {
			if ( ! isset( $collections[ $key ] ) ) {
				/* translators: %s: collection key. */
				WP_CLI::warning( sprintf( __( 'No collection has the key %s.', 'wpcore' ), $key ) );
			}
		}

		if ( array() === $collections ) {
			WP_CLI::error( __( 'No collection was found.', 'wpcore' ) );
		}

		return $collections;
	}

	/**
	 * Syncs with WPCore.com and returns the collections assigned to this site.
	 *
	 * Stops the command when the sync fails or no collection is assigned.
	 *
	 * @return array<string, Collection> Collections keyed by key, in the order they were assigned.
	 */
	private function site_collections(): array {
		$collections = Pusher::push();

		if ( is_wp_error( $collections ) ) {
			WP_CLI::error( $collections->get_error_message() );
			return array();
		}

		if ( array() === $collections ) {
			WP_CLI::error( __( 'No collections are assigned to this site. Assign collections on the site page on WPCore.com.', 'wpcore' ) );
		}

		return $collections;
	}

	/**
	 * Returns the error for collection keys given on a site connected to WPCore.com.
	 */
	private static function site_mode_message(): string {
		return __( 'This site is connected to WPCore.com and uses the collections assigned to it there. Run the command without collection keys.', 'wpcore' );
	}

	/**
	 * Returns the plugins of the collections. A slug listed in more than one collection is kept once, with the first collection.
	 *
	 * @param array<string, Collection> $collections Collections in key order.
	 * @return array<int, array{collection: Collection, plugin: CollectionPlugin}>
	 */
	private static function unique_plugins( array $collections ): array {
		$plugins = array();

		foreach ( $collections as $collection ) {
			foreach ( $collection->plugins() as $plugin ) {
				if ( ! isset( $plugins[ $plugin->slug() ] ) ) {
					$plugins[ $plugin->slug() ] = array(
						'collection' => $collection,
						'plugin'     => $plugin,
					);
				}
			}
		}

		return array_values( $plugins );
	}

	/**
	 * Prints the plugins with their collection, source and status on this site.
	 *
	 * @param array<int, array{collection: Collection, plugin: CollectionPlugin}> $plugins Plugins.
	 * @param string                                                              $format  WP-CLI output format.
	 */
	private static function print_plugins( array $plugins, string $format ): void {
		$rows = array();

		foreach ( $plugins as $item ) {
			$plugin = $item['plugin'];
			$rows[] = array(
				'collection' => $item['collection']->name(),
				'name'       => self::plain_name( $plugin ),
				'slug'       => $plugin->slug(),
				'source'     => $plugin->is_from_wordpress_org() ? 'wordpress.org' : (string) $plugin->source(),
				'status'     => PluginStatus::of( $plugin->slug() ),
			);
		}

		WP_CLI\Utils\format_items( $format, $rows, array( 'collection', 'name', 'slug', 'source', 'status' ) );
	}

	/**
	 * Asks for confirmation before the install. --yes skips the question.
	 *
	 * @param array<int, array{collection: Collection, plugin: CollectionPlugin}> $plugins    Plugins to install.
	 * @param array{keys: string[], activate: bool, network: bool, force: bool}   $options    Install options.
	 * @param array<string, mixed>                                                $assoc_args Options, read for --yes.
	 */
	private function confirm( array $plugins, array $options, array $assoc_args ): void {
		$count  = count( $plugins );
		$custom = 0;

		foreach ( $plugins as $item ) {
			$custom += $item['plugin']->is_from_wordpress_org() ? 0 : 1;
		}

		if ( $custom > 0 ) {
			/* translators: %d: number of plugins. */
			WP_CLI::warning( sprintf( _n( '%d plugin downloads from outside WordPress.org. The source column shows its URL.', '%d plugins download from outside WordPress.org. The source column shows their URLs.', $custom, 'wpcore' ), $custom ) );
		}

		if ( $options['force'] ) {
			WP_CLI::log( __( 'Installed plugins are downloaded again and their files are replaced.', 'wpcore' ) );
		}

		if ( $options['network'] ) {
			/* translators: %d: number of plugins. */
			$question = _n( 'Install and network activate %d plugin?', 'Install and network activate %d plugins?', $count, 'wpcore' );
		} elseif ( $options['activate'] ) {
			/* translators: %d: number of plugins. */
			$question = _n( 'Install and activate %d plugin?', 'Install and activate %d plugins?', $count, 'wpcore' );
		} else {
			/* translators: %d: number of plugins. */
			$question = _n( 'Install %d plugin?', 'Install %d plugins?', $count, 'wpcore' );
		}

		WP_CLI::confirm( sprintf( $question, $count ), $assoc_args );
	}

	/**
	 * Returns the line printed for a plugin that installed without an error.
	 *
	 * @param CollectionPlugin                        $plugin   Plugin.
	 * @param array{installed: bool, activated: bool} $result   Installer result.
	 * @param bool                                    $activate True when the command activates plugins.
	 */
	private static function outcome( CollectionPlugin $plugin, array $result, bool $activate ): string {
		if ( $result['installed'] && $result['activated'] ) {
			/* translators: %s: plugin name. */
			$message = __( '%s: installed and activated.', 'wpcore' );
		} elseif ( $result['installed'] ) {
			/* translators: %s: plugin name. */
			$message = __( '%s: installed.', 'wpcore' );
		} elseif ( $result['activated'] ) {
			/* translators: %s: plugin name. */
			$message = __( '%s: activated.', 'wpcore' );
		} elseif ( $activate ) {
			/* translators: %s: plugin name. */
			$message = __( '%s: already active.', 'wpcore' );
		} else {
			/* translators: %s: plugin name. */
			$message = __( '%s: already installed.', 'wpcore' );
		}

		return sprintf( $message, self::plain_name( $plugin ) );
	}

	/**
	 * Returns the plugin name with HTML entities decoded. wpcore.com stores some names with entities such as &amp;.
	 *
	 * @param CollectionPlugin $plugin Plugin.
	 */
	private static function plain_name( CollectionPlugin $plugin ): string {
		return html_entity_decode( $plugin->name(), ENT_QUOTES, 'UTF-8' );
	}
}
