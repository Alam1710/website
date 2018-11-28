<?php
namespace Destiny\Tasks;

use Destiny\Chat\ChatRedisService;
use Destiny\Commerce\SubscriptionStatus;
use Destiny\Common\Annotation\Schedule;
use Destiny\Common\Application;
use Destiny\Commerce\SubscriptionsService;
use Destiny\Common\Authentication\AuthenticationService;
use Destiny\Common\Log;
use Destiny\Common\Cron\TaskInterface;
use Destiny\Common\User\UserService;
use Destiny\Common\Utils\Date;
use Destiny\StreamLabs\StreamLabsAlertsType;
use Destiny\StreamLabs\StreamLabsService;
use Doctrine\DBAL\DBALException;

/**
 * @Schedule(frequency=5,period="minute")
 */
class SubscriptionExpire implements TaskInterface {

    /**
     * @return mixed|void
     * @throws DBALException
     */
    public function execute() {
        $authService = AuthenticationService::instance();
        $subscriptionService = SubscriptionsService::instance();
        $users = [];

        // Renew any subscription that has an active payment profile.
        $subscriptions = $subscriptionService->getRecurringSubscriptionsToRenew();
        foreach ($subscriptions as $subscription) {
            try {
                $subType = $subscriptionService->getSubscriptionType($subscription ['subscriptionType']);

                // Because subscriptions can be revived after months of skipped payments;
                // The end date may not simply be behind by the subscription frequency.
                $end = Date::getDateTime($subscription ['endDate']);
                $diff = $end->diff(new \DateTime ('NOW'));
                $end->modify('+' . (intval(($diff->format('%y') * 12)) + intval($diff->format('%m'))) . ' month');
                $end->modify('+' . $subType ['billingFrequency'] . ' ' . strtolower($subType ['billingPeriod']));

                $subscriptionService->updateSubscription([
                    'subscriptionId' => $subscription ['subscriptionId'],
                    'endDate' => $end->format('Y-m-d H:i:s'),
                    'status' => SubscriptionStatus::ACTIVE
                ]);
                $this->sendResubscribeBroadcast($subscription);
                $users[] = $subscription ['userId'];
            } catch (\Exception $e) {
                Log::critical("Could not roll over subscription", $subscription);
            }
        }

        // Expire subscriptions
        $subscriptions = $subscriptionService->getSubscriptionsToExpire();
        if (!empty ($subscriptions)) {
            foreach ($subscriptions as $subscription) {
                $users[] = $subscription ['userId'];
                $subscriptionService->updateSubscription([
                    'subscriptionId' => $subscription ['subscriptionId'],
                    'status' => SubscriptionStatus::EXPIRED
                ]);
            }
        }

        // Update users
        $users = array_unique($users);
        foreach ($users as $id) {
            $authService->flagUserForUpdate($id);
        }

        // Clean-up old unfinished subscriptions (where users have aborted the process)
        $conn = Application::getDbConn();
        $stmt = $conn->prepare('
          DELETE FROM `dfl_users_subscriptions`
          WHERE `status` = :status AND `createdDate` < (NOW() - INTERVAL 1 HOUR)
        ');
        $stmt->bindValue('status', SubscriptionStatus::_NEW, \PDO::PARAM_STR);
        $stmt->execute();
    }

    /**
     * @param array $subscription
     * @throws DBALException
     */
    private function sendResubscribeBroadcast(array $subscription) {
        $userService = UserService::instance();
        $user = $userService->getUserById($subscription['userId']);
        if (!empty($user)) {
            try {
                // the subscription endDate has not been updated with the new subscription time
                $months = max(1, Date::getDateTime($subscription['createdDate'])->diff(Date::getDateTime($subscription['endDate']))->m);
                $months = $months > 1 ? $months . " months" : $months . " month";
                $message = sprintf("%s has resubscribed! active for %s", $user['username'], $months);
                $redisService = ChatRedisService::instance();
                $redisService->sendBroadcast($message);
                $streamLabService = StreamLabsService::withAuth();
                $streamLabService->sendAlert(['message' => $message, 'type' => StreamLabsAlertsType::ALERT_SUBSCRIPTION]);
            } catch (\Exception $e) {
                Log::critical('Could not send resubscribe broadcast', $subscription);
            }
        }
    }
}