<?php

declare(strict_types=1);

namespace MyBeast\Audit\Laravel\Transport;

use RuntimeException;

/** No answer from the service at all: nothing was confirmed, the events wait. */
final class TransportFailed extends RuntimeException {}
