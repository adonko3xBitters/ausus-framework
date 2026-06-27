<?php
declare(strict_types=1);

namespace Ausus\Cli\Authoring;

use Ausus\Engine\Compile\CompilationError;

/**
 * IMPLEMENTATION-001 Phase 5B — static scan of an authored entities/*.php source
 * for forbidden symbols (RFC-CLI-003 §Q6 corrected).
 *
 * Runs BEFORE any evaluation. The first forbidden symbol aborts immediately with
 * {@see CompilationError}; nothing is executed. This is the PRIMARY determinism
 * guarantee — the sandbox is only best-effort defence-in-depth.
 *
 * Goal: architectural security, not perfect PHP parsing. The scan is
 * token-based (so it ignores string-literal contents) and intentionally simple.
 */
final class ForbiddenSymbolScanner
{
    /** Forbidden bareword identifiers (case-insensitive exact match). */
    private const FORBIDDEN = [
        'eval', 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen',
        'file_get_contents', 'file_put_contents', 'fopen', 'fwrite', 'fread',
        'unlink', 'rename', 'copy', 'fsockopen',
        'rand', 'mt_rand', 'random_int', 'random_bytes',
        'getenv', 'call_user_func', 'call_user_func_array',
        'container', 'app', 'resolve', 'persistencedriver', 'driver', 'context',
    ];

    /** Forbidden identifier prefixes (case-insensitive). */
    private const FORBIDDEN_PREFIX = ['curl_', 'reflection'];

    public function scan(string $source): void
    {
        $tokens = token_get_all($source);
        $n = count($tokens);

        for ($i = 0; $i < $n; $i++) {
            $tok = $tokens[$i];

            // Single-character tokens: a bare '$' starts a `$$variable`.
            if (!is_array($tok)) {
                if ($tok === '$') {
                    $this->reject('$$ (variable-variable)');
                }
                continue;
            }

            [$id, $text] = $tok;

            // ${ ... } dynamic expression.
            if (defined('T_DOLLAR_OPEN_CURLY_BRACES') && $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $this->reject('${...} (dynamic expression)');
            }

            // Superglobals.
            if ($id === T_VARIABLE && ($text === '$_ENV' || $text === '$_SERVER')) {
                $this->reject($text);
            }

            // Forbidden bareword identifiers / prefixes.
            if ($id === T_STRING) {
                $lower = strtolower($text);
                if (in_array($lower, self::FORBIDDEN, true)) {
                    $this->reject($text);
                }
                foreach (self::FORBIDDEN_PREFIX as $prefix) {
                    if (str_starts_with($lower, $prefix)) {
                        $this->reject($text);
                    }
                }
            }

            // new $class()
            if ($id === T_NEW) {
                $next = $this->nextSignificant($tokens, $i, $n);
                if (is_array($next) && $next[0] === T_VARIABLE) {
                    $this->reject('new $dynamic (dynamic instantiation)');
                }
            }

            // $callable(...)  and  $class::...
            if ($id === T_VARIABLE) {
                $next = $this->nextSignificant($tokens, $i, $n);
                if ($next === '(') {
                    $this->reject($text . '() (dynamic call)');
                }
                if (is_array($next) && $next[0] === T_DOUBLE_COLON) {
                    $this->reject($text . ':: (dynamic static dispatch)');
                }
            }

            // Class::$method  (dynamic member after ::)
            if ($id === T_DOUBLE_COLON) {
                $next = $this->nextSignificant($tokens, $i, $n);
                if (is_array($next) && $next[0] === T_VARIABLE) {
                    $this->reject(':: $dynamic (dynamic static dispatch)');
                }
            }
        }
    }

    /**
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @return array{0:int,1:string,2:int}|string|null
     */
    private function nextSignificant(array $tokens, int $i, int $n): array|string|null
    {
        for ($j = $i + 1; $j < $n; $j++) {
            $t = $tokens[$j];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $t;
        }

        return null;
    }

    private function reject(string $symbol): never
    {
        throw new CompilationError("forbidden symbol in authored definition: {$symbol}");
    }
}
