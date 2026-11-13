<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Application\Mapping;

/**
 * MappingException — the answer arrived, but it is not a shape this panel reads.
 *
 * An HTTP status that is not 2xx is `ApiException` (the service said no); this
 * one is for a 2xx whose body cannot be interpreted: not JSON, no list where a
 * list is required, a list of scalars where rows are expected. The two are
 * separate because the operator's next step is different — check the token, or
 * check the service's response mapping.
 */
final class MappingException extends \RuntimeException
{
}
