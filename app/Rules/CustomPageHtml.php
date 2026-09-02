<?php

namespace App\Rules;

use Closure;
use DOMDocument;
use Illuminate\Contracts\Validation\ValidationRule;

/** Keeps HTML, CSS and JavaScript on their dedicated custom-page channels. */
class CustomPageHtml implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML($value, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($document->getElementsByTagName('script')->length > 0) {
            $fail('Il contenuto HTML non puÃ² contenere tag script: usa il campo JavaScript dedicato.');
        }

        if ($document->getElementsByTagName('style')->length > 0) {
            $fail('Il contenuto HTML non puÃ² contenere tag style: usa il campo CSS dedicato.');
        }
    }
}
