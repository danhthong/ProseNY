<?php
/**
 * Custom capabilities and roles.
 *
 * @package ProseCore
 */

namespace Prose\Core\Security;

/**
 * Registers CourtFlow capabilities.
 */
final class Capabilities {

	public const ROLE_PARALEGAL = 'cf_paralegal';
	public const ROLE_REVIEWER  = 'cf_reviewer';

	/**
	 * @var array<string, array<string, bool>>
	 */
	private static array $caps = array(
		'cf_intake'          => array( 'read' => true ),
		'cf_admin_workflows' => array(),
		'cf_admin_rules'     => array(),
		'cf_admin_forms'     => array(),
		'cf_admin_sessions'  => array(),
		'cf_admin_audit'     => array(),
		'cf_admin_settings'  => array(),
	);

	public static function register(): void {
		self::add_admin_caps();
		self::add_roles();
	}

	private static function add_admin_caps(): void {
		$admin = get_role( 'administrator' );
		if ( ! $admin ) {
			return;
		}

		$all_caps = array(
			'cf_intake',
			'cf_admin_workflows',
			'cf_admin_rules',
			'cf_admin_forms',
			'cf_admin_sessions',
			'cf_admin_audit',
			'cf_admin_settings',
			'edit_cf_workflows',
			'edit_cf_forms',
			'edit_cf_questions',
			'edit_cf_counties',
			'edit_cf_courts',
		);

		foreach ( $all_caps as $cap ) {
			$admin->add_cap( $cap );
		}
	}

	private static function add_roles(): void {
		if ( ! get_role( self::ROLE_PARALEGAL ) ) {
			add_role(
				self::ROLE_PARALEGAL,
				__( 'CourtFlow Paralegal', 'prose-core' ),
				array(
					'read'               => true,
					'cf_admin_workflows' => true,
					'cf_admin_rules'     => true,
					'cf_admin_forms'     => true,
					'cf_admin_sessions'  => true,
					'edit_cf_workflows'  => true,
					'edit_cf_forms'      => true,
				)
			);
		}

		if ( ! get_role( self::ROLE_REVIEWER ) ) {
			add_role(
				self::ROLE_REVIEWER,
				__( 'CourtFlow Reviewer', 'prose-core' ),
				array(
					'read'              => true,
					'cf_admin_sessions' => true,
					'cf_admin_audit'    => true,
				)
			);
		}

		$subscriber = get_role( 'subscriber' );
		if ( $subscriber ) {
			$subscriber->add_cap( 'cf_intake' );
		}
	}

	public static function user_can_intake(): bool {
		return current_user_can( 'cf_intake' ) || current_user_can( 'manage_options' );
	}
}
