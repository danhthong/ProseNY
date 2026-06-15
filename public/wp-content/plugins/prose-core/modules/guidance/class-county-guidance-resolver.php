<?php
/**
 * County Guidance Resolver — county-specific procedural notes.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Guidance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class County_Guidance_Resolver
 */
final class County_Guidance_Resolver {

	/**
	 * Supported NYC borough counties.
	 *
	 * @var string[]
	 */
	private const SUPPORTED_COUNTIES = array(
		'Kings',
		'Queens',
		'Bronx',
		'New York',
		'Richmond',
	);

	/**
	 * Guidance repository.
	 *
	 * @var Guidance_Repository
	 */
	private Guidance_Repository $repository;

	/**
	 * Validator.
	 *
	 * @var Validator
	 */
	private Validator $validator;

	/**
	 * Constructor.
	 *
	 * @param Guidance_Repository|null $repository Guidance repository.
	 * @param Validator|null           $validator  Validator.
	 */
	public function __construct( ?Guidance_Repository $repository = null, ?Validator $validator = null ) {
		$this->repository = $repository ?? new Guidance_Repository();
		$this->validator  = $validator ?? new Validator();
	}

	/**
	 * Resolve county-specific guidance.
	 *
	 * @param string $county County name.
	 * @return array{county_guidance: array<string, mixed>, warnings: array<int, array<string, mixed>>}
	 */
	public function resolve( string $county ): array {
		$county = trim( $county );

		if ( '' === $county ) {
			return array(
				'county_guidance' => array(),
				'warnings'        => array(),
			);
		}

		$canonical = $this->canonical_county_name( $county );
		$data      = $this->repository->read_county( $county );

		if ( null === $data ) {
			return array(
				'county_guidance' => $this->empty_scaffold( $canonical ),
				'warnings'        => array(),
			);
		}

		return array(
			'county_guidance' => $data,
			'warnings'        => $this->validator->validate_county_guidance( $canonical, $data ),
		);
	}

	/**
	 * List supported county names.
	 *
	 * @return string[]
	 */
	public function supported_counties(): array {
		return self::SUPPORTED_COUNTIES;
	}

	/**
	 * Normalize county name to canonical form when possible.
	 *
	 * @param string $county County name.
	 * @return string
	 */
	private function canonical_county_name( string $county ): string {
		$slug = $this->repository->county_slug( $county );

		$map = array(
			'kings'     => 'Kings',
			'queens'    => 'Queens',
			'bronx'     => 'Bronx',
			'new-york'  => 'New York',
			'richmond'  => 'Richmond',
			'manhattan' => 'New York',
			'brooklyn'  => 'Kings',
			'staten-island' => 'Richmond',
		);

		return $map[ $slug ] ?? $county;
	}

	/**
	 * Empty county guidance scaffold.
	 *
	 * @param string $county County name.
	 * @return array{county: string, filing_notes: string[], special_requirements: string[]}
	 */
	private function empty_scaffold( string $county ): array {
		return array(
			'county'               => $county,
			'filing_notes'         => array(),
			'special_requirements' => array(),
		);
	}
}
