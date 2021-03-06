<?php declare(strict_types = 1);

namespace SlevomatCodingStandard\Sniffs\Commenting;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use SlevomatCodingStandard\Helpers\AnnotationHelper;
use SlevomatCodingStandard\Helpers\TokenHelper;
use function count;
use function in_array;
use function preg_match;
use function sprintf;
use function substr;
use function trim;
use const T_AS;
use const T_COMMENT;
use const T_DOC_COMMENT_OPEN_TAG;
use const T_EQUAL;
use const T_FOREACH;
use const T_LIST;
use const T_OPEN_SHORT_ARRAY;
use const T_PRIVATE;
use const T_PROTECTED;
use const T_PUBLIC;
use const T_STATIC;
use const T_VARIABLE;
use const T_WHILE;

class InlineDocCommentDeclarationSniff implements Sniff
{

	public const CODE_INVALID_FORMAT = 'InvalidFormat';
	public const CODE_INVALID_COMMENT_TYPE = 'InvalidCommentType';
	public const CODE_MISSING_VARIABLE = 'MissingVariable';
	public const CODE_NO_ASSIGNMENT = 'NoAssignment';

	/**
	 * @return (int|string)[]
	 */
	public function register(): array
	{
		return [
			T_DOC_COMMENT_OPEN_TAG,
			T_COMMENT,
		];
	}

	/**
	 * @phpcsSuppress SlevomatCodingStandard.TypeHints.TypeHintDeclaration.MissingParameterTypeHint
	 * @param \PHP_CodeSniffer\Files\File $phpcsFile
	 * @param int $commentOpenPointer
	 */
	public function process(File $phpcsFile, $commentOpenPointer): void
	{
		$tokens = $phpcsFile->getTokens();

		$commentClosePointer = $tokens[$commentOpenPointer]['code'] === T_COMMENT ? $commentOpenPointer : $tokens[$commentOpenPointer]['comment_closer'];

		$codePointer = TokenHelper::findFirstNonWhitespaceOnNextLine($phpcsFile, $commentClosePointer);
		if ($codePointer !== null) {
			if (in_array($tokens[$codePointer]['code'], [T_PRIVATE, T_PROTECTED, T_PUBLIC], true)) {
				return;
			}

			if ($tokens[$codePointer]['code'] === T_STATIC) {
				$pointerAfterStatic = TokenHelper::findNextEffective($phpcsFile, $codePointer + 1);
				if (in_array($tokens[$pointerAfterStatic]['code'], [T_PRIVATE, T_PROTECTED, T_PUBLIC], true)) {
					return;
				}
			}
		}

		$this->checkFormat($phpcsFile, $commentOpenPointer, $commentClosePointer);

		if ($tokens[$commentOpenPointer]['code'] !== T_DOC_COMMENT_OPEN_TAG) {
			return;
		}

		$this->checkVariable($phpcsFile, $commentOpenPointer, $codePointer);
	}

	private function checkFormat(File $phpcsFile, int $commentOpenPointer, int $commentClosePointer): void
	{
		$tokens = $phpcsFile->getTokens();

		if ($tokens[$commentOpenPointer]['code'] === T_COMMENT) {
			if (preg_match('~^/\*\\s*@var\\s+~', $tokens[$commentOpenPointer]['content']) === 0) {
				return;
			}

			$fix = $phpcsFile->addFixableError(
				'Invalid comment type /* */ for inline documentation comment, use /** */.',
				$commentOpenPointer,
				self::CODE_INVALID_COMMENT_TYPE
			);

			if ($fix) {
				$phpcsFile->fixer->beginChangeset();
				$phpcsFile->fixer->replaceToken($commentOpenPointer, sprintf('/**%s', substr($tokens[$commentOpenPointer]['content'], 2)));
				$phpcsFile->fixer->endChangeset();
			}

			$commentContent = trim(substr($tokens[$commentOpenPointer]['content'], 2, -2));
		} else {
			$commentContent = trim(TokenHelper::getContent($phpcsFile, $commentOpenPointer + 1, $commentClosePointer - 1));
		}

		if (preg_match('~^@var~', $commentContent) === 0) {
			return;
		}

		if (preg_match('~^@var\\s+(?:(?:\\S+(?:\\s*[,&\|]\\s*\\S+)+)|\\S+)\\s+\$\\S+(?:\\s+.+)?$~', $commentContent) !== 0) {
			return;
		}

		if (
			preg_match('~^@var\\s+(\$\\S+)\\s+((?:\\S+(?:\\s*[,&\|]\\s*\\S+)+)|\\S+)(\\s+.+)?$~', $commentContent, $matches) !== 0
			&& preg_match('~\\s+~', $matches[2]) === 0
		) {
			$fix = $phpcsFile->addFixableError(
				sprintf(
					'Invalid inline documentation comment format "%s", expected "@var %s %s%s".',
					$commentContent,
					$matches[2],
					$matches[1],
					$matches[3] ?? ''
				),
				$commentOpenPointer,
				self::CODE_INVALID_FORMAT
			);

			if ($fix) {
				$phpcsFile->fixer->beginChangeset();
				for ($i = $commentOpenPointer; $i <= $commentClosePointer; $i++) {
					$phpcsFile->fixer->replaceToken($i, '');
				}
				$phpcsFile->fixer->addContent(
					$commentOpenPointer,
					sprintf(
						'%s @var %s %s%s */',
						$tokens[$commentOpenPointer]['code'] === T_DOC_COMMENT_OPEN_TAG ? '/**' : '/*',
						$matches[2],
						$matches[1],
						$matches[3] ?? ''
					)
				);
				$phpcsFile->fixer->endChangeset();
			}
		} else {
			$phpcsFile->addError(
				sprintf('Invalid inline documentation comment format "%1$s", expected "@var type $variable".', $commentContent),
				$commentOpenPointer,
				self::CODE_INVALID_FORMAT
			);
		}
	}

	private function checkVariable(File $phpcsFile, int $docCommentOpenPointer, ?int $codePointer): void
	{
		$variableAnnotations = AnnotationHelper::getAnnotationsByName($phpcsFile, $docCommentOpenPointer, '@var');
		if (count($variableAnnotations) === 0) {
			return;
		}

		$tokens = $phpcsFile->getTokens();

		$tokenCodes = [T_VARIABLE, T_FOREACH, T_WHILE, T_LIST, T_OPEN_SHORT_ARRAY];
		if ($codePointer === null || !in_array($tokens[$codePointer]['code'], $tokenCodes, true)) {
			$firstPointerOnPreviousLine = TokenHelper::findFirstNonWhitespaceOnPreviousLine($phpcsFile, $docCommentOpenPointer);
			$codePointer = $firstPointerOnPreviousLine !== null && !in_array($tokens[$firstPointerOnPreviousLine]['code'], $tokenCodes, true)
				? null
				: $firstPointerOnPreviousLine;
		}

		/** @var \SlevomatCodingStandard\Helpers\Annotation\VariableAnnotation $variableAnnotation */
		foreach ($variableAnnotations as $variableAnnotation) {
			if ($variableAnnotation->isInvalid()) {
				continue;
			}

			$variableName = $variableAnnotation->getVariableName();
			if ($variableName === null) {
				continue;
			}

			$missingVariableErrorParameters = [
				sprintf('Missing variable %s before or after the documentation comment.', $variableName),
				$docCommentOpenPointer,
				self::CODE_MISSING_VARIABLE,
			];

			$noAssignmentErrorParameters = [
				sprintf('No assignment to %s variable before or after the documentation comment.', $variableName),
				$docCommentOpenPointer,
				self::CODE_NO_ASSIGNMENT,
			];

			if ($codePointer === null) {
				$phpcsFile->addError(...$missingVariableErrorParameters);
				continue;
			}

			if ($tokens[$codePointer]['code'] === T_VARIABLE) {
				$pointerAfterVariable = TokenHelper::findNextEffective($phpcsFile, $codePointer + 1);
				if ($tokens[$pointerAfterVariable]['code'] !== T_EQUAL) {
					$phpcsFile->addError(...$noAssignmentErrorParameters);
					continue;
				}

				if ($variableName !== $tokens[$codePointer]['content']) {
					$phpcsFile->addError(...$missingVariableErrorParameters);
				}

			} elseif ($tokens[$codePointer]['code'] === T_LIST) {
				$listParenthesisOpener = TokenHelper::findNextEffective($phpcsFile, $codePointer + 1);

				$variablePointerInList = TokenHelper::findNextContent($phpcsFile, T_VARIABLE, $variableName, $listParenthesisOpener + 1, $tokens[$listParenthesisOpener]['parenthesis_closer']);
				if ($variablePointerInList === null) {
					$phpcsFile->addError(...$missingVariableErrorParameters);
				}

			} elseif ($tokens[$codePointer]['code'] === T_OPEN_SHORT_ARRAY) {
				$pointerAfterList = TokenHelper::findNextEffective($phpcsFile, $tokens[$codePointer]['bracket_closer'] + 1);
				if ($tokens[$pointerAfterList]['code'] !== T_EQUAL) {
					$phpcsFile->addError(...$noAssignmentErrorParameters);
					continue;
				}

				$variablePointerInList = TokenHelper::findNextContent($phpcsFile, T_VARIABLE, $variableName, $codePointer + 1, $tokens[$codePointer]['bracket_closer']);
				if ($variablePointerInList === null) {
					$phpcsFile->addError(...$missingVariableErrorParameters);
				}

			} else {
				if ($tokens[$codePointer]['code'] === T_WHILE) {
					$variablePointerInWhile = TokenHelper::findNextContent($phpcsFile, T_VARIABLE, $variableName, $tokens[$codePointer]['parenthesis_opener'] + 1, $tokens[$codePointer]['parenthesis_closer']);
					if ($variablePointerInWhile === null) {
						$phpcsFile->addError(...$missingVariableErrorParameters);
						continue;
					}

					$pointerAfterVariableInWhile = TokenHelper::findNextEffective($phpcsFile, $variablePointerInWhile + 1);
					if ($tokens[$pointerAfterVariableInWhile]['code'] !== T_EQUAL) {
						$phpcsFile->addError(...$noAssignmentErrorParameters);
					}
				} else {
					$asPointer = TokenHelper::findNext($phpcsFile, T_AS, $tokens[$codePointer]['parenthesis_opener'] + 1, $tokens[$codePointer]['parenthesis_closer']);
					$variablePointerInForeach = TokenHelper::findNextContent($phpcsFile, T_VARIABLE, $variableName, $asPointer + 1, $tokens[$codePointer]['parenthesis_closer']);
					if ($variablePointerInForeach === null) {
						$phpcsFile->addError(...$missingVariableErrorParameters);
					}
				}
			}
		}
	}

}
