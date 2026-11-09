<?php
/**
 * Class SystemDebugTest
 *
 * @package KHSE_Cache
 */

class SystemDebugTest extends WP_UnitTestCase {

	private $server_backup = array();

	public function set_up() {
		parent::set_up();

		$this->server_backup = $_SERVER;
		delete_option( 'khse_debug_mode' );

		add_filter( 'khse_debug_home_ip', static function () {
			return '192.168.128.4';
		} );
	}

	public function tear_down() {
		$_SERVER = $this->server_backup;

		delete_option( 'khse_debug_mode' );
		remove_all_filters( 'khse_debug_home_ip' );

		parent::tear_down();
	}

	public function test_debug_mode_toggle_is_stored() {
		$this->assertFalse( khse_is_debug_mode() );

		khse_set_debug_mode( true );
		$this->assertTrue( khse_is_debug_mode() );

		khse_set_debug_mode( false );
		$this->assertFalse( khse_is_debug_mode() );
	}

	public function test_direct_request_has_no_proxy_headers() {
		unset(
			$_SERVER['HTTP_X_FORWARDED_FOR'],
			$_SERVER['HTTP_X_FORWARDED_PROTO'],
			$_SERVER['HTTP_X_REAL_IP'],
			$_SERVER['HTTP_CF_RAY'],
			$_SERVER['HTTP_CF_CONNECTING_IP'],
			$_SERVER['HTTP_FORWARDED'],
			$_SERVER['HTTP_VIA']
		);
		$_SERVER['REMOTE_ADDR']     = '203.0.113.9';
		$_SERVER['SERVER_ADDR']     = '203.0.113.10';
		$_SERVER['SERVER_SOFTWARE'] = 'Apache';

		$proxy = khse_detect_proxy_context();

		$this->assertFalse( $proxy['behindProxy'] );
		$this->assertSame( 'none', $proxy['confidence'] );
		$this->assertNotEmpty( $proxy['warnings'] );
	}

	public function test_forwarded_headers_are_detected() {
		unset( $_SERVER['HTTP_CF_RAY'], $_SERVER['HTTP_CF_CONNECTING_IP'] );
		$_SERVER['HTTP_X_FORWARDED_FOR']   = '203.0.113.5';
		$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
		$_SERVER['HTTP_X_REAL_IP']         = '203.0.113.5';
		$_SERVER['REMOTE_ADDR']            = '192.168.128.2';
		$_SERVER['SERVER_ADDR']            = '192.168.128.3';
		$_SERVER['SERVER_SOFTWARE']        = 'nginx';

		$proxy = khse_detect_proxy_context();

		$this->assertTrue( $proxy['behindProxy'] );
		$this->assertSame( 'high', $proxy['confidence'] );
		$this->assertTrue( $proxy['homePrivate'] );
		$this->assertNotEmpty( $proxy['headers'] );
	}

	public function test_cloudflare_headers_are_identified() {
		$_SERVER['HTTP_CF_RAY']           = '8f2a1234-SJC';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.6';
		$_SERVER['REMOTE_ADDR']           = '192.168.128.2';
		$_SERVER['SERVER_ADDR']           = '192.168.128.3';
		$_SERVER['SERVER_SOFTWARE']        = 'nginx';

		$proxy = khse_detect_proxy_context();

		$this->assertSame( 'Cloudflare', $proxy['provider'] );
		$this->assertTrue( $proxy['behindProxy'] );
	}

	public function test_system_debug_payload_has_groups_proxy_and_log() {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$_SERVER['SERVER_ADDR'] = '203.0.113.10';

		$data = khse_get_system_debug_data();

		$this->assertArrayHasKey( 'debugEnabled', $data );
		$this->assertArrayHasKey( 'system', $data );
		$this->assertArrayHasKey( 'proxy', $data );
		$this->assertArrayHasKey( 'preloadLog', $data );
		$this->assertArrayHasKey( 'queue', $data );
		$this->assertNotEmpty( $data['system'] );

		$labels = array();

		foreach ( $data['system'] as $group ) {
			foreach ( $group['rows'] as $row ) {
				$labels[] = $row['label'];
			}
		}

		$this->assertContains( 'WordPress', $labels );
		$this->assertContains( 'PHP version', $labels );
		$this->assertContains( 'Queue backend', $labels );
		$this->assertNotContains( 'AUTH_KEY', $labels );
		$this->assertNotContains( 'DB_PASSWORD', $labels );
	}
}
