<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Application\Mapping;

/**
 * ApiException — a non-2xx answer, carrying the typed reason.
 *
 * Callers catch this and render `error->message` plus `error->fieldErrors`;
 * the exception message is the same text so a log line is already readable.
 */
final class ApiException extends \RuntimeException
{
    public function __construct(public readonly ApiError $error)
    {
        parent::__construct($error->message);
    }
}
