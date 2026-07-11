<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Attribute;

use M2Oidc\OAuth\Logger\OidcLogger;

/**
 * Applies predefined claim-value transformation functions before Magento field assignment.
 *
 * Supported functions
 * -------------------
 * concat        Joins values from multiple claim keys with a delimiter.
 *               Params: fields (comma-separated claim names), delimiter (default " ")
 *               Example: fields=given_name,family_name  → "Jane Doe"
 *
 * split         Splits a single claim value by a delimiter and returns one part.
 *               Params: delimiter (default " "), index (0=first, -1=last, …)
 *               Example: delimiter=space index=0  → "Jane" from "Jane Doe"
 *
 * prefix        Prepends a static string to the claim value.
 *               Params: value (the prefix string)
 *               Example: value=sso_  → "sso_jdoe"
 *
 * regex_replace Runs preg_replace on the claim value.
 *               Params: pattern (PCRE), replacement (default "")
 *               Example: pattern=/@.*$/  → "jdoe" from "jdoe@example.com"
 *
 * NULL / ""     Passthrough — returns the raw claim value unchanged.
 *
 * apply() always returns a non-null string or null (caller treats null as "skip").
 * On error it logs a WARNING and returns the original raw value (never throws).
 */
class Transformer
{
    /**
     * Maximum claim-value length (bytes) accepted by regex_replace.
     *
     * Longer values skip the transform (raw value returned) to bound the cost
     * of pathological PCRE backtracking on attacker-sized claim values.
     *
     * @var int
     */
    private const REGEX_VALUE_MAX_LENGTH = 4096;

    /**
     * @param OidcLogger $logger
     */
    public function __construct(
        private readonly OidcLogger $logger
    ) {
    }

    /**
     * Apply a transform function to a claim value.
     *
     * @param  string|null         $rawValue   Raw claim value extracted from OIDC response (null if claim absent)
     * @param  array<string,mixed> $rawClaims  Full flattened claim set (needed by concat)
     * @param  string|null         $function   Transform function name (null = passthrough)
     * @param  string|null         $paramsJson JSON-encoded params string (null = no params)
     * @return string|null                     Transformed value, or null when input is absent/empty after transform
     */
    public function apply(
        ?string        $rawValue,
        array          $rawClaims,
        ?string        $function,
        ?string        $paramsJson
    ): ?string {
        if ($function === null || $function === '') {
            return $rawValue;
        }

        $params = $this->parseParams($paramsJson);

        try {
            return match ($function) {
                'concat'        => $this->applyConcat($rawClaims, $params),
                'split'         => $this->applySplit($rawValue, $params),
                'prefix'        => $this->applyPrefix($rawValue, $params),
                'regex_replace' => $this->applyRegexReplace($rawValue, $params),
                default         => $rawValue,
            };
        } catch (\Throwable $e) {
            $this->logger->customlog(
                sprintf(
                    'Transformer: function "%s" failed: %s — falling back to raw value',
                    $function,
                    $e->getMessage()
                )
            );
            return $rawValue;
        }
    }

    // -------------------------------------------------------------------------
    // Private transform implementations
    // -------------------------------------------------------------------------

    /**
     * Concat — joins values from multiple claim keys.
     *
     * Params:
     *   fields    (required) comma-separated OIDC claim names
     *   delimiter (optional) defaults to a single space; use "space" as an alias for " "
     *
     * @param  array<string,mixed> $rawClaims
     * @param  array<string,string> $params
     */
    private function applyConcat(array $rawClaims, array $params): ?string
    {
        $fieldsStr = trim($params['fields'] ?? '');
        if ($fieldsStr === '') {
            return null;
        }

        $delimiter = $this->resolveDelimiter($params['delimiter'] ?? ' ');
        $parts = [];
        foreach (explode(',', $fieldsStr) as $field) {
            $field = trim($field);
            if ($field !== '' && isset($rawClaims[$field]) && (string) $rawClaims[$field] !== '') {
                $parts[] = (string) $rawClaims[$field];
            }
        }

        $result = implode($delimiter, $parts);
        return $result !== '' ? $result : null;
    }

    /**
     * Split — splits a claim value by delimiter and returns one part.
     *
     * Params:
     *   delimiter (optional) defaults to " "; use "space" as alias
     *   index     (optional) 0-based; negative counts from end; defaults to 0
     *
     * @param  string|null $rawValue
     * @param  array<string,string> $params
     */
    private function applySplit(?string $rawValue, array $params): ?string
    {
        if ($rawValue === null || $rawValue === '') {
            return null;
        }

        $delimiter = $this->resolveDelimiter($params['delimiter'] ?? ' ');
        $parts     = $delimiter !== '' ? explode($delimiter, $rawValue) : str_split($rawValue);
        $index     = (int) ($params['index'] ?? 0);

        // Normalise negative index
        if ($index < 0) {
            $index = count($parts) + $index;
        }

        $part = $parts[$index] ?? null;
        return ($part !== null && $part !== '') ? $part : null;
    }

    /**
     * Prefix — prepends a static string.
     *
     * Params:
     *   value (required) the prefix string
     *
     * @param  string|null $rawValue
     * @param  array<string,string> $params
     */
    private function applyPrefix(?string $rawValue, array $params): ?string
    {
        if ($rawValue === null) {
            return null;
        }

        $prefix = (string) ($params['value'] ?? '');
        return $prefix . $rawValue;
    }

    /**
     * Regex_replace — runs preg_replace on the claim value.
     *
     * Params:
     *   pattern     (required) PCRE pattern (e.g. /@.*$/)
     *   replacement (optional) defaults to empty string
     *
     * Values longer than REGEX_VALUE_MAX_LENGTH bytes are returned unchanged
     * (with a WARNING log) to avoid expensive regex evaluation on oversized input.
     *
     * @param  string|null $rawValue
     * @param  array<string,string> $params
     */
    private function applyRegexReplace(?string $rawValue, array $params): ?string
    {
        if ($rawValue === null || $rawValue === '') {
            return $rawValue;
        }

        if (strlen($rawValue) > self::REGEX_VALUE_MAX_LENGTH) {
            $this->logger->customlog(
                'Transformer: WARNING — regex_replace claim value exceeds '
                . self::REGEX_VALUE_MAX_LENGTH . ' bytes — skipping transform'
            );
            return $rawValue;
        }

        $pattern = trim($params['pattern'] ?? '');
        if ($pattern === '') {
            return $rawValue;
        }

        $replacement = $params['replacement'] ?? '';

        // Validate pattern before applying to prevent warnings.
        try {
            if (preg_match($pattern, '') === false) {
                $this->logger->customlog(
                    'Transformer: regex_replace has invalid pattern "' . $pattern . '" — skipping transform'
                );
                return $rawValue;
            }
        } catch (\ValueError $e) {
            $this->logger->customlog(
                'Transformer: regex_replace has invalid pattern "' . $pattern . '" — skipping transform'
            );
            return $rawValue;
        }

        $result = preg_replace($pattern, $replacement, $rawValue);
        return ($result !== null) ? $result : $rawValue;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Parse JSON params string into a key→value array.
     *
     * Falls back to empty array on invalid JSON.
     *
     * @param  string|null $paramsJson
     * @return array<string,string>
     */
    private function parseParams(?string $paramsJson): array
    {
        if ($paramsJson === null || $paramsJson === '') {
            return [];
        }

        $decoded = json_decode($paramsJson, true);
        if (!is_array($decoded)) {
            return [];
        }

        // Flatten to string values only
        $result = [];
        foreach ($decoded as $key => $val) {
            $result[(string) $key] = (string) $val;
        }
        return $result;
    }

    /**
     * Resolve the string "space" to a literal space for delimiter params.
     *
     * @param  string $delimiter
     */
    private function resolveDelimiter(string $delimiter): string
    {
        return ($delimiter === 'space') ? ' ' : $delimiter;
    }
}
