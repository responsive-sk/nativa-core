<?php

declare(strict_types = 1);

namespace Modules\Queue\Exceptions;

/**
 * PoisonJobException — a reserved row's payload is not valid JSON.
 *
 * The row is already parked in failed_jobs when this is thrown; the
 * recipient calls reserve() again for the next job. The worker counts it
 * as failed and continues — a corrupt row never spins the loop and never
 * strands the queue behind it.
 */
final class PoisonJobException extends QueueException
{
}
