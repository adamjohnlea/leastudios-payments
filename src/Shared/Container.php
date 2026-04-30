<?php
/**
 * Tiny service-locator container with lazy resolution.
 *
 * @package LEAStudios\Payments\Shared
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Shared;

defined( 'ABSPATH' ) || exit;

use RuntimeException;

/**
 * Holds a map of `id => factory` and resolves each factory at most once,
 * caching the result. Factories receive the container itself as their only
 * argument so they can pull dependencies via {@see Container::get()}.
 *
 * Why not autowiring: this codebase wires ~25 services in a fixed shape,
 * and the constructor argument lists are stable. Explicit factory closures
 * give us clear, greppable wiring without reflection magic and without a
 * vendored library — a deliberate fit for the per-plugin self-contained
 * deployment model.
 *
 * Example:
 *
 * ```php
 * $c = new Container();
 * $c->set( 'logger', static fn() => new Logger() );
 * $c->set( 'service', static fn( Container $c ) => new Service( $c->get( 'logger' ) ) );
 * $service = $c->get( 'service' );
 * ```
 *
 * Resolution is lazy and idempotent: a service is built the first time
 * `get()` is called for it, and every subsequent `get()` returns the same
 * instance. There is no scope/lifetime concept — every service is a
 * singleton within the container's lifetime (which matches the plugin's
 * request lifetime).
 */
final class Container {

	/**
	 * Service factories keyed by id.
	 *
	 * @var array<string, callable(self): mixed>
	 */
	private array $factories = [];

	/**
	 * Cached, already-resolved instances keyed by id.
	 *
	 * @var array<string, mixed>
	 */
	private array $instances = [];

	/**
	 * Register a factory for a service id.
	 *
	 * @param string                $id      Service identifier (free-form string; convention is snake_case).
	 * @param callable(self): mixed $factory Closure invoked with `$this` to build the instance.
	 *
	 * @return void
	 */
	public function set( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		// Drop any previously-resolved instance so re-registration takes effect.
		unset( $this->instances[ $id ] );
	}

	/**
	 * Resolve a service, building it on first access and caching thereafter.
	 *
	 * @param string $id Service identifier.
	 *
	 * @return mixed
	 *
	 * @throws RuntimeException When `$id` has no registered factory.
	 */
	public function get( string $id ) {
		if ( array_key_exists( $id, $this->instances ) ) {
			return $this->instances[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception message is developer-facing, not user-facing output.
			throw new RuntimeException( sprintf( "Service '%s' is not registered.", $id ) );
		}

		$this->instances[ $id ] = ( $this->factories[ $id ] )( $this );

		return $this->instances[ $id ];
	}

	/**
	 * Whether `$id` has a registered factory (regardless of whether it has been resolved).
	 *
	 * @param string $id Service identifier.
	 *
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] );
	}
}
