<?php

namespace App\Http\Requests\Concerns;

/**
 * Lowercases and trims the "email" input before validation.
 *
 * PostgreSQL compares strings case-sensitively, so without this "Ana@x.com" and
 * "ana@x.com" could register as two accounts, and logging in with different
 * casing would fail.
 */
trait NormalizesEmail
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }
}
