<?php
/**
 * Per-session AI budget guardrails.
 *
 * @package ProseCore
 */

namespace Prose\Core\AI\Gateway;

use Prose\Core\Database\Repositories\AuditRepository;
use Prose\Core\Support\Config;
use RuntimeException;

final class BudgetGuard {

	public function __construct(
		private readonly AuditRepository $audit
	) {}

	public function check( int $session_id, int $case_id ): void {
		$token_budget = (int) Config::get( 'session_token_budget', 50000 );
		$cost_budget    = (float) Config::get( 'session_cost_budget', 2.0 );

		$cost = $this->audit->total_cost_for_case( $case_id );

		if ( $cost >= $cost_budget ) {
			throw new RuntimeException( 'Session AI cost budget exceeded.' );
		}

		$key = 'courtflow_tokens_' . $session_id;
		$used = (int) get_transient( $key );

		if ( $used >= $token_budget ) {
			throw new RuntimeException( 'Session token budget exceeded.' );
		}
	}

	public function record_usage( int $session_id, int $tokens ): void {
		$key  = 'courtflow_tokens_' . $session_id;
		$used = (int) get_transient( $key );
		set_transient( $key, $used + $tokens, DAY_IN_SECONDS );
	}
}
