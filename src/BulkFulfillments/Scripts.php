<?php

namespace Vendidero\Shiptastic\BulkFulfillments;

defined( 'ABSPATH' ) || exit;

class Scripts {

	public static function init() {
		add_action(
			'woocommerce_shiptastic_fulfillment_print_scripts',
			function () {
				$stc_fulfillment_scripts = self::scripts();

				$stc_fulfillment_scripts->do_head_items();
				$stc_fulfillment_scripts->reset();

				return $stc_fulfillment_scripts->done;
			}
		);

		add_action(
			'woocommerce_shiptastic_fulfillment_print_footer_scripts',
			function () {
				global $stc_fulfillment_scripts;

				$stc_fulfillment_scripts->do_footer_items();
				$stc_fulfillment_scripts->reset();

				return $stc_fulfillment_scripts->done;
			}
		);

		self::script_modules()->add_hooks();

		add_action( 'woocommerce_shiptastic_fulfillment_default_scripts', array( __CLASS__, 'default_scripts' ) );
		add_action( 'woocommerce_shiptastic_fulfillment_default_scripts', array( __CLASS__, 'default_script_modules' ) );
	}

	/**
	 * Initializes $stc_fulfillment_scripts if it has not been set.
	 *
	 * @global FulfillmentScripts $stc_fulfillment_scripts
	 *
	 * @return FulfillmentScripts WP_Scripts instance.
	 */
	public static function scripts() {
		global $stc_fulfillment_scripts;

		if ( ! ( $stc_fulfillment_scripts instanceof FulfillmentScripts ) ) {
			$stc_fulfillment_scripts = new FulfillmentScripts();
		}

		return $stc_fulfillment_scripts;
	}

	/**
	 * Enqueues a script.
	 *
	 * Registers the script if `$src` provided (does NOT overwrite), and enqueues it.
	 *
	 * @see WP_Dependencies::add()
	 * @see WP_Dependencies::add_data()
	 * @see WP_Dependencies::enqueue()
	 *
	 * @param string           $handle Name of the script. Should be unique.
	 * @param string           $src    Full URL of the script, or path of the script relative to the WordPress root directory.
	 *                                 Default empty.
	 * @param string[]         $deps   Optional. An array of registered script handles this script depends on. Default empty array.
	 * @param string|bool|null $ver    Optional. String specifying script version number, if it has one, which is added to the URL
	 *                                 as a query string for cache busting purposes. If version is set to false, a version
	 *                                 number is automatically added equal to current installed WordPress version.
	 *                                 If set to null, no version is added.
	 * @param array|bool $args {
	 *     Optional. An array of extra args for the script. Default empty array.
	 *     Otherwise, it may be a boolean in which case it determines whether the script is printed in the footer. Default false.
	 *
	 *     @type string $strategy            Optional. If provided, may be either 'defer' or 'async'.
	 *     @type bool   $in_footer           Optional. Whether to print the script in the footer. Default 'false'.
	 *     @type string $fetchpriority       Optional. The fetch priority for the script. Default 'auto'.
	 *     @type array  $module_dependencies Optional. IDs for module dependencies loaded via dynamic import. Default empty array.
	 *                                       For the full data format, see the `$deps` param of {@see wp_register_script_module()}.
	 *                                       When provided, the script must either be printed in the footer (with
	 *                                       `in_footer` set to true) or use a deferred loading `strategy` (`defer`),
	 *                                       so that the script modules import map is printed before the script
	 *                                       is evaluated. Otherwise dynamic imports may fail to resolve.
	 * }
	 *
	 * @phpstan-param non-empty-string $handle
	 * @phpstan-param string $src
	 * @phpstan-param non-empty-string[] $deps
	 * @phpstan-param array{
	 *     in_footer?: bool,
	 *     strategy?: 'async'|'defer',
	 *     fetchpriority?: 'low'|'auto'|'high',
	 *     module_dependencies?: array<non-empty-string|array{ id: non-empty-string, ... }>,
	 * }|bool $args
	 */
	public static function enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $args = array() ) {
		_wp_scripts_maybe_doing_it_wrong( __FUNCTION__, $handle );

		$stc_fulfillment_scripts = self::scripts();

		if ( $src || ! empty( $args ) ) {
			/** @var array{ 0: non-empty-string, 1?: string } $_handle */
			$_handle = explode( '?', $handle );
			if ( ! is_array( $args ) ) {
				$args = array(
					'in_footer' => (bool) $args,
				);
			}

			if ( $src ) {
				$stc_fulfillment_scripts->add( $_handle[0], $src, $deps, $ver );
			}
			if ( ! empty( $args ) ) {
				_wp_scripts_add_args_data( $stc_fulfillment_scripts, $_handle[0], $args );
			}
		}

		$stc_fulfillment_scripts->enqueue( $handle );
	}

	/**
	 * Removes a previously enqueued script.
	 *
	 * @see WP_Dependencies::dequeue()
	 *
	 * @since 3.1.0
	 *
	 * @param string $handle Name of the script to be removed.
	 */
	public static function dequeue_script( $handle ) {
		_wp_scripts_maybe_doing_it_wrong( __FUNCTION__, $handle );

		self::scripts()->dequeue( $handle );
	}

	/**
	 * Determines whether a script has been added to the queue.
	 *
	 * For more information on this and similar theme functions, check out
	 * the {@link https://developer.wordpress.org/themes/basics/conditional-tags/
	 * Conditional Tags} article in the Theme Developer Handbook.
	 *
	 * @since 2.8.0
	 * @since 3.5.0 'enqueued' added as an alias of the 'queue' list.
	 *
	 * @param string $handle Name of the script.
	 * @param string $status Optional. Status of the script to check. Default 'enqueued'.
	 *                       Accepts 'enqueued', 'registered', 'queue', 'to_do', and 'done'.
	 * @return bool Whether the script is queued.
	 */
	public static function script_is( $handle, $status = 'enqueued' ) {
		_wp_scripts_maybe_doing_it_wrong( __FUNCTION__, $handle );

		return (bool) self::scripts()->query( $handle, $status );
	}

	/**
	 * Adds metadata to a script.
	 *
	 * Works only if the script has already been registered.
	 *
	 * Possible values for $key and $value:
	 * 'strategy' string 'defer' or 'async'.
	 *
	 * @since 4.2.0
	 * @since 6.9.0 Updated possible values to remove reference to 'conditional' and add 'strategy'.
	 *
	 * @see WP_Dependencies::add_data()
	 *
	 * @param string $handle Name of the script.
	 * @param string $key    Name of data point for which we're storing a value.
	 * @param mixed  $value  String containing the data to be added.
	 * @return bool True on success, false on failure.
	 */
	function script_add_data( $handle, $key, $value ) {
		return self::scripts()->add_data( $handle, $key, $value );
	}

	/**
	 * Removes a registered script.
	 *
	 * Note: there are intentional safeguards in place to prevent critical admin scripts,
	 * such as jQuery core, from being unregistered.
	 *
	 * @see WP_Dependencies::remove()
	 *
	 * @since 2.1.0
	 *
	 * @global string $pagenow The filename of the current screen.
	 *
	 * @param string $handle Name of the script to be removed.
	 */
	public static function deregister_script( $handle ) {
		global $pagenow;

		_wp_scripts_maybe_doing_it_wrong( __FUNCTION__, $handle );

		self::scripts()->remove( $handle );
	}

	/**
	 * Localizes a script.
	 *
	 * Works only if the script has already been registered.
	 *
	 * Accepts an associative array `$l10n` and creates a JavaScript object:
	 *
	 *     "$object_name": {
	 *         key: value,
	 *         key: value,
	 *         ...
	 *     }
	 *
	 * @see WP_Scripts::localize()
	 * @link https://core.trac.wordpress.org/ticket/11520
	 *
	 * @since 2.2.0
	 *
	 * @todo Documentation cleanup
	 *
	 * @param string               $handle      Script handle the data will be attached to.
	 * @param string               $object_name Name for the JavaScript object. Passed directly, so it should be qualified JS variable.
	 *                                          Example: '/[a-zA-Z0-9_]+/'.
	 * @param array<string, mixed> $l10n        The data itself. The data can be either a single or multi-dimensional array.
	 * @return bool True if the script was successfully localized, false otherwise.
	 */
	public static function localize_script( $handle, $object_name, $l10n ) {
		return self::scripts()->localize( $handle, $object_name, $l10n );
	}

	/**
	 * Sets translated strings for a script.
	 *
	 * Works only if the script has already been registered.
	 *
	 * @see WP_Scripts::set_translations()
	 * @since 5.0.0
	 * @since 5.1.0 The `$domain` parameter was made optional.
	 *
	 * @global WP_Scripts $wp_scripts The WP_Scripts object for printing scripts.
	 *
	 * @param string $handle Script handle the textdomain will be attached to.
	 * @param string $domain Optional. Text domain. Default 'default'.
	 * @param string $path   Optional. The full file path to the directory containing translation files.
	 * @return bool True if the text domain was successfully localized, false otherwise.
	 */
	public static function set_script_translations( $handle, $domain = 'default', $path = '' ) {
		return self::scripts()->set_translations( $handle, $domain, $path );
	}

	/**
	 * Prints scripts in document head that are in the $handles queue.
	 *
	 * Called by admin-header.php and {@see 'wp_head'} hook. Since it is called by wp_head on every page load,
	 * the function does not instantiate the WP_Scripts object unless script names are explicitly passed.
	 * Makes use of already-instantiated `$wp_scripts` global if present. Use provided {@see 'wp_print_scripts'}
	 * hook to register/enqueue new scripts.
	 *
	 * @see WP_Scripts::do_item()
	 * @since 2.1.0
	 *
	 * @global WP_Scripts $wp_scripts The WP_Scripts object for printing scripts.
	 *
	 * @param string|string[]|false $handles Optional. Scripts to be printed. Default 'false'.
	 * @return string[] On success, an array of handles of processed WP_Dependencies items; otherwise, an empty array.
	 */
	public static function print_scripts( $handles = false ) {
		/**
		 * Fires before scripts in the $handles queue are printed.
		 *
		 * @since 2.1.0
		 */
		do_action( 'woocommerce_shiptastic_bulk_fulfillments_print_scripts' );

		return self::scripts()->do_items( $handles );
	}

	/**
	 * Adds extra code to a registered script.
	 *
	 * Code will only be added if the script is already in the queue.
	 * Accepts a string `$data` containing the code. If two or more code blocks
	 * are added to the same script `$handle`, they will be printed in the order
	 * they were added, i.e. the latter added code can redeclare the previous.
	 *
	 * @since 4.5.0
	 *
	 * @see WP_Scripts::add_inline_script()
	 *
	 * @param string $handle   Name of the script to add the inline script to.
	 * @param string $data     String containing the JavaScript to be added.
	 * @param string $position Optional. Whether to add the inline script before the handle
	 *                         or after. Default 'after'.
	 * @return bool True on success, false on failure.
	 */
	public static function add_inline_script( $handle, $data, $position = 'after' ) {
		_wp_scripts_maybe_doing_it_wrong( __FUNCTION__, $handle );

		if ( false !== stripos( $data, '</script>' ) ) {
			$data = trim( (string) preg_replace( '#<script[^>]*>(.*)</script>#is', '$1', $data ) );
		}

		return self::scripts()->add_inline_script( $handle, $data, $position );
	}

	/**
	 * Registers a new script.
	 *
	 * Registers a script to be enqueued later using the wp_enqueue_script() function.
	 *
	 * @see WP_Dependencies::add()
	 * @see WP_Dependencies::add_data()
	 *
	 * @since 2.1.0
	 * @since 4.3.0 A return value was added.
	 * @since 6.3.0 The $in_footer parameter of type boolean was overloaded to be an $args parameter of type array.
	 * @since 6.9.0 The $fetchpriority parameter of type string was added to the $args parameter of type array.
	 * @since 7.0.0 The $module_dependencies parameter of type string[] was added to the $args parameter of type array.
	 *
	 * @param string           $handle Name of the script. Should be unique.
	 * @param string|false     $src    Full URL of the script, or path of the script relative to the WordPress root directory.
	 *                                 If source is set to false, script is an alias of other scripts it depends on.
	 * @param string[]         $deps   Optional. An array of registered script handles this script depends on. Default empty array.
	 * @param string|bool|null $ver    Optional. String specifying script version number, if it has one, which is added to the URL
	 *                                 as a query string for cache busting purposes. If version is set to false, a version
	 *                                 number is automatically added equal to current installed WordPress version.
	 *                                 If set to null, no version is added.
	 * @param array|bool       $args   {
	 *     Optional. An array of extra args for the script. Default empty array.
	 *     Otherwise, it may be a boolean in which case it determines whether the script is printed in the footer. Default false.
	 *
	 *     @type string $strategy            Optional. If provided, may be either 'defer' or 'async'.
	 *     @type bool   $in_footer           Optional. Whether to print the script in the footer. Default 'false'.
	 *     @type string $fetchpriority       Optional. The fetch priority for the script. Default 'auto'.
	 *     @type array  $module_dependencies Optional. IDs for module dependencies loaded via dynamic import. Default empty array.
	 *                                                                    For the full data format, see the `$deps` param of {@see wp_register_script_module()}.
	 *                                                                    When provided, the script must either be printed in the footer (with
	 *                                                                    `in_footer` set to true) or use a deferred loading `strategy` (`defer`),
	 *                                                                    so that the script modules import map is printed before the script
	 *                                                                    is evaluated. Otherwise dynamic imports may fail to resolve.
	 * }
	 * @return bool Whether the script has been registered. True on success, false on failure.
	 *
	 * @phpstan-param non-empty-string $handle
	 * @phpstan-param non-empty-string|false $src
	 * @phpstan-param non-empty-string[] $deps
	 * @phpstan-param array{
	 *     in_footer?: bool,
	 *     strategy?: 'async'|'defer',
	 *     fetchpriority?: 'low'|'auto'|'high',
	 *     module_dependencies?: array<non-empty-string|array{ id: non-empty-string, ... }>,
	 * }|bool $args
	 */
	public static function register_script( $handle, $src, $deps = array(), $ver = false, $args = array() ) {
		if ( ! is_array( $args ) ) {
			$args = array(
				'in_footer' => (bool) $args,
			);
		}
		_wp_scripts_maybe_doing_it_wrong( __FUNCTION__, $handle );

		$registered = self::scripts()->add( $handle, $src, $deps, $ver );
		_wp_scripts_add_args_data( self::scripts(), $handle, $args );

		return $registered;
	}

	public static function default_scripts( $scripts ) {
		wp_default_packages_vendor( $scripts );
		wp_register_development_scripts( $scripts );
		wp_default_packages_scripts( $scripts );

		if ( did_action( 'init' ) ) {
			wp_default_packages_inline_scripts( $scripts );
		}
	}

	/**
	 * Retrieves the main WP_Script_Modules instance.
	 *
	 * This function provides access to the WP_Script_Modules instance, creating one
	 * if it doesn't exist yet.
	 *
	 * @global FulfillmentScriptModules $stc_fulfillments_script_modules
	 *
	 * @return FulfillmentScriptModules The main WP_Script_Modules instance.
	 */
	public static function script_modules() {
		global $stc_fulfillments_script_modules;

		if ( ! ( $stc_fulfillments_script_modules instanceof FulfillmentScriptModules ) ) {
			$stc_fulfillments_script_modules = new FulfillmentScriptModules();
		}

		return $stc_fulfillments_script_modules;
	}

	/**
	 * Registers the script module if no script module with that script module
	 * identifier has already been registered.
	 *
	 * @since 6.5.0
	 * @since 6.9.0 Added the $args parameter.
	 *
	 * @param string                              $id      The identifier of the script module. Should be unique. It will be used in the
	 *                                                     final import map.
	 * @param string                              $src     Optional. Full URL of the script module, or path of the script module relative
	 *                                                     to the WordPress root directory. If it is provided and the script module has
	 *                                                     not been registered yet, it will be registered.
	 * @param array<string|array<string, string>> $deps    {
	 *                                                         Optional. List of dependencies.
	 *
	 *                                                         @type string|array<string, string> ...$0 {
	 *                                                             An array of script module identifiers of the dependencies of this script
	 *                                                             module. The dependencies can be strings or arrays. If they are arrays,
	 *                                                             they need an `id` key with the script module identifier, and can contain
	 *                                                             an `import` key with either `static` or `dynamic`. By default,
	 *                                                             dependencies that don't contain an `import` key are considered static.
	 *
	 *                                                             @type string $id     The script module identifier.
	 *                                                             @type string $import Optional. Import type. May be either `static` or
	 *                                                                                  `dynamic`. Defaults to `static`.
	 *                                                         }
	 *                                                     }
	 * @param string|false|null                   $version Optional. String specifying the script module version number. Defaults to false.
	 *                                                     It is added to the URL as a query string for cache busting purposes. If $version
	 *                                                     is set to false, the version number is the currently installed WordPress version.
	 *                                                     If $version is set to null, no version is added.
	 * @param array<string, string|bool>          $args    {
	 *     Optional. An array of additional args. Default empty array.
	 *
	 *     @type bool                $in_footer     Whether to print the script module in the footer. Only relevant to block themes. Default 'false'. Optional.
	 *     @type 'auto'|'low'|'high' $fetchpriority Fetch priority. Default 'auto'. Optional.
	 * }
	 */
	public static function register_script_module( string $id, string $src, array $deps = array(), $version = false, array $args = array() ) {
		self::script_modules()->register( $id, $src, $deps, $version, $args );
	}

	/**
	 * Marks the script module to be enqueued in the page.
	 *
	 * If a src is provided and the script module has not been registered yet, it
	 * will be registered.
	 *
	 * @since 6.5.0
	 * @since 6.9.0 Added the $args parameter.
	 *
	 * @param string                              $id      The identifier of the script module. Should be unique. It will be used in the
	 *                                                     final import map.
	 * @param string                              $src     Optional. Full URL of the script module, or path of the script module relative
	 *                                                     to the WordPress root directory. If it is provided and the script module has
	 *                                                     not been registered yet, it will be registered.
	 * @param array<string|array<string, string>> $deps    {
	 *                                                         Optional. List of dependencies.
	 *
	 *                                                         @type string|array<string, string> ...$0 {
	 *                                                             An array of script module identifiers of the dependencies of this script
	 *                                                             module. The dependencies can be strings or arrays. If they are arrays,
	 *                                                             they need an `id` key with the script module identifier, and can contain
	 *                                                             an `import` key with either `static` or `dynamic`. By default,
	 *                                                             dependencies that don't contain an `import` key are considered static.
	 *
	 *                                                             @type string $id     The script module identifier.
	 *                                                             @type string $import Optional. Import type. May be either `static` or
	 *                                                                                  `dynamic`. Defaults to `static`.
	 *                                                         }
	 *                                                     }
	 * @param string|false|null                   $version Optional. String specifying the script module version number. Defaults to false.
	 *                                                     It is added to the URL as a query string for cache busting purposes. If $version
	 *                                                     is set to false, the version number is the currently installed WordPress version.
	 *                                                     If $version is set to null, no version is added.
	 * @param array<string, string|bool>          $args    {
	 *     Optional. An array of additional args. Default empty array.
	 *
	 *     @type bool                $in_footer     Whether to print the script module in the footer. Only relevant to block themes. Default 'false'. Optional.
	 *     @type 'auto'|'low'|'high' $fetchpriority Fetch priority. Default 'auto'. Optional.
	 * }
	 */
	public static function enqueue_script_module( string $id, string $src = '', array $deps = array(), $version = false, array $args = array() ) {
		self::script_modules()->enqueue( $id, $src, $deps, $version, $args );
	}

	/**
	 * Unmarks the script module so it is no longer enqueued in the page.
	 *
	 * @since 6.5.0
	 *
	 * @param string $id The identifier of the script module.
	 */
	public static function dequeue_script_module( string $id ) {
		self::script_modules()->dequeue( $id );
	}

	/**
	 * Deregisters the script module.
	 *
	 * @since 6.5.0
	 *
	 * @param string $id The identifier of the script module.
	 */
	public static function deregister_script_module( string $id ) {
		self::script_modules()->deregister( $id );
	}

	/**
	 * Overrides the text domain and path used to load translations for a script module.
	 *
	 * Translations for script modules are loaded automatically from the default
	 * text domain and language directory. Use this function only when a module's
	 * text domain differs from `'default'` or when translation files live outside
	 * the standard location, for example plugin modules using their own text domain.
	 *
	 * @since 7.0.0
	 *
	 * @see WP_Script_Modules::set_translations()
	 *
	 * @param string $id     The identifier of the script module.
	 * @param string $domain Optional. Text domain. Default 'default'.
	 * @param string $path   Optional. The full file path to the directory containing translation files.
	 * @return bool True if the text domain was registered, false if the module is not registered.
	 */
	public static function set_script_module_translations( string $id, string $domain = 'default', string $path = '' ): bool {
		return self::script_modules()->set_translations( $id, $domain, $path );
	}

	/**
	 * Registers all the default WordPress Script Modules.
	 *
	 * @since 6.7.0
	 */
	public static function default_script_modules() {
		$suffix = defined( 'WP_RUN_CORE_TESTS' ) ? '.min' : wp_scripts_get_suffix();

		/*
		 * Expects multidimensional array like:
		 *
		 *     'interactivity/index.js' => array('dependencies' => array(…), 'version' => '…'),
		 *     'interactivity-router/index.js' => array('dependencies' => array(…), 'version' => '…'),
		 *     'block-library/navigation/view.js' => …
		 */
		$assets_file = ABSPATH . WPINC . '/assets/script-modules-packages.php';
		$assets      = file_exists( $assets_file ) ? include $assets_file : array();

		foreach ( $assets as $file_name => $script_module_data ) {
			/*
			 * Build the WordPress Script Module ID from the file name.
			 * Prepend `@wordpress/` and remove extensions and `/index` if present:
			 *   - interactivity/index.min.js         => @wordpress/interactivity
			 *   - interactivity-router/index.min.js  => @wordpress/interactivity-router
			 *   - block-library/navigation/view.js   => @wordpress/block-library/navigation/view
			 */
			$script_module_id = '@wordpress/' . preg_replace( '~(?:/index)?(?:\.min)?\.js$~D', '', $file_name, 1 );

			/*
			 * The Interactivity API is designed with server-side rendering as its primary goal, so all of its script modules
			 * should be loaded with low fetchpriority and printed in the footer since they should not be needed in the
			 * critical rendering path. Also, the @wordpress/a11y script module is intended to be used as a dynamic import
			 * dependency, in which case the fetchpriority is irrelevant. See <https://make.wordpress.org/core/2024/10/14/updates-to-script-modules-in-6-7/>.
			 * However, in case it is added as a static import dependency, the fetchpriority is explicitly set to be 'low'
			 * since the module should not be involved in the critical rendering path, and if it is, its fetchpriority will
			 * be bumped to match the fetchpriority of the dependent script.
			 */
			$args = array();
			if (
				str_starts_with( $script_module_id, '@wordpress/interactivity' ) ||
				str_starts_with( $script_module_id, '@wordpress/block-library' ) ||
				'@wordpress/a11y' === $script_module_id
			) {
				$args['fetchpriority'] = 'low';
				$args['in_footer']     = true;
			}

			// Marks all Core blocks as compatible with client-side navigation.
			if ( str_starts_with( $script_module_id, '@wordpress/block-library' ) ) {
				wp_interactivity()->add_client_navigation_support_to_script_module( $script_module_id );
			}

			// VIPS files and the video-conversion worker are always minified — the
			// non-minified versions are not shipped because they consist of large
			// inlined WASM/worker code with no debugging value.
			if ( str_starts_with( $file_name, 'vips/' ) || 'video-conversion/worker.js' === $file_name ) {
				$file_name = str_replace( '.js', '.min.js', $file_name );
			} elseif ( '' !== $suffix ) {
				$file_name = str_replace( '.js', $suffix . '.js', $file_name );
			}

			$path        = includes_url( "js/dist/script-modules/{$file_name}" );
			$module_deps = $script_module_data['module_dependencies'] ?? array();
			self::register_script_module( $script_module_id, $path, $module_deps, $script_module_data['version'], $args );
		}
	}
}
