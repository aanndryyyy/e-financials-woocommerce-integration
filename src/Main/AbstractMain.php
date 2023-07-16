<?php
/**
 * File containing the main intro class for your project.
 *
 * @package Arbictus\EFinancialsPlugin
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin\Main;

use DI\Container;
use DI\ContainerBuilder;
use DI\Definition\Helper\AutowireDefinitionHelper;
use DI\Definition\Reference;
use Aanndryyyy\EFinancialsPlugin\Services\ServiceInterface;
use Aanndryyyy\EFinancialsPlugin\Services\ServiceCliInterface;
use Exception;

/**
 * The main start class.
 *
 * This is used to instantiate all classes.
 */
abstract class AbstractMain extends Autowiring implements ServiceInterface {

	/**
	 * Array of instantiated services.
	 *
	 * @var Object[]
	 */
	private array $services = [];

	/**
	 * DI container instance.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Constructs object and inserts prefixes from composer.
	 *
	 * @param array<string, string> $psr4_prefixes Composer's ClassLoader psr4Prefixes. $ClassLoader->getPsr4Prefixes().
	 * @param string                $project_namespace Projects namespace.
	 */
	public function __construct( array $psr4_prefixes, string $project_namespace ) {

		$this->psr4_prefixes = $psr4_prefixes;
		$this->namespace     = $project_namespace;
	}

	/**
	 * Register the individual services with optional dependency injection.
	 *
	 * @throws Exception Exception thrown by DI container.
	 *
	 * @return void
	 */
	public function register_services() {

		// Bail early so we don't instantiate services twice.
		if ( count( $this->services ) !== 0 ) {
			return;
		}

		$this->services = $this->get_service_classes_with_di();

		\array_walk(
			$this->services,
			function ( object $the_service_class ) {

				// Load services classes but not in the WP-CLI env.
				if ( ! \defined( 'WP_CLI' ) && $the_service_class instanceof ServiceInterface ) {
					$the_service_class->register();
				}

				// Load services CLI classes only in WP-CLI env.
				if ( \defined( 'WP_CLI' ) && $the_service_class instanceof ServiceCliInterface ) {
					$the_service_class->register();
				}
			}
		);
	}

	/**
	 * Returns the DI container
	 *
	 * Allows it to be used in different context (for example in tests outside of WP environment).
	 *
	 * @return Container
	 * @throws Exception Exception thrown by the DI container.
	 */
	public function build_di_container(): Container {

		$this->container = $this->get_di_container( $this->get_service_classes_prepared_array() );

		return $this->container;
	}

	/**
	 * Merges the autowired definition list with custom user-defined definition list.
	 *
	 * You can override autowired definition lists in $this->get_service_classes().
	 *
	 * @throws Exception Exception thrown in case class is missing.
	 *
	 * @return array<int|string, mixed>
	 */
	private function get_service_classes_with_autowire(): array {

		return $this->build_service_classes( $this->get_service_classes() );
	}

	/**
	 * Return array of services with Dependency Injection parameters.
	 *
	 * @return Object[]
	 *
	 * @throws Exception Exception thrown by the DI container.
	 */
	private function get_service_classes_with_di(): array {

		$services  = $this->get_service_classes_prepared_array();
		$container = $this->get_di_container( $services );

		return \array_map(
			fn ( int|string $the_class ): object => (object) $container->get( (string) $the_class ),
			\array_keys( $services )
		);
	}

	/**
	 * Get services classes array and prepare it for dependency injection.
	 * Key should be a class name, and value should be an empty array or the dependencies of the class.
	 *
	 * @throws Exception Exception thrown in case class is missing.
	 *
	 * @return array<int|string, array<string>>
	 */
	private function get_service_classes_prepared_array(): array {

		$output  = [];
		$classes = $this->get_service_classes_with_autowire();

		foreach ( $classes as $class => $dependencies ) {

			if ( \is_array( $dependencies ) ) {
				$output[ (string) $class ] = $dependencies;
				continue;
			}

			$output[ $dependencies ] = [];
		}

		return $output;
	}

	/**
	 * Implement PHP-DI.
	 *
	 * Build and return a DI container.
	 * Wire all the dependencies automatically, based on the provided array of
	 * class => dependencies from the get_di_items().
	 *
	 * @param array<int|string, array<string>> $services Array of service.
	 *
	 * @throws Exception Exception thrown by the DI container.
	 *
	 * @return Container
	 */
	private function get_di_container( array $services ): Container {

		$definitions = [];

		foreach ( $services as $service_key => $service_values ) {

			$autowire = new AutowireDefinitionHelper();

			$definitions[ $service_key ] = $autowire->constructor( ...$this->get_di_dependencies( $service_values ) );
		}

		$builder = new ContainerBuilder();

		if ( \defined( 'WP_ENVIRONMENT_TYPE' ) && ( \WP_ENVIRONMENT_TYPE === 'production' || \WP_ENVIRONMENT_TYPE === 'staging' ) ) {
			$file = \explode( '\\', $this->namespace );

			$builder->enableCompilation( __DIR__ . '/Cache', "{$file[0]}CompiledContainer" );
		}

		return $builder->addDefinitions( $definitions )->build();
	}

	/**
	 * Return prepared Dependency Injection objects.
	 * If you pass a class use PHP-DI to prepare if not just output it.
	 *
	 * @param array<string, string> $dependencies Array of classes/parameters to push in constructor.
	 *
	 * @return array<string, mixed>
	 */
	private function get_di_dependencies( array $dependencies ): array {

		return \array_map(
			function ( $dependency ) {

				if ( \class_exists( $dependency ) ) {
					return new Reference( $dependency );
				}

				return $dependency;
			},
			$dependencies
		);
	}

	/**
	 * Get the list of services to register.
	 *
	 * A list of classes which contain hooks.
	 *
	 * @return array<class-string, string|string[]> Array of fully qualified service class names.
	 */
	protected function get_service_classes(): array {

		return [];
	}
}
