<?php

namespace App\Security;

use App\Entity\User;
use App\Entity\Tenant;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Class SubscriptionVoter
 *
 * This security voter evaluates whether the active Tenant possesses an authorized
 * subscription status (active, trialing, or unexpired local trial) before allowing
 * access to high-value end-to-end encrypted features.
 */
class SubscriptionVoter extends Voter
{
    // Define the explicit feature permissions managed by this voter
    public const string DOWNLOAD_DECRYPT_DOCUMENT = 'DOWNLOAD_DECRYPT_DOCUMENT';
    public const string UPLOAD_SECURE_DOCUMENT = 'UPLOAD_SECURE_DOCUMENT';
    public const string SEND_SECURE_INVITATION = 'SEND_SECURE_INVITATION';
    public const string CREATE_FILE_REQUEST = 'CREATE_FILE_REQUEST';
    public const string RESYNC_KEYS = 'RESYNC_KEYS';

    /**
     * @var array<string>
     */
    private const array SUPPORTED_ATTRIBUTES = [
        self::DOWNLOAD_DECRYPT_DOCUMENT,
        self::UPLOAD_SECURE_DOCUMENT,
        self::SEND_SECURE_INVITATION,
        self::CREATE_FILE_REQUEST,
        self::RESYNC_KEYS,
    ];

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, self::SUPPORTED_ATTRIBUTES, true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        // 1. If the user is not authenticated, deny all access immediately
        if (!$user instanceof User) {
            return false;
        }

        $tenant = $user->tenant;
        // 2. If the user is not bound to any tenant, deny access
        if (!$tenant instanceof Tenant) {
            return false;
        }

        // 3. Perform a robust pre-flight check on local card-free trials.
        // This acts as a real-time safety fallback in case cron synchronizations were delayed.
        if ($tenant->subscriptionPlan === 'trial') {
            $trialEnd = $tenant->currentPeriodEnd;
            $now = new \DateTimeImmutable();

            if ($trialEnd !== null && $trialEnd < $now) {
                // Trial has expired; force suspension checks
                return false;
            }
        }

        // 4. Retrieve the status string stored locally in the cached tenant table
        $status = $tenant->status;

        // If the tenant is suspended or unpaid, block all protected actions unconditionally
        if ($status === 'suspended' || $status === 'unpaid') {
            return false;
        }

        // Evaluate permissions based on active subscription status
        switch ($attribute) {
            case self::DOWNLOAD_DECRYPT_DOCUMENT:
                // Only allow decryption/downloads if active or in an active grace-period state
                return in_array($status, ['active', 'past_due'], true);

            case self::UPLOAD_SECURE_DOCUMENT:
                // Block new incoming encrypted file uploads if the account is past_due (grace-period warning)
                return $status === 'active';

            case self::SEND_SECURE_INVITATION:
                // Block administrators from issuing new pre-approved colleague invitations if unpaid/past_due
                return $status === 'active';

            case self::CREATE_FILE_REQUEST:
                // Block staff from issuing new tracking-link requests if the account is not in perfect standing
                return $status === 'active';

            case self::RESYNC_KEYS:
                // Block admins from re-syncing user decryption keys if the account is not in perfect standing
                return $status === 'active';
        }

        return false;
    }
}
