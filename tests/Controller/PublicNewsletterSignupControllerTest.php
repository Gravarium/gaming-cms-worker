<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\ExternalConnectorTarget;
use App\Entity\Newsletter\NewsletterSubscription;
use App\Entity\User;
use App\ExternalConnector\ExternalConnectorAdapterRegistry;
use App\ExternalConnector\ExternalConnectorExecutor;
use App\ExternalConnector\ExternalConnectorRegistry;
use App\ExternalConnector\ExternalConnectorTargetDefinition;
use App\ExternalConnector\ExternalConnectorTargetSource;
use App\ExternalConnector\ExternalMailConnectorAdapter;
use App\ExternalConnector\ExternalMailDispatcher;
use App\ExternalConnector\ExternalMailMessage;
use App\Module\CmsModuleManager;
use App\Repository\Newsletter\NewsletterSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final class PublicNewsletterSignupControllerTest extends WebTestCase
{
    public function testGuestCanSubscribeAndConfirmThroughTheExistingOneTimeRoute(): void
    {
        $client = $this->enabledClient();
        $messages = $this->sentMessages();
        $this->replaceMailDispatcher($client, $messages);
        $token = $this->formToken($client);
        $email = $this->email('guest');

        $client->request('POST', '/newsletter/subscribe', [
            '_token' => $token,
            'email' => $email,
            'consent' => 'yes',
        ]);

        self::assertResponseRedirects('/newsletter/subscribe', Response::HTTP_SEE_OTHER);
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Wenn die Adresse für den Newsletter verwendet werden kann, senden wir eine Bestätigung.');
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control', ''));
        self::assertStringNotContainsString($email, (string) $client->getResponse()->getContent());

        $entityManager = $this->entityManager($client);
        $subscription = $this->subscriptions($client)->findByEmail($email);
        self::assertInstanceOf(NewsletterSubscription::class, $subscription);
        self::assertSame(NewsletterSubscription::STATUS_PENDING, $subscription->getStatus());
        self::assertFalse($subscription->canReceive());
        self::assertNull($subscription->getUser());
        self::assertNull($entityManager->getRepository(User::class)->findOneBy(['email' => $email]));

        $message = $messages->getArrayCopy()[0] ?? null;
        self::assertInstanceOf(ExternalMailMessage::class, $message);
        self::assertSame([$email], $message->recipients);
        self::assertSame(1, preg_match(
            '~https?://[^\s]+(/newsletter/confirm/\d+/[A-Za-z0-9_-]{20,120})~',
            $message->text,
            $matches,
        ));
        $confirmPath = parse_url((string) ($matches[1] ?? ''), PHP_URL_PATH);
        self::assertIsString($confirmPath);

        $client->request('GET', $confirmPath);
        self::assertResponseIsSuccessful();

        $entityManager->clear();
        $confirmed = $this->subscriptions($client)->findByEmail($email);
        self::assertInstanceOf(NewsletterSubscription::class, $confirmed);
        self::assertTrue($confirmed->canReceive());

        $client->request('GET', $confirmPath);
        self::assertResponseStatusCodeSame(404);
    }

    public function testInvalidEmailIsEscapedWhenReflectedInTheForm(): void
    {
        $client = $this->enabledClient();
        $messages = $this->sentMessages();
        $this->replaceMailDispatcher($client, $messages);
        $token = $this->formToken($client);
        $suffix = bin2hex(random_bytes(5));
        $payload = '"><script>alert("newsletter-'.$suffix.'")</script><input value="';

        $client->request('POST', '/newsletter/subscribe', [
            '_token' => $token,
            'email' => $payload,
            'consent' => 'yes',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $content = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString($payload, $content);
        self::assertStringContainsString(htmlspecialchars($payload, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $content);

        $form = $client->getCrawler()->filter('form.newsletter-signup-form');
        $emailInput = $form->filter('input[name="email"]');
        self::assertSame(1, $emailInput->count());
        self::assertSame($payload, $emailInput->attr('value'));
        self::assertNull($emailInput->attr('onfocus'));
        self::assertNull($emailInput->attr('oninput'));
        self::assertSame(0, $form->filter('script')->count());
        self::assertNull($this->subscriptions($client)->findByEmail($payload));
        self::assertCount(0, $messages);
    }

    public function testInvalidEmailAndMissingConsentDoNotCreateSubscriptionsOrSendMail(): void
    {
        $client = $this->enabledClient();
        $messages = $this->sentMessages();
        $this->replaceMailDispatcher($client, $messages);
        $token = $this->formToken($client);
        $validEmail = $this->email('consent');

        $client->request('POST', '/newsletter/subscribe', [
            '_token' => $token,
            'email' => 'not-an-email',
            'consent' => 'yes',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $client->request('POST', '/newsletter/subscribe', [
            '_token' => $token,
            'email' => $validEmail,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        self::assertNull($this->subscriptions($client)->findByEmail('not-an-email'));
        self::assertNull($this->subscriptions($client)->findByEmail($validEmail));
        self::assertCount(0, $messages);
    }

    public function testInvalidCsrfDoesNotCreateSubscriptionOrSendMail(): void
    {
        $client = $this->enabledClient();
        $messages = $this->sentMessages();
        $this->replaceMailDispatcher($client, $messages);
        $email = $this->email('csrf');

        $client->request('POST', '/newsletter/subscribe', [
            '_token' => 'invalid-token',
            'email' => $email,
            'consent' => 'yes',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertNull($this->subscriptions($client)->findByEmail($email));
        self::assertCount(0, $messages);
    }

    public function testActiveAndSuppressedAddressesReceiveTheSameAcknowledgementWithoutChangingState(): void
    {
        $client = $this->enabledClient();
        $messages = $this->sentMessages();
        $this->replaceMailDispatcher($client, $messages);
        $now = new \DateTimeImmutable();
        $activeEmail = $this->email('active');
        $suppressedEmail = $this->email('suppressed');

        $active = (new NewsletterSubscription())->setEmail($activeEmail);
        $activeToken = $active->issueConfirmation('account', 'v1', $now);
        $active->confirm($activeToken, $now);
        $suppressed = (new NewsletterSubscription())->setEmail($suppressedEmail);
        $suppressed->suppress('hard_bounce', $now);

        $this->entityManager($client)->persist($active);
        $this->entityManager($client)->persist($suppressed);
        $this->entityManager($client)->flush();

        $token = $this->formToken($client);
        foreach ([$activeEmail, $suppressedEmail] as $email) {
            $client->request('POST', '/newsletter/subscribe', [
                '_token' => $token,
                'email' => $email,
                'consent' => 'yes',
            ]);
            self::assertResponseRedirects('/newsletter/subscribe', Response::HTTP_SEE_OTHER);
            $client->followRedirect();
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'Wenn die Adresse für den Newsletter verwendet werden kann, senden wir eine Bestätigung.');
            self::assertStringNotContainsString($email, (string) $client->getResponse()->getContent());
        }

        self::assertCount(0, $messages);
        $this->entityManager($client)->clear();

        $storedActive = $this->subscriptions($client)->findByEmail($activeEmail);
        $storedSuppressed = $this->subscriptions($client)->findByEmail($suppressedEmail);
        self::assertInstanceOf(NewsletterSubscription::class, $storedActive);
        self::assertInstanceOf(NewsletterSubscription::class, $storedSuppressed);
        self::assertTrue($storedActive->canReceive());
        self::assertSame(NewsletterSubscription::STATUS_SUPPRESSED, $storedSuppressed->getStatus());
    }

    public function testMailFailureKeepsTheGenericAcknowledgementAndSubscriptionPending(): void
    {
        $client = $this->enabledClient();
        $messages = $this->sentMessages();
        $this->replaceMailDispatcher($client, $messages, true);
        $email = $this->email('delivery');
        $token = $this->formToken($client);

        $client->request('POST', '/newsletter/subscribe', [
            '_token' => $token,
            'email' => $email,
            'consent' => 'yes',
        ]);

        self::assertResponseRedirects('/newsletter/subscribe', Response::HTTP_SEE_OTHER);
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Wenn die Adresse für den Newsletter verwendet werden kann, senden wir eine Bestätigung.');
        self::assertStringNotContainsString($email, (string) $client->getResponse()->getContent());

        $subscription = $this->subscriptions($client)->findByEmail($email);
        self::assertInstanceOf(NewsletterSubscription::class, $subscription);
        self::assertSame(NewsletterSubscription::STATUS_PENDING, $subscription->getStatus());
        self::assertFalse($subscription->canReceive());
        self::assertCount(0, $messages);
    }

    public function testEmailAndIpLimitsStopAdditionalConfirmationMail(): void
    {
        $client = $this->enabledClient();
        $messages = $this->sentMessages();
        $this->replaceMailDispatcher($client, $messages);
        $token = $this->formToken($client);
        $email = $this->email('limited');

        for ($attempt = 0; $attempt < 4; ++$attempt) {
            $client->request('POST', '/newsletter/subscribe', [
                '_token' => $token,
                'email' => $email,
                'consent' => 'yes',
            ]);
            if ($attempt < 3) {
                self::assertResponseRedirects('/newsletter/subscribe', Response::HTTP_SEE_OTHER);
            } else {
                self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
            }
        }

        for ($index = 0; $index < 6; ++$index) {
            $client->request('POST', '/newsletter/subscribe', [
                '_token' => $token,
                'email' => $this->email('ip-'.$index),
                'consent' => 'yes',
            ]);
            self::assertResponseRedirects('/newsletter/subscribe', Response::HTTP_SEE_OTHER);
        }

        $client->request('POST', '/newsletter/subscribe', [
            '_token' => $token,
            'email' => $this->email('ip-over'),
            'consent' => 'yes',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertCount(9, $messages);
    }

    public function testSignupReturnsNotFoundWhenNotificationsModuleIsDisabled(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $states = $entityManager->getRepository(CmsModuleState::class);
        $originalState = $states->find('notifications');
        $originalEnabled = $originalState?->isEnabled() ?? true;
        $modules = $client->getContainer()->get(CmsModuleManager::class);
        $modules->setEnabled('notifications', false);

        try {
            $client->request('GET', '/newsletter/subscribe');
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        } finally {
            $state = $states->find('notifications');
            if ($originalState === null) {
                if ($state instanceof CmsModuleState) {
                    $entityManager->remove($state);
                }
            } elseif ($state instanceof CmsModuleState) {
                $state->setEnabled($originalEnabled);
                $entityManager->persist($state);
            }
            $entityManager->flush();
        }
    }

    private function enabledClient(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $modules = $client->getContainer()->get(CmsModuleManager::class);
        if (!$modules->isEnabled('notifications')) {
            $modules->setEnabled('notifications', true);
        }
        $this->resetIpLimiter($client);

        return $client;
    }

    private function formToken(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/newsletter/subscribe');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control', ''));

        return (string) $crawler->filter('input[name="_token"]')->attr('value');
    }

    /** @return \ArrayObject<int, ExternalMailMessage> */
    private function sentMessages(): \ArrayObject
    {
        return new \ArrayObject();
    }

    /** @param \ArrayObject<int, ExternalMailMessage> $messages */
    private function replaceMailDispatcher(KernelBrowser $client, \ArrayObject $messages, bool $fail = false): void
    {
        $target = (new ExternalConnectorTarget())
            ->setCapability(ExternalConnectorTarget::CAPABILITY_MAIL)
            ->setTargetKey('public-newsletter-test')
            ->setProviderKey('public-newsletter-test')
            ->setDisplayName('Public newsletter test')
            ->setConfigurationReference('newsletter.test')
            ->setRequired(true)
            ->setEnabled(true);

        $source = new class([$target]) implements ExternalConnectorTargetSource {
            /** @param list<ExternalConnectorTarget> $targets */
            public function __construct(private readonly array $targets)
            {
            }

            /** @return list<ExternalConnectorTarget> */
            public function enabledFor(string $capability): array
            {
                return $capability === ExternalConnectorTarget::CAPABILITY_MAIL ? $this->targets : [];
            }
        };

        $adapter = new class($messages, $fail) implements ExternalMailConnectorAdapter {
            /** @param \ArrayObject<int, ExternalMailMessage> $messages */
            public function __construct(
                private readonly \ArrayObject $messages,
                private readonly bool $fail,
            ) {
            }

            public function providerKey(): string
            {
                return 'public-newsletter-test';
            }

            public function supports(string $capability): bool
            {
                return $capability === ExternalConnectorTarget::CAPABILITY_MAIL;
            }

            public function send(ExternalConnectorTargetDefinition $target, ExternalMailMessage $message): void
            {
                if ($this->fail) {
                    throw new \RuntimeException('Test transport failure.');
                }
                $this->messages->append($message);
            }
        };

        $dispatcher = new ExternalMailDispatcher(new ExternalConnectorExecutor(
            new ExternalConnectorRegistry($source),
            new ExternalConnectorAdapterRegistry([$adapter]),
        ));
        $client->getContainer()->set(ExternalMailDispatcher::class, $dispatcher);
    }

    private function resetIpLimiter(KernelBrowser $client): void
    {
        $limiter = $client->getContainer()->get('limiter.public_newsletter_signup_ip');
        self::assertInstanceOf(RateLimiterFactory::class, $limiter);
        $limiter->create('ip-127.0.0.1')->reset();
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }

    private function subscriptions(KernelBrowser $client): NewsletterSubscriptionRepository
    {
        return $client->getContainer()->get(NewsletterSubscriptionRepository::class);
    }

    private function email(string $prefix): string
    {
        return $prefix.'-'.bin2hex(random_bytes(6)).'@example.test';
    }
}
