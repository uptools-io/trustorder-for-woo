<?php
/**
 * wp trustorder settings get|set.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\CLI;

use LightweightPlugins\TrustOrder\CLI\SettingsCommand;
use LightweightPlugins\TrustOrder\Settings;
use WP_CLI;

final class StdinSettingsCommand extends SettingsCommand {
	public ?string $stdin = null;

	protected function stdin(): ?string {
		return $this->stdin;
	}
}

final class SettingsCommandTest extends CliTestCase {

	private StdinSettingsCommand $cmd;

	protected function setUp(): void {
		parent::setUp();
		$this->cmd = new StdinSettingsCommand();
		trustorder_test_wc()->payment_gateways->all = [
			'bacs' => new \WC_Payment_Gateway( 'bacs', 'yes', 'Bank transfer' ),
			'cod'  => new \WC_Payment_Gateway( 'cod', 'yes', 'Cash on delivery' ),
		];
	}

	public function test_get_lists_every_setting_and_only_the_key_prefix(): void {
		Settings::update( [ 'api_key' => self::LIVE_KEY ] );

		$this->assertSame( 0, $this->exec( fn() => $this->cmd->get( [], [] ) ) );

		$rows = array_column( $this->table(), 'value', 'setting' );
		$this->assertSame( substr( self::LIVE_KEY, 0, 12 ), $rows['api-key'] );
		$this->assertSame( 'normal', $rows['strictness'] );
		$this->assertSame( 'cod', $rows['hidden-gateways'] );
		$this->assertSame( 'yes', $rows['enabled'] );
		$this->assertStringNotContainsString( self::LIVE_KEY, $this->output() );
	}

	public function test_get_one_key_prints_the_plain_value(): void {
		$this->assertSame( 0, $this->exec( fn() => $this->cmd->get( [ 'strictness' ], [] ) ) );
		$this->assertSame( [ 'normal' ], WP_CLI::$lines );
	}

	public function test_get_json_and_unknown_key_and_bad_format(): void {
		$this->assertSame( 0, $this->exec( fn() => $this->cmd->get( [], [ 'format' => 'json' ] ) ) );
		$this->assertSame( 'normal', $this->json()['strictness'] );
		$this->assertSame( 1, $this->exec( fn() => $this->cmd->get( [ 'nope' ], [] ) ) );
		$this->assertSame( 1, $this->exec( fn() => $this->cmd->get( [], [ 'format' => 'xml' ] ) ) );
	}

	public function test_set_delegates_to_the_shared_validation(): void {
		$this->assertSame( 0, $this->exec( fn() => $this->cmd->set( [ 'strictness', 'strict' ], [] ) ) );
		$this->assertSame( 'strict', Settings::get()['strictness'] );

		$this->assertSame( 0, $this->exec( fn() => $this->cmd->set( [ 'enabled', 'off' ], [] ) ) );
		$this->assertFalse( Settings::get()['enabled'] );

		$this->assertSame( 0, $this->exec( fn() => $this->cmd->set( [ 'pickup-methods', 'local_pickup, vp_pont' ], [] ) ) );
		$this->assertSame( [ 'local_pickup', 'vp_pont' ], Settings::get()['pickup_methods'] );

		$this->assertSame( 0, $this->exec( fn() => $this->cmd->set( [ 'fallback-gateways', 'bacs' ], [] ) ) );
		$this->assertSame( [ 'bacs' ], Settings::get()['fallback_gateways'] );
	}

	public function test_set_rejects_invalid_input_and_saves_nothing(): void {
		$before = get_option( Settings::OPTION );

		$this->assertSame( 1, $this->exec( fn() => $this->cmd->set( [ 'strictness', 'extreme' ], [] ) ) );
		$this->assertSame( 1, $this->exec( fn() => $this->cmd->set( [ 'enabled', 'maybe' ], [] ) ) );
		$this->assertSame( 1, $this->exec( fn() => $this->cmd->set( [ 'hidden-gateways', 'made_up' ], [] ) ) );
		$this->assertSame( 1, $this->exec( fn() => $this->cmd->set( [ 'colour', 'red' ], [] ) ) );
		$this->assertSame( 1, $this->exec( fn() => $this->cmd->set( [ 'strictness' ], [] ) ) );
		$this->assertSame( $before, get_option( Settings::OPTION ) );
	}

	public function test_a_gateway_in_both_lists_is_refused_with_its_id(): void {
		Settings::update( [ 'fallback_gateways' => [ 'bacs' ] ] );

		$this->assertSame( 1, $this->exec( fn() => $this->cmd->set( [ 'hidden-gateways', 'bacs' ], [] ) ) );
		$this->assertStringContainsString( 'bacs', WP_CLI::$errors[0] );
	}

	public function test_the_api_key_is_never_accepted_as_an_argument(): void {
		$this->cmd->stdin = self::LIVE_KEY;

		$this->assertSame( 1, $this->exec( fn() => $this->cmd->set( [ 'api-key', self::LIVE_KEY ], [] ) ) );
		$this->assertSame( 1, $this->exec( fn() => $this->cmd->set( [ 'strictness', 'strict' ], [ 'api-key' => self::LIVE_KEY ] ) ) );
		$this->assertSame( 1, $this->exec( fn() => $this->cmd->set( [ 'api-key', '' ], [] ) ) );
		$this->assertSame( 1, $this->exec( fn() => $this->cmd->set( [ 'api-key' ], [ 'api_key' => self::LIVE_KEY ] ) ) );
		$this->assertSame( self::TEST_KEY, Settings::get()['api_key'] );
		$this->assertStringNotContainsString( self::LIVE_KEY, $this->output() );
		$this->assertStringContainsString( 'STDIN', WP_CLI::$errors[0] );
	}

	public function test_wp_cli_prompt_does_not_open_an_argument_path(): void {
		WP_CLI::$config['prompt'] = true; // WP-CLI would then fill the positional with the typed text.

		$this->assertSame( 1, $this->exec( fn() => $this->cmd->set( [ 'api-key', self::LIVE_KEY ], [] ) ) );

		$this->assertSame( self::TEST_KEY, Settings::get()['api_key'] );
	}

	public function test_the_api_key_comes_from_stdin(): void {
		$this->cmd->stdin = self::LIVE_KEY . "\n";

		$this->assertSame( 0, $this->exec( fn() => $this->cmd->set( [ 'api-key', '-' ], [] ) ) );

		$this->assertSame( self::LIVE_KEY, Settings::get()['api_key'] );
		$out = $this->output();
		$this->assertStringContainsString( substr( self::LIVE_KEY, 0, 12 ), $out );
		$this->assertStringNotContainsString( self::LIVE_KEY, $out );
	}

	public function test_a_bad_api_key_from_stdin_is_refused_without_echoing_it(): void {
		$this->cmd->stdin = "to_live_short\n";

		$this->assertSame( 1, $this->exec( fn() => $this->cmd->set( [ 'api-key', '-' ], [] ) ) );

		$this->assertSame( self::TEST_KEY, Settings::get()['api_key'] );
		$this->assertStringNotContainsString( 'to_live_short', $this->output() );
	}

	public function test_without_piped_stdin_the_key_command_explains_itself(): void {
		$this->cmd->stdin = null; // A terminal, or nothing within 5 seconds.

		$this->assertSame( 1, $this->exec( fn() => $this->cmd->set( [ 'api-key', '-' ], [] ) ) );
		$this->assertStringContainsString( 'no input within 5 seconds', WP_CLI::$errors[0] );
		$this->assertStringContainsString( 'STDIN', WP_CLI::$errors[0] );
		$this->assertSame( self::TEST_KEY, Settings::get()['api_key'] );
	}

	public function test_an_empty_stdin_removes_the_key(): void {
		$this->cmd->stdin = "\n";

		$this->assertSame( 0, $this->exec( fn() => $this->cmd->set( [ 'api-key', '-' ], [] ) ) );
		$this->assertSame( '', Settings::get()['api_key'] );
	}
}
