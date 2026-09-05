<?php

declare(strict_types=1);

namespace App\Exceptions\Credentials;

use RuntimeException;

/**
 * A lifecycle transition was refused (wrong status, undecryptable row,
 * provider mismatch). Message is safe to show; never a secret.
 */
final class CredentialLifecycleException extends RuntimeException {}
