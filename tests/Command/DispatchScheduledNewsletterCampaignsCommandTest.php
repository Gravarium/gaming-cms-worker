<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\DispatchScheduledNewsletterCampaignsCommand;
use App\Entity\CmsModuleState;
use App\Entity\Newsletter\NewsletterCampaign;
use App\Entity\Newsletter\NewsletterDelivery;
use App\Entity\Newsletter\NewsletterSubscription;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class DispatchScheduledNewsletterCampaignsCommandTest extends KernelTestCase
{
    public function testDueCampaignsAndDueRetriesAreProcessedButFutureAndCancelledCampaignsAreNot(): void
    {
        self::bootKernel();
        $entityManager = $this->entityManager();
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $suffix = bin2hex(random_bytes(5));
            $now = new \DateTimeImmutable();
            $creator = $this->persistUser($entityManager, $suffix);

            $dueCampaign = $this->campaign($creator, 'due-'.$suffix);
            $dueCampaign->setSegment(NewsletterCampaign::SEGMENT_MEMBERS);
            $dueCampaign->schedule($now->modify('-10 minutes'), $now->modify('-1 day'));

            $futureCampaign = $this->campaign($creator, 'future-'.$suffix);
            $futureCampaign->schedule($now->modify('+1 day'), $now);

            $cancelledCampaign = $this->campaign($creator, 'cancelled-'.$suffix);
            $cancelledCampaign->schedule($now->modify('+1 hour'), $now);
            $cancelledCampaign->cancel($now);

            $retryCampaign = $this->campaign($creator, 'retry-'.$suffix);
            $retryCampaign->setSegment(NewsletterCampaign::SEGMENT_MEMBERS);
            $retryCampaign->markSending($now->modify('-1 hour'));
            $retrySubscription = $this->activeSubscription('retry-'.$suffix.'@example.test', $now->modify('-1 day'));
            $dueRetry = new NewsletterDelivery($retryCampaign, $retrySubscription);
            $dueRetry->markFailure('transport_unavailable', $now->modify('-1 hour'));

            $futureRetryCampaign = $this->campaign($creator, 'future-retry-'.$suffix);
            $futureRetryCampaign->setSegment(NewsletterCampaign::SEGMENT_MEMBERS);
            $futureRetryCampaign->markSending($now->modify('-1 hour'));
            $futureRetrySubscription = $this->activeSubscription('future-retry-'.$suffix.'@example.test', $now->modify('-1 day'));
            $futureRetry = new NewsletterDelivery($futureRetryCampaign, $futureRetrySubscription);
            $futureRetry->markFailure('transport_unavailable', $now);

            foreach ([$dueCampaign, $futureCampaign, $cancelledCampaign, $retryCampaign, $futureRetryCampaign, $retrySubscription, $futureRetrySubscription, $dueRetry, $futureRetry] as $record) {
                $entityManager->persist($record);
            }
            $entityManager->flush();

            $ids = [
                'due' => $dueCampaign->getId(),
                'future' => $futureCampaign->getId(),
                'cancelled' => $cancelledCampaign->getId(),
                'retry' => $retryCampaign->getId(),
                'futureRetry' => $futureRetryCampaign->getId(),
                'dueDelivery' => $dueRetry->getId(),
                'futureDelivery' => $futureRetry->getId(),
            ];
            foreach ($ids as $id) {
                self::assertNotNull($id);
            }

            $tester = $this->commandTester();
            self::assertSame(Command::SUCCESS, $tester->execute([]));
            self::assertStringContainsString('2 Kampagnen verarbeitet', $tester->getDisplay());

            $entityManager->clear();
            $storedDue = $entityManager->find(NewsletterCampaign::class, $ids['due']);
            $storedFuture = $entityManager->find(NewsletterCampaign::class, $ids['future']);
            $storedCancelled = $entityManager->find(NewsletterCampaign::class, $ids['cancelled']);
            $storedRetryCampaign = $entityManager->find(NewsletterCampaign::class, $ids['retry']);
            $storedFutureRetryCampaign = $entityManager->find(NewsletterCampaign::class, $ids['futureRetry']);
            $storedDueDelivery = $entityManager->find(NewsletterDelivery::class, $ids['dueDelivery']);
            $storedFutureDelivery = $entityManager->find(NewsletterDelivery::class, $ids['futureDelivery']);

            self::assertInstanceOf(NewsletterCampaign::class, $storedDue);
            self::assertInstanceOf(NewsletterCampaign::class, $storedFuture);
            self::assertInstanceOf(NewsletterCampaign::class, $storedCancelled);
            self::assertInstanceOf(NewsletterCampaign::class, $storedRetryCampaign);
            self::assertInstanceOf(NewsletterCampaign::class, $storedFutureRetryCampaign);
            self::assertInstanceOf(NewsletterDelivery::class, $storedDueDelivery);
            self::assertInstanceOf(NewsletterDelivery::class, $storedFutureDelivery);

            self::assertSame(NewsletterCampaign::STATUS_SENT, $storedDue->getStatus());
            self::assertSame(NewsletterCampaign::STATUS_SCHEDULED, $storedFuture->getStatus());
            self::assertSame(NewsletterCampaign::STATUS_CANCELLED, $storedCancelled->getStatus());
            self::assertSame(2, $storedDueDelivery->getAttempts());
            self::assertSame(1, $storedFutureDelivery->getAttempts());
            self::assertTrue(in_array($storedRetryCampaign->getStatus(), [NewsletterCampaign::STATUS_SENDING, NewsletterCampaign::STATUS_SENT], true));
            self::assertSame(NewsletterCampaign::STATUS_SENDING, $storedFutureRetryCampaign->getStatus());

            $display = $tester->getDisplay();
            self::assertStringNotContainsString('retry-'.$suffix.'@example.test', $display);
            self::assertStringNotContainsString('future-retry-'.$suffix.'@example.test', $display);
            self::assertStringNotContainsString('Sensitive newsletter body', $display);

            self::assertSame(Command::SUCCESS, $this->commandTester()->execute([]));
            $entityManager->clear();
            $storedDueDelivery = $entityManager->find(NewsletterDelivery::class, $ids['dueDelivery']);
            $storedFutureDelivery = $entityManager->find(NewsletterDelivery::class, $ids['futureDelivery']);
            self::assertInstanceOf(NewsletterDelivery::class, $storedDueDelivery);
            self::assertInstanceOf(NewsletterDelivery::class, $storedFutureDelivery);
            self::assertSame(2, $storedDueDelivery->getAttempts());
            self::assertSame(1, $storedFutureDelivery->getAttempts());
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $entityManager->clear();
        }
    }

    public function testCampaignAndDeliveryWorkIsBoundedAndSendingCampaignResumes(): void
    {
        self::bootKernel();
        $entityManager = $this->entityManager();
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $suffix = bin2hex(random_bytes(5));
            $now = new \DateTimeImmutable();
            $creator = $this->persistUser($entityManager, $suffix);
            $older = $this->campaign($creator, 'older-'.$suffix);
            $older->schedule($now->modify('-2 hours'), $now->modify('-1 day'));
            $later = $this->campaign($creator, 'later-'.$suffix);
            $later->schedule($now->modify('-1 hour'), $now->modify('-1 day'));
            $subscriptions = [
                $this->activeSubscription('first-'.$suffix.'@example.test', $now->modify('-1 day')),
                $this->activeSubscription('second-'.$suffix.'@example.test', $now->modify('-1 day')),
            ];

            foreach ([$older, $later, ...$subscriptions] as $record) {
                $entityManager->persist($record);
            }
            $entityManager->flush();
            $olderId = $older->getId();
            $laterId = $later->getId();
            self::assertNotNull($olderId);
            self::assertNotNull($laterId);

            $tester = $this->commandTester();
            self::assertSame(Command::SUCCESS, $tester->execute([
                '--campaign-limit' => '1',
                '--delivery-quota' => '1',
            ]));
            $entityManager->clear();

            $storedOlder = $entityManager->find(NewsletterCampaign::class, $olderId);
            $storedLater = $entityManager->find(NewsletterCampaign::class, $laterId);
            self::assertInstanceOf(NewsletterCampaign::class, $storedOlder);
            self::assertInstanceOf(NewsletterCampaign::class, $storedLater);
            self::assertNotSame(NewsletterCampaign::STATUS_SCHEDULED, $storedOlder->getStatus());
            self::assertSame(NewsletterCampaign::STATUS_SCHEDULED, $storedLater->getStatus());

            $olderDeliveries = $entityManager->getRepository(NewsletterDelivery::class)->findBy(['campaign' => $storedOlder]);
            self::assertCount(2, $olderDeliveries);
            self::assertSame(1, array_sum(array_map(
                static fn (NewsletterDelivery $delivery): int => $delivery->getAttempts(),
                $olderDeliveries,
            )));

            self::assertSame(Command::SUCCESS, $this->commandTester()->execute([
                '--campaign-limit' => '1',
                '--delivery-quota' => '1',
            ]));
            $entityManager->clear();
            $storedOlder = $entityManager->find(NewsletterCampaign::class, $olderId);
            $storedLater = $entityManager->find(NewsletterCampaign::class, $laterId);
            self::assertInstanceOf(NewsletterCampaign::class, $storedOlder);
            self::assertInstanceOf(NewsletterCampaign::class, $storedLater);
            self::assertSame(2, array_sum(array_map(
                static fn (NewsletterDelivery $delivery): int => $delivery->getAttempts(),
                $entityManager->getRepository(NewsletterDelivery::class)->findBy(['campaign' => $storedOlder]),
            )));
            self::assertSame(NewsletterCampaign::STATUS_SCHEDULED, $storedLater->getStatus());

            self::assertSame(Command::SUCCESS, $this->commandTester()->execute([
                '--campaign-limit' => '1',
                '--delivery-quota' => '1',
            ]));
            $entityManager->clear();
            $storedLater = $entityManager->find(NewsletterCampaign::class, $laterId);
            self::assertInstanceOf(NewsletterCampaign::class, $storedLater);
            self::assertNotSame(NewsletterCampaign::STATUS_SCHEDULED, $storedLater->getStatus());
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $entityManager->clear();
        }
    }

    public function testDisabledNotificationsAndInvalidLimitsDoNotDispatch(): void
    {
        self::bootKernel();
        $entityManager = $this->entityManager();
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $suffix = bin2hex(random_bytes(5));
            $now = new \DateTimeImmutable();
            $creator = $this->persistUser($entityManager, $suffix);
            $campaign = $this->campaign($creator, 'disabled-'.$suffix);
            $campaign->schedule($now->modify('-5 minutes'), $now->modify('-1 day'));
            $entityManager->persist($campaign);
            $entityManager->persist((new CmsModuleState())->setModuleKey('notifications')->setEnabled(false));
            $entityManager->flush();
            $campaignId = $campaign->getId();
            self::assertNotNull($campaignId);

            $tester = $this->commandTester();
            self::assertSame(Command::SUCCESS, $tester->execute([]));
            self::assertStringContainsString('deaktiviert', $tester->getDisplay());
            self::assertSame(Command::FAILURE, $tester->execute(['--campaign-limit' => '0']));
            self::assertSame(Command::FAILURE, $tester->execute(['--campaign-limit' => '101']));
            self::assertSame(Command::FAILURE, $tester->execute(['--delivery-quota' => '1001']));

            $entityManager->clear();
            $storedCampaign = $entityManager->find(NewsletterCampaign::class, $campaignId);
            self::assertInstanceOf(NewsletterCampaign::class, $storedCampaign);
            self::assertSame(NewsletterCampaign::STATUS_SCHEDULED, $storedCampaign->getStatus());
            self::assertCount(0, $entityManager->getRepository(NewsletterDelivery::class)->findBy(['campaign' => $storedCampaign]));
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $entityManager->clear();
        }
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    private function commandTester(): CommandTester
    {
        $command = static::getContainer()->get(DispatchScheduledNewsletterCampaignsCommand::class);
        self::assertInstanceOf(DispatchScheduledNewsletterCampaignsCommand::class, $command);

        return new CommandTester($command);
    }

    private function persistUser(EntityManagerInterface $entityManager, string $suffix): User
    {
        $user = (new User())
            ->setEmail('newsletter-scheduled-command-'.$suffix.'@example.test')
            ->setDisplayName('Newsletter scheduled command test')
            ->verifyEmail();
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    private function campaign(User $creator, string $title): NewsletterCampaign
    {
        return (new NewsletterCampaign())
            ->setCreatedBy($creator)
            ->setTitle($title)
            ->setSubject('Sensitive newsletter subject')
            ->setBodyText('Sensitive newsletter body');
    }

    private function activeSubscription(string $email, \DateTimeImmutable $createdAt): NewsletterSubscription
    {
        $subscription = (new NewsletterSubscription())->setEmail($email);
        $token = $subscription->issueConfirmation('test', 'v1', $createdAt);
        $subscription->confirm($token, $createdAt->modify('+1 minute'));

        return $subscription;
    }
}
