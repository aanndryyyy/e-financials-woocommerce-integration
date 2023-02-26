<?php
/**
 * The file that defines the autowiring process.
 *
 * @package Arbictus\EFinancialsPlugin
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin\Main;

use Aanndryyyy\EFinancialsPlugin\Exception\InvalidAutowireDependency;
use Aanndryyyy\EFinancialsPlugin\Exception\NonPsr4CompliantClass;
use Aanndryyyy\EFinancialsPlugin\Services\ServiceInterface;
use Aanndryyyy\EFinancialsPlugin\Services\ServiceCliInterface;
use Exception;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionException;

/**
 * The file that defines the autowiring process
 */
class Autowiring {

	/**
	 * Array of psr-4 prefixes. Should be provided by Composer's ClassLoader. $ClassLoader->getPsr4Prefixes().
	 *
	 * @var array<string, string>
	 */
	protected $psr4_prefixes;

	/**
	 * Project namespace
	 *
	 * @var string
	 */
	protected string $namespace;

	/**
	 * Autowiring.
	 *
	 * @param array<string, mixed> $manually_defined_dependencies Manually defined dependencies from Main.
	 * @param bool                 $skip_invalid Skip invalid namespaces rather than throwing an exception. Used for tests.
	 *
	 * @throws Exception Exception thrown in case class is missing.
	 *
	 * @return  array<int|string, mixed> Array of fully qualified class names.
	 */
	public function build_service_classes( array $manually_defined_dependencies = [], bool $skip_invalid = false ): array {

		$class_names = $this->filter_manually_defined_dependencies(
			$this->get_classes_in_namespace( $this->namespace, $this->psr4_prefixes ),
			$manually_defined_dependencies
		);

		$project_reflection_classes = $this->validate_and_build_classes( $class_names, $skip_invalid );

		$dependency_tree = [];

		// Prepare the filename index.
		$filename_index        = $this->build_filename_index( $project_reflection_classes );
		$class_interface_index = $this->build_class_interface_index( $project_reflection_classes );

		foreach ( $project_reflection_classes as $project_class => $refl_class ) {

			// Skip abstract classes, interfaces & traits, and non-service classes.
			if (
				$refl_class->isAbstract() ||
				$refl_class->isInterface() ||
				$refl_class->isTrait() ||
				! (
					$refl_class->implementsInterface( ServiceInterface::class ) ||
					$refl_class->implementsInterface( ServiceCliInterface::class )
				)
			) {
				continue;
			}

			/**
			 * Build the dependency tree.
			 *
			 * @var array<string, array<string, array<string, string>>>
			 */
			$dependency_tree = \array_merge(
				$this->build_dependency_tree( $project_class, $filename_index, $class_interface_index ),
				$dependency_tree
			);
		}

		// Build dependency tree for dependencies. Things that need to be injected but were skipped because
		// they were initially irrelevant.
		foreach ( $dependency_tree as &$dependencies ) {

			// PHPCS:Ignore Generic.Commenting.DocComment.MissingShort
			/** @var array<string, array<string, string>> $dependencies */
			foreach ( $dependencies as $dep_class => $sub_deps ) {

				// No need to build dependencies for this again if we already have them.
				if ( isset( $dependency_tree[ $dep_class ] ) ) {
					continue;
				}

				$dependency_tree = \array_merge(
					$this->build_dependency_tree( $dep_class, $filename_index, $class_interface_index ),
					$dependency_tree
				);
			}
		}

		// Convert dependency tree into PHP-DI's definition list.
		return \array_merge(
			/* @phpstan-ignore-next-line Recursive. */
			$this->convert_dependency_tree_into_definition_list( $dependency_tree ),
			$manually_defined_dependencies
		);
	}

	/**
	 * Builds the dependency tree for a single class ($relevant_class).
	 *
	 * @param string                             $relevant_class Class we're building dependency tree for.
	 * @param array<string, array<int, string>>  $filename_index Filename index. Maps filenames to class names.
	 * @param array<string, array<string, true>> $class_interface_index Class interface index. Map classes to interface they implement.
	 *
	 * @throws InvalidAutowireDependency If a primitive dependency is found.
	 *
	 * @return array<string, mixed>
	 */
	private function build_dependency_tree( string $relevant_class, array $filename_index, array $class_interface_index ): array {

		// Keeping PHPStan happy.
		if ( ! \class_exists( $relevant_class, false ) ) {
			return [];
		}

		// Ignore dependencies for autowire and main class.
		$ignore_paths = \array_flip(
			[
				'psr4_prefixes',
				'project_namespace',
			]
		);

		$dependency_tree = [];
		$refl_class      = new ReflectionClass( $relevant_class );

		// If this class has dependencies, we need to figure those out. Otherwise,
		// we just add it to the dependency tree as a class without dependencies.
		if ( \is_null( $refl_class->getConstructor() ) || count( $refl_class->getConstructor()->getParameters() ) === 0 ) {

			$dependency_tree[ $relevant_class ] = [];
			return $dependency_tree;
		}

		// Go through each constructor parameter.
		foreach ( $refl_class->getConstructor()->getParameters() as $refl_param ) {

			$type = $refl_param->getType();

			// Skip parameters without type hints.
			if ( ! $type instanceof ReflectionNamedType ) {
				continue;
			}

			$class_name = $type->getName();
			$is_builtin = $type->isBuiltin();

			// We're unable to autowire primitive dependency and there doesn't seem to yet be a way
			// to check if this parameter has a default value or not (so we need to throw an exception regardless).
			// See: https://www.php.net/manual/en/class.reflectionnamedtype.php.
			if ( $is_builtin && ! isset( $ignore_paths[ $refl_param->getName() ] ) ) {
				throw InvalidAutowireDependency::throw_primitive_dependency_found( $relevant_class, $refl_param->getName() );
			}

			// Keeping PHPStan happy.
			if ( \class_exists( $class_name, false ) || \interface_exists( $class_name, false ) ) {

				$refl_class_for_param = new ReflectionClass( $class_name );

				// If the expected type is interface, try guessing based on var name.
				// Otherwise, just inject that class.
				if ( $refl_class_for_param->isInterface() ) {

					$matched_class = $this->try_to_find_matching_class(
						$refl_param->getName(),
						$class_name,
						$filename_index,
						$class_interface_index
					);

					// If we're unable to find exactly 1 class for whatever reason, just skip it, the user
					// will have to define the dependencies manually.
					if ( $matched_class === '' ) {
						continue;
					}

					$dependency_tree[ $relevant_class ][ $matched_class ] = [];
				} else {
					$dependency_tree[ $relevant_class ][ $class_name ] = [];
				}
			}
		}

		return $dependency_tree;
	}

	/**
	 * Returns all classes in namespace.
	 *
	 * @param string                $namespace_name Name of namespace.
	 * @param array<string, string> $psr4_prefixes Array of psr-4 compliant namespaces and their accompanying folders.
	 *
	 * @return string[]
	 */
	private function get_classes_in_namespace( string $namespace_name, array $psr4_prefixes ): array {

		$classes              = [];
		$namespace_with_slash = "{$namespace_name}\\";
		$path_to_namespace    = $psr4_prefixes[ $namespace_with_slash ][0] ?? '';

		if ( ! \is_dir( $path_to_namespace ) ) {
			return [];
		}

		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $path_to_namespace )
		);

		// PHPCS:Ignore Generic.Commenting.DocComment.MissingShort
		/** @var \SplFileInfo $file */
		foreach ( $it as $file ) {

			if ( $file->isDir() ) {
				continue;
			}

			if ( (bool) \preg_match( '/^[A-Z]{1}[A-Za-z0-9]+\.php/', $file->getFilename() ) ) {
				$classes[] = $this->get_namespace_from_filepath( $file->getPathname(), $namespace_name, $path_to_namespace );
			}
		}

		return $classes;
	}

	/**
	 * Builds PSR namespace Vendor\from file's path.
	 *
	 * @param string $filepath Path to a file.
	 * @param string $root_namespace Root namespace Vendor\we're getting classes from.
	 * @param string $root_namespace_path Path to root namespace Vendor\.
	 *
	 * @return string
	 */
	private function get_namespace_from_filepath(
		string $filepath,
		string $root_namespace,
		string $root_namespace_path
	): string {

		$path_namespace = \str_replace(
			[ $root_namespace_path, \DIRECTORY_SEPARATOR, '.php' ],
			[ '', '\\', '' ],
			$filepath
		);

		return $root_namespace . $path_namespace;
	}


	/**
	 * Try to uniquely match the $filename.
	 *
	 * @param string                             $filename Filename based on variable name.
	 * @param string                             $interface_name Interface we're trying to match.
	 * @param array<string, array<int, string>>  $filename_index Filename index. Maps filenames to class names.
	 * @param array<string, array<string, true>> $class_interface_index Class interface index. Map classes to interface they implement.
	 *
	 * @throws InvalidAutowireDependency If we didn't find exactly 1 class when trying to inject interface-based dependencies. If things
	 *                                   we're looking for are missing inside filename or classInterface index (which shouldn't happen).
	 *
	 * @return string
	 */
	private function try_to_find_matching_class(
		string $filename,
		string $interface_name,
		array $filename_index,
		array $class_interface_index
	): string {

		// If there's no matches in filename index by variable, we need to throw an exception to let the user
		// know they either need to provide the correct variable name OR manually define the dependencies for this class.
		$class_name = \ucfirst( $filename );

		if ( ! isset( $filename_index[ $filename ] ) ) {
			throw InvalidAutowireDependency::throw_unable_to_find_class( $class_name, $interface_name );
		}

		// Let's go through each file that's called $filename and check which interfaces that class
		// implements (if any).
		$matches = 0;
		$match   = '';

		foreach ( $filename_index[ $filename ] as $class_in_filename ) {

			if ( ! isset( $class_interface_index[ $class_in_filename ] ) ) {
				throw InvalidAutowireDependency::throw_unable_to_find_class( $class_in_filename, 'classInterfaceIndex' );
			}

			// If the current class implements the interface we're looking for, great!
			// We still need to go through all other classes to make sure we don't get more than 1 match.
			if ( isset( $class_interface_index[ $class_in_filename ][ $interface_name ] ) ) {
				$match = $class_in_filename;
				++$matches;
			}
		}

		// If we don't have a unique match
		// (i.e. if 2 classes of the same name are implementing the interface we're looking for)
		// then we need to cancel the match because we don't know how to handle that.
		if ( $matches === 0 ) {
			throw InvalidAutowireDependency::throw_unable_to_find_class( $class_name, $interface_name );
		}

		if ( $matches > 1 ) {
			throw InvalidAutowireDependency::throw_more_than_one_class_found( $class_name, $interface_name );
		}

		return $match;
	}

	/**
	 * Builds the PSR-4 filename index. Maps filenames to class names.
	 *
	 * @param array<string, ReflectionClass<object>> $reflection_classes Reflection classes of all relevant classes.
	 *
	 * @return array<string, array<int, string>>
	 */
	private function build_filename_index( array $reflection_classes ): array {

		$filename_index = [];

		foreach ( $reflection_classes as $relevant_class => $refl_class ) {

			$filename = $this->get_filename_from_class( $relevant_class );

			$filename_index[ $filename ][] = $relevant_class;
		}

		return $filename_index;
	}

	/**
	 * Builds the PSR-4 class => [$interfaces] index. Map classes to interface they implement.
	 *
	 * @psalm-suppress TooManyArguments
	 *
	 * @param array<string, ReflectionClass<object>> $reflection_classes  Reflection classes of all relevant classes.
	 *
	 * @return array<string, array<string, true>>
	 */
	private function build_class_interface_index( array $reflection_classes ): array {

		$class_interface_index = [];

		foreach ( $reflection_classes as $project_class => $reflection_class ) {

			$interfaces = \array_map(
				fn () => true,
				$reflection_class->getInterfaces()
			);

			$class_interface_index[ $project_class ] = $interfaces;
		}

		return $class_interface_index;
	}

	/**
	 * Returns filename from fully-qualified class names
	 *
	 * Example: AutowiringTest/Something/Class => class
	 *
	 * @param string $class_name Fully qualified classname.
	 *
	 * @return string
	 */
	private function get_filename_from_class( string $class_name ): string {

		return \lcfirst( \trim( \substr( $class_name, (int) \strrpos( $class_name, '\\' ) + 1 ) ) );
	}

	/**
	 * Takes the dependency tree array and convert's it into PHP-DI's definition list. Recursive.
	 *
	 * @param array<string, array<string, mixed>> $dependency_tree Dependency tree.
	 *
	 * @return array<int|string, mixed>
	 */
	private function convert_dependency_tree_into_definition_list( array $dependency_tree ): array {

		$classes = [];

		foreach ( $dependency_tree as $class_name => $dependencies ) {

			if ( count( $dependencies ) === 0 ) {
				$classes[] = $class_name;
				continue;
			}

			/* @phpstan-ignore-next-line Recursive. */
			$classes[ $class_name ] = $this->convert_dependency_tree_into_definition_list( $dependencies );
		}

		return $classes;
	}

	/**
	 * Validates all classes.
	 *
	 * Validates that all classes/interfaces/traits/etc. provided here are valid (we can build a ReflectionClass
	 * on them) and return them. Otherwise, throw an exception.
	 *
	 * @param array<string, mixed> $class_names FQCNs found in $this->namespace.
	 * @param bool                 $skip_invalid Skip invalid namespaces rather than throwing an exception. Used for tests.
	 *
	 * @return array<string, ReflectionClass<object>>
	 *
	 * @throws NonPsr4CompliantClass When a found class/file doesn't match PSR-4 standards (and $skipInvalid is false).
	 */
	private function validate_and_build_classes( array $class_names, bool $skip_invalid ): array {

		$reflection_classes = [];

		foreach ( $class_names as $class_name ) {

			if ( ! is_string( $class_name ) ) {
				continue;
			}

			// Validate as class-string.
			if ( ! class_exists( $class_name ) && ! interface_exists( $class_name ) ) {
				if ( $skip_invalid ) {
					continue;
				}

				throw NonPsr4CompliantClass::throw_invalid_namespace( $class_name );
			}

			$refl_class = new ReflectionClass( $class_name );

			$reflection_classes[ $class_name ] = $refl_class;
		}

		return $reflection_classes;
	}

	/**
	 * Filters out manually defined dependencies so we don't autowire them.
	 *
	 * @param array<string, mixed> $service_classes All FQCNs inside the namespace.
	 * @param array<string, mixed> $manually_defined_dependencies Manually defined dependency tree.
	 *
	 * @return array<string, mixed>
	 */
	private function filter_manually_defined_dependencies( array $service_classes, array $manually_defined_dependencies ): array {

		return \array_filter(
			$service_classes,
			fn ( $class_namespace ) => ! isset( $manually_defined_dependencies[ $class_namespace ] ),
		);
	}
}
