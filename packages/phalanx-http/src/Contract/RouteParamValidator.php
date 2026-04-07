<?php

declare(strict_types=1);

namespace Phalanx\Http\Contract;

/**
 * Validates a single extracted route parameter value.
 *
 * Two-phase validation:
 *
 * 1. Pattern phase (compile-time): toPattern() returns a regex fragment that
 *    FastRoute uses for URL matching. A non-null pattern means the route only
 *    matches URLs where the parameter satisfies the regex.
 *
 * 2. Imperative phase (dispatch-time): validate() runs after FastRoute
 *    matches. Useful for constraints that cannot be expressed as a regex
 *    (e.g. integer range checks, enum membership). Return null to pass, or
 *    an error message string to fail.
 *
 * Implementations that only need regex-level validation may return null from
 * validate(). Implementations that cannot express their constraint as a regex
 * may return null from toPattern() and rely solely on validate().
 */
interface RouteParamValidator
{
    /**
     * Validate the parameter value after FastRoute match.
     *
     * Return null if valid, or an error message string if invalid.
     */
    public function validate(string $name, string $value): ?string;

    /**
     * Regex fragment to pass to FastRoute for URL matching.
     *
     * Return null to use FastRoute's default match ([^/]+ or equivalent).
     * Return a regex fragment (without delimiters or anchors) to constrain
     * which URLs this route matches.
     */
    public function toPattern(): ?string;
}
