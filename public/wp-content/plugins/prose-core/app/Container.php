<?php
/**
 * Lightweight PSR-11 dependency injection container.
 *
 * @package ProseCore
 */

namespace Prose\Core;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

/**
 * Simple service container with singleton bindings.
 */
final class Container implements ContainerInterface {

	/**
	 * @var array<string, mixed>
	 */
	private array $bindings = array();

	/**
	 * @var array<string, object>
	 */
	private array $instances = array();

	/**
	 * @var array<string, bool>
	 */
	private array $singletons = array();

	/**
	 * Bind a factory or concrete class.
	 *
	 * @param callable|string $concrete Factory or class name.
	 */
	public function bind( string $id, callable|string $concrete, bool $singleton = true ): void {
		$this->bindings[ $id ]  = $concrete;
		$this->singletons[ $id ] = $singleton;
		unset( $this->instances[ $id ] );
	}

	/**
	 * Register an existing instance.
	 */
	public function instance( string $id, object $instance ): void {
		$this->instances[ $id ]   = $instance;
		$this->singletons[ $id ]  = true;
		unset( $this->bindings[ $id ] );
	}

	public function get( string $id ): mixed {
		if ( isset( $this->instances[ $id ] ) ) {
			return $this->instances[ $id ];
		}

		if ( ! isset( $this->bindings[ $id ] ) ) {
			if ( class_exists( $id ) ) {
				$this->bindings[ $id ]  = $id;
				$this->singletons[ $id ] = true;
			} else {
				throw new class( "Service not found: {$id}" ) extends RuntimeException implements NotFoundExceptionInterface {};
			}
		}

		$concrete = $this->bindings[ $id ];
		$object   = is_callable( $concrete ) ? $concrete( $this ) : new $concrete();

		if ( $this->singletons[ $id ] ?? true ) {
			$this->instances[ $id ] = $object;
		}

		return $object;
	}

	public function has( string $id ): bool {
		return isset( $this->bindings[ $id ] ) || isset( $this->instances[ $id ] ) || class_exists( $id );
	}
}
