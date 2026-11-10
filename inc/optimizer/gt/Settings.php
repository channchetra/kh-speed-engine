<?php
namespace KHSE\Optimization;

/**
 * Settings bridge for the GT-style optimizers.
 *
 * Maps the optimizer's settings names onto KHSE's page optimization option.
 *
 * @package   KH Speed Engine
 * @license   GPLv3 (or later)
 */

if ( ! defined( 'KHSE_INC' ) ) {
	die;
}

if ( ! defined( 'KHSE_OPTIMIZATION_NS' ) ) {
	define( 'KHSE_OPTIMIZATION_NS', 'KHSE\\Optimization' );
}

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'KHSE\\Optimization\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require $file;
		}
	},
	true,
	true
);

if ( ! class_exists( 'KHSE\\Optimization\\Settings' ) ) {
	/**
	 * Read optimizer settings from the KHSE option.
	 */
	final class Settings {
		/**
		 * Map optimizer keys to KHSE option keys.
		 *
		 * @var array<string, string>
		 */
		private const MAP = array(
			'css.enabled'                => 'css_enabled',
			'css.mode'                   => 'css_mode',
			'css.critical_budget'        => 'css_critical_budget',
			'css.keep_dynamic_states'    => 'css_keep_dynamic_states',
			'css.rollout_percent'        => 'css_rollout_percent',
			'css.safelist'               => 'css_safelist',
			'css.excluded_stylesheets'   => 'css_excluded_stylesheets',
			'javascript.minify'          => 'javascript_minify',
			'javascript.defer'           => 'javascript_defer',
			'javascript.delay'           => 'javascript_delay',
			'javascript.exclusions'      => 'javascript_exclusions',
			'javascript.delay_patterns'  => 'javascript_delay_patterns',
		);

		/**
		 * List-valued keys.
		 *
		 * @var list<string>
		 */
		private const LISTS = array(
			'css.safelist',
			'css.excluded_stylesheets',
			'javascript.exclusions',
			'javascript.delay_patterns',
		);

		/**
		 * Boolean keys.
		 *
		 * @var list<string>
		 */
		private const BOOLEANS = array(
			'css.enabled',
			'css.keep_dynamic_states',
			'javascript.minify',
			'javascript.defer',
			'javascript.delay',
		);

		/**
		 * Return one optimizer setting.
		 *
		 * @param string $key     Setting key.
		 * @param mixed  $default Default.
		 *
		 * @return mixed
		 */
		public static function get( $key, $default = null ) {
			if ( 'debug' === $key ) {
				return function_exists( 'khse_is_debug_mode' ) && khse_is_debug_mode();
			}

			if ( 'generation' === $key ) {
				return 1;
			}

			if ( ! isset( self::MAP[ $key ] ) ) {
				return $default;
			}

			$settings = function_exists( 'khse_get_page_optimization_settings' )
				? khse_get_page_optimization_settings()
				: array();
			$value    = $settings[ self::MAP[ $key ] ] ?? $default;

			if ( in_array( $key, self::LISTS, true ) ) {
				if ( is_string( $value ) ) {
					$value = preg_split( '/\r\n|\r|\n/', $value );
				}

				return array_values( array_filter( array_map( 'strval', (array) $value ), 'strlen' ) );
			}

			if ( in_array( $key, self::BOOLEANS, true ) ) {
				return 'yes' === $value || true === $value || '1' === (string) $value;
			}

			return $value;
		}
	}
}
