<?php
/**
 * Intake session lifecycle service.
 *
 * @package ProseCore
 */

namespace Prose\Core\Intake;

use Prose\Core\Database\Repositories\CaseRepository;
use Prose\Core\Database\Repositories\EventRepository;
use Prose\Core\Database\Repositories\FactsRepository;
use Prose\Core\Database\Repositories\SessionRepository;

final class SessionService {

	public function __construct(
		private readonly CaseRepository $cases,
		private readonly SessionRepository $sessions,
		private readonly FactsRepository $facts,
		private readonly EventRepository $events
	) {}

	/**
	 * @return array<string, mixed>
	 */
	public function create( int $user_id, string $case_type = 'divorce' ): array {
		$case_id    = $this->cases->create( $user_id, $case_type );
		$session_id = $this->sessions->create( $case_id, $user_id );

		$this->events->append(
			$session_id,
			'session_created',
			array( 'case_id' => $case_id, 'case_type' => $case_type )
		);

		do_action( 'courtflow_session_created' );

		return array(
			'case_id'    => $case_id,
			'session_id' => $session_id,
			'facts'      => $this->facts->get( $session_id ),
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get( int $session_id, int $user_id ): ?array {
		$session = $this->sessions->find( $session_id );
		if ( ! $session || (int) $session['user_id'] !== $user_id ) {
			return null;
		}

		return array(
			'session' => $session,
			'facts'   => $this->facts->get( $session_id ),
			'messages' => $this->events->messages( $session_id ),
		);
	}
}
