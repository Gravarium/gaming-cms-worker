<?php
declare(strict_types=1);
namespace App\Service;
use App\Entity\Guild;
use App\Entity\MemberNotification;
use App\ExternalConnector\ExternalConnectorExecutionSummary;
use App\ExternalConnector\ExternalNotificationDispatcher;
use App\ExternalConnector\ExternalNotificationMessage;
use App\Repository\GuildMemberRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
final class GuildNotifier
{
    public function __construct(
        private readonly GuildMemberRepository $members,
        private readonly EntityManagerInterface $entityManager,
        private readonly GuildWebhookSender $discord,
        private readonly ExternalNotificationDispatcher $externalNotifications,
        private readonly LoggerInterface $logger,
    ) {}
    public function notify(Guild $guild, string $type, string $title, string $message): void
    {
        foreach ($this->members->usersForGuild($guild) as $user) {
            $notification = (new MemberNotification())->setUser($user)->setGuild($guild)->setType($type)->setTitle($title)->setMessage($message)->setLink('/guild-area/'.$guild->getId());
            $this->entityManager->persist($notification);
        }
        $summary = $this->externalNotifications->send(new ExternalNotificationMessage($type, $title, $message, '/guild-area/'.$guild->getId(), 'guild:'.$guild->getId()));
        if ($summary->status() === ExternalConnectorExecutionSummary::STATUS_NOT_CONFIGURED) {
            $this->discord->notify($guild, $type, $title, $message);
        } elseif ($summary->status() === ExternalConnectorExecutionSummary::STATUS_FAILED) {
            $this->logger->warning('Required external guild notification delivery failed.', ['guild_id' => $guild->getId(), 'target_count' => count($summary->results)]);
        }
    }
}
