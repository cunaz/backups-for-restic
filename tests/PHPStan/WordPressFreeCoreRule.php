<?php
/**
 * Keeps the engine core free of WordPress.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

namespace BackupsForRestic\Tests\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Only BackupsForRestic\WordPress and the composition root BackupsForRestic\Plugin may use
 * WordPress functions or classes (docs/02-architecture.md §3). Everything else is the core:
 * unit tested without WordPress and portable to WP-CLI and a future binary engine. A symbol
 * counts as WordPress when PHPStan found it in the WordPress stubs.
 *
 * @implements Rule<Node>
 */
final class WordPressFreeCoreRule implements Rule {

	/** Every sub-namespace of BackupsForRestic except WordPress. */
	private const string CORE = '/^BackupsForRestic\\\\(?!WordPress(\\\\|$))\\w+/';

	/**
	 * Creates the rule.
	 *
	 * @param ReflectionProvider $reflection PHPStan's reflection.
	 */
	public function __construct( private readonly ReflectionProvider $reflection ) {
	}

	public function getNodeType(): string {
		return Node::class;
	}

	/**
	 * Reports WordPress symbols used inside the core namespaces.
	 *
	 * @param Node  $node  Node.
	 * @param Scope $scope Scope.
	 * @return list<IdentifierRuleError> Errors.
	 */
	public function processNode( Node $node, Scope $scope ): array {
		if ( 1 !== preg_match( self::CORE, $scope->getNamespace() ?? '' ) ) {
			return [];
		}
		$symbol = null;
		$file   = null;
		if ( $node instanceof FuncCall && $node->name instanceof Node\Name && $this->reflection->hasFunction( $node->name, $scope ) ) {
			$function = $this->reflection->getFunction( $node->name, $scope );
			$symbol   = $function->getName() . '()';
			$file     = $function->getFileName();
		} elseif ( ( $node instanceof New_ || $node instanceof StaticCall || $node instanceof ClassConstFetch || $node instanceof Instanceof_ ) && $node->class instanceof Node\Name ) {
			$name = $scope->resolveName( $node->class );
			if ( $this->reflection->hasClass( $name ) ) {
				$class  = $this->reflection->getClass( $name );
				$symbol = $class->getName();
				$file   = $class->getFileName();
			}
		}
		if ( null === $symbol || null === $file || ! str_contains( str_replace( '\\', '/', $file ), '/wordpress-stubs/' ) ) {
			return [];
		}
		return [
			RuleErrorBuilder::message( sprintf( '%s is WordPress; the engine core (%s) must stay WordPress-free.', $symbol, $scope->getNamespace() ) )
				->identifier( 'backupsForRestic.wordPressInCore' )
				->build(),
		];
	}
}
