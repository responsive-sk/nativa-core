<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Application\Spec;

/**
 * SpecValidationException — the field that is wrong, not just that something is.
 *
 * A specification is written by an operator in a form, so the only useful
 * failure is one that names the input to fix. `field` is a dotted path into
 * the document (`service.base_url`, `resources[0].fields[1].type`), and it is
 * public because the MCP layer puts it in a tool's error content while the
 * panel marks the input beside it.
 *
 * The reason never repeats a token or a header VALUE — only names, paths and
 * shapes. A validation error is shown, logged and copied into a bug report.
 */
final class SpecValidationException extends \InvalidArgumentException
{
    public function __construct(public readonly string $field, string $reason)
    {
        parent::__construct($field . ': ' . $reason);
    }
}
