<?php
/**
 * Precise PHPStan types for unpack() with integer formats.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

namespace BackupsForRestic\Tests\PHPStan;

use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Type\ArrayType;
use PHPStan\Type\Constant\ConstantBooleanType;
use PHPStan\Type\DynamicFunctionReturnTypeExtension;
use PHPStan\Type\IntegerType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * Tells PHPStan that unpack() returns integers when every format code is an integer code
 * (for example "V*" or "Vpack/Joffset"). The crypto and format code unpacks words in hot loops,
 * where validating each element at runtime would cost measurable throughput for no benefit.
 */
final class UnpackReturnTypeExtension implements DynamicFunctionReturnTypeExtension {

	private const string INTEGER_CODES = 'cCsSnviIlLNVqQJP';

	public function isFunctionSupported( FunctionReflection $function_reflection ): bool {
		return 'unpack' === $function_reflection->getName();
	}

	public function getTypeFromFunctionCall( FunctionReflection $function_reflection, FuncCall $function_call, Scope $scope ): ?Type {
		$arguments = $function_call->getArgs();
		if ( [] === $arguments ) {
			return null;
		}
		$formats = $scope->getType( $arguments[0]->value )->getConstantStrings();
		if ( 1 !== count( $formats ) ) {
			return null;
		}

		$named = false;
		foreach ( explode( '/', $formats[0]->getValue() ) as $part ) {
			if ( '' === $part || ! str_contains( self::INTEGER_CODES, $part[0] ) ) {
				return null;
			}
			$repeat = ltrim( substr( $part, 1 ), '0123456789*' );
			$named  = $named || '' !== $repeat;
		}

		$key = $named ? TypeCombinator::union( new IntegerType(), new StringType() ) : new IntegerType();
		return TypeCombinator::union( new ArrayType( $key, new IntegerType() ), new ConstantBooleanType( false ) );
	}
}
