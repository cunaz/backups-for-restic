<?php
/**
 * Keeps the places that reveal secrets on a reviewed list.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

namespace BackupsForRestic\Tests\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\TypeCombinator;

/**
 * Secret::reveal() is a deliberate exposure point (docs/03-security-and-threat-model.md
 * §5.3–5.4). Only the classes below may call it; a new call site needs a reviewed change of
 * this list, which makes every new exposure visible in the pull request.
 *
 * @implements Rule<Node>
 */
final class SecretRevealRule implements Rule {

	/** Classes allowed to reveal secrets, with the reason. */
	private const array ALLOWED = [
		'BackupsForRestic\\Secrets\\Secret'               => 'compares and checks for emptiness',
		'BackupsForRestic\\Secrets\\EncryptedOptionStore' => 'encrypts secrets at rest',
		'BackupsForRestic\\Backend\\RestBackend'          => 'builds the basic auth header',
		'BackupsForRestic\\Connection\\RepositoryAccess'  => 'passes the REST password to the backend and reads the key cache',
		'BackupsForRestic\\Connection\\ConnectionTester'  => 'passes the REST password to the backend',
		'BackupsForRestic\\Connection\\Setup'             => 'derives the key of a new repository',
		'BackupsForRestic\\Engine\\KeyUnlock'             => 'derives the user key',
		'BackupsForRestic\\Support\\Redactor'             => 'finds secrets in text to remove them',
		'BackupsForRestic\\WordPress\\RecoveryKit'        => 'shows the recovery kit after re-authentication',
	];

	public function getNodeType(): string {
		return Node::class;
	}

	/**
	 * Reports reveal() calls outside the allowed classes.
	 *
	 * @param Node  $node  Node.
	 * @param Scope $scope Scope.
	 * @return list<IdentifierRuleError> Errors.
	 */
	public function processNode( Node $node, Scope $scope ): array {
		if ( ! ( $node instanceof MethodCall || $node instanceof NullsafeMethodCall ) || ! $node->name instanceof Identifier || 'reveal' !== $node->name->toString() ) {
			return [];
		}
		$type = TypeCombinator::removeNull( $scope->getType( $node->var ) );
		if ( ! ( new ObjectType( 'BackupsForRestic\\Secrets\\Secret' ) )->isSuperTypeOf( $type )->yes() ) {
			return [];
		}
		$class = $scope->getClassReflection()?->getName() ?? '';
		if ( isset( self::ALLOWED[ $class ] ) ) {
			return [];
		}
		return [
			RuleErrorBuilder::message( sprintf( 'Secret::reveal() in %s is not on the reviewed list in tests/PHPStan/SecretRevealRule.php.', '' === $class ? 'global code' : $class ) )
				->identifier( 'backupsForRestic.secretRevealed' )
				->build(),
		];
	}
}
