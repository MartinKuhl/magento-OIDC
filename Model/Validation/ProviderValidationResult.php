<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Validation;

/**
 * Value object returned by ProviderDataValidator::validate().
 *
 * Carries the normalized provider data together with any warnings
 * (auto-corrections applied) and errors (conditions that must abort the save).
 */
class ProviderValidationResult
{
    /**
     * Normalized provider data.
     *
     * @var mixed[]
     */
    private readonly array $data;

    /**
     * Human-readable warnings about auto-corrected fields.
     *
     * @var string[]
     */
    private readonly array $warnings;

    /**
     * Human-readable errors that must abort persisting the provider.
     *
     * @var string[]
     */
    private readonly array $errors;

    /**
     * Initialize validation result.
     *
     * @param mixed[]  $data     Normalized provider data
     * @param string[] $warnings Warnings about auto-corrected fields
     * @param string[] $errors   Errors that must abort the save
     */
    public function __construct(array $data, array $warnings = [], array $errors = [])
    {
        $this->data     = $data;
        $this->warnings = $warnings;
        $this->errors   = $errors;
    }

    /**
     * Get the normalized provider data.
     *
     * @return mixed[]
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Get warnings about auto-corrected fields.
     *
     * @return string[]
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Get errors that must abort persisting the provider.
     *
     * @return string[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Whether the data may be persisted (no blocking errors).
     *
     * @return bool True when there are no blocking errors
     */
    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
