<?php

declare(strict_types=1);

namespace App\Tests\Newsletter;

use App\Entity\CmsModuleState;
use App\Entity\Newsletter\NewsletterSubscription;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class NewsletterConsentControllerTest extends WebTestCase
{
    public function testConfirmationAndUnsubscribeBearerTokensAreOneWayAndStateBound(): void
    {
        $client = static::createClient();
        $module = $this->em($client)->find(CmsModuleState::class, 'notifications');
        if ($module instanceof CmsModuleState) {
            $module->setEnabled(true);
            $this->em($client)->flush();
        }

        $subscription = (new NewsletterSubscription())->setEmail('consent-'.bin2hex(random_bytes(4)).'@example.test');
        $now = new \DateTimeImmutable();
        $confirmToken = $subscription->issueConfirmation('account', 'v1', $now);
        $this->em($client)->persist($subscription);
        $this->em($client)->flush();
        $id = $subscription->getId();
        self::assertNotNull($id);

        $client->request('GET', '/newsletter/confirm/'.$id.'/wrong-token-value-that-is-long-enough');
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/newsletter/confirm/'.$id.'/'.$confirmToken);
        self::assertResponseIsSuccessful();

        $this->em($client)->refresh($subscription);
        self::assertTrue($subscription->canReceive());

        $unsubscribeToken = $subscription->issueUnsubscribeToken(new \DateTimeImmutable());
        $this->em($client)->flush();
        $client->request('GET', '/newsletter/unsubscribe/'.$id.'/'.$unsubscribeToken);
        self::assertResponseIsSuccessful();

        $this->em($client)->refresh($subscription);
        self::assertSame(NewsletterSubscription::STATUS_UNSUBSCRIBED, $subscription->getStatus());
        self::assertFalse($subscription->canReceive());

        $client->request('GET', '/newsletter/unsubscribe/'.$id.'/'.$unsubscribeToken);
        self::assertResponseStatusCodeSame(404);
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
