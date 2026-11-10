<?php
/**
 * Keeps the dependencies of the engine and the layers below it in one direction.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

namespace BackupsForRestic\Tests\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * The engine and what it stands on form a stack (docs/02-architecture.md §3): Exception,
 * Support, Crypto, Format, Backend, Engine. A layer uses itself and the layers below it,
 * never Jobs, an adapter or another part of the plugin, so that the stack is tested and
 * reused without them. Two classes outside the stack are used by name; they are held to
 * the same rule, so that nothing else reaches the stack through them.
 *
 * The rule reads the use statements of a file and every name in its code. A name that
 * stands only in a comment is seen through the use statement it needs, not otherwise.
 *
 * @implements Rule<Namespace_>
 */
final class EngineDependencyRule implements Rule {

	private const string ROOT = 'BackupsForRestic\\';

	/**
	 * What each part may use besides itself, lowest part first. A part is a namespace below
	 * BackupsForRestic or, for the two helpers outside the stack, a single class.
	 */
	private const array MAY_USE = [
		'Exception'       => [],
		'Secrets\\Secret' => [],
		'Storage\\Files'  => [ 'Exception' ],
		// Redactor takes the secrets it removes from texts.
		'Support'         => [ 'Exception', 'Secrets\\Secret' ],
		'Crypto'          => [ 'Exception', 'Support' ],
		'Format'          => [ 'Exception', 'Support', 'Crypto' ],
		// RestBackend takes the REST password for the basic auth header.
		'Backend'         => [ 'Exception', 'Support', 'Crypto', 'Format', 'Secrets\\Secret' ],
		// KeyUnlock takes the repository password; the runs write their state files atomically.
		'Engine'          => [ 'Exception', 'Support', 'Crypto', 'Format', 'Backend', 'Secrets\\Secret', 'Storage\\Files' ],
	];

	public function getNodeType(): string {
		return Namespace_::class;
	}

	/**
	 * Reports the names of plugin code that the part this file belongs to may not use.
	 *
	 * @param Namespace_ $node  The namespace with all statements of the file.
	 * @param Scope      $scope Scope.
	 * @return list<IdentifierRuleError> Errors.
	 */
	public function processNode( Node $node, Scope $scope ): array {
		$class = ( new NodeFinder() )->findFirstInstanceOf( $node->stmts, ClassLike::class );
		$part  = self::part( ( $node->name?->toString() ?? '' ) . '\\' . ( $class?->name?->toString() ?? '' ) );
		if ( null === $part || ! isset( self::MAY_USE[ $part ] ) ) {
			return [];
		}
		$allowed = self::MAY_USE[ $part ];
		$errors  = [];
		foreach ( self::names( $node ) as $name => $line ) {
			$used = self::part( $name );
			if ( null === $used || $used === $part || in_array( $used, $allowed, true ) ) {
				continue;
			}
			$errors[] = RuleErrorBuilder::message(
				sprintf(
					'%s is used here, but %s may use %s (docs/02-architecture.md §3).',
					$name,
					$part,
					[] === $allowed ? 'no other part of the plugin' : 'only ' . implode( ', ', $allowed ) . ' besides itself'
				)
			)
				->identifier( 'backupsForRestic.engineDependency' )
				->line( $line )
				->build();
		}
		return $errors;
	}

	/**
	 * The part a name of the plugin belongs to: the class itself if the list names it,
	 * otherwise its first namespace. Null for names outside the plugin.
	 *
	 * @param string $name Fully qualified name.
	 */
	private static function part( string $name ): ?string {
		if ( ! str_starts_with( $name, self::ROOT ) ) {
			return null;
		}
		$relative = substr( $name, strlen( self::ROOT ) );
		return isset( self::MAY_USE[ $relative ] ) ? $relative : explode( '\\', $relative, 2 )[0];
	}

	/**
	 * Every name of the plugin that the file imports or writes in its code, with the line
	 * of its first appearance. PHPStan has resolved the names in the code already.
	 *
	 * @param Namespace_ $file The namespace with all statements of the file.
	 * @return array<string, int> Lines by fully qualified name.
	 */
	private static function names( Namespace_ $file ): array {
		$nodes = ( new NodeFinder() )->find(
			$file->stmts,
			static fn ( Node $node ): bool => $node instanceof FullyQualified || $node instanceof Use_ || $node instanceof GroupUse
		);
		$names = [];
		foreach ( $nodes as $node ) {
			$found = [];
			if ( $node instanceof FullyQualified ) {
				$found[] = $node->toString();
			} elseif ( $node instanceof Use_ ) {
				foreach ( $node->uses as $use ) {
					$found[] = $use->name->toString();
				}
			} elseif ( $node instanceof GroupUse ) {
				foreach ( $node->uses as $use ) {
					$found[] = $node->prefix->toString() . '\\' . $use->name->toString();
				}
			}
			foreach ( $found as $name ) {
				if ( str_starts_with( $name, self::ROOT ) ) {
					$names[ $name ] ??= $node->getStartLine();
				}
			}
		}
		return $names;
	}
}
