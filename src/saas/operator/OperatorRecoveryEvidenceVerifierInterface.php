<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\operator;

use Z0x\WebmanStarter\saas\domain\MainlandMobileNumber;

interface OperatorRecoveryEvidenceVerifierInterface
{
    /**
     * Resolve the reference in a product-controlled records system and confirm an approved account
     * ownership check against existing customer records, performed through the specified registered
     * contact or authorized channel. Confirm the approval covers this target, destination, reason,
     * and operator. The operator is the platform account ID or the CLI execution identity.
     * Return false for a missing, mismatched, expired or otherwise unconfirmed case. Keep the raw
     * evidence in the product system. The starter receives and stores only the safe reference.
     */
    public function verify(OperatorRecoveryEvidence $evidence, int $accountId, MainlandMobileNumber $newPhone, int|string $actor): bool;
}
