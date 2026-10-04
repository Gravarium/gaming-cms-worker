<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Entity\CmsModuleState;
use App\Entity\PageLayout;
use App\Entity\Social\SocialConversation;
use App\Entity\Social\SocialConversationParticipant;
use App\Entity\Social\SocialMessage;
use App\Entity\User;
use App\Layout\LayoutValidator;
use App\Widget\Social\UnreadConversationsQuery;
use App\Widget\SocialUnreadConversationsWidgetProvider;
use App\Widget\WidgetDefinition;
use App\Widget\WidgetRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SocialUnreadConversationsWidgetProviderTest extends WebTestCase
{
    private const WIDGET_KEY = 'social.unread-conversations';
    private const WIDGET_ID = 'social-unread-widget';

    public function testWidgetShowsOnlyUnreadMessagesAndOpeningEachConversationClearsThem(): void
    {
        $client = static::createClient();
        $ids = $this->newIds();
        $em = $this->em($client);
        $this->enableSocial($client);
        $this->saveHomeWidget($client);
        $suffix = bin2hex(random_bytes(5));

        try {
            $reader = $this->newUser($suffix.'-reader');
            $sender = $this->newUser($suffix.'-sender');
            $em->persist($reader);
            $em->persist($sender);

            $markedConversation = new SocialConversation($sender, SocialConversation::TYPE_DIRECT);
            $markedReader = new SocialConversationParticipant($markedConversation, $reader);
            $markedReader->markRead(new \DateTimeImmutable('-10 minutes'));
            $markedSender = new SocialConversationParticipant($markedConversation, $sender, SocialConversationParticipant::ROLE_OWNER);
            $unreadMessage = new SocialMessage($markedConversation, $sender, 'PRIVATE-UNREAD-'.$suffix);
            $ownMessage = new SocialMessage($markedConversation, $reader, 'PRIVATE-OWN-'.$suffix);
            $deletedMessage = new SocialMessage($markedConversation, $sender, 'PRIVATE-DELETED-'.$suffix);
            $deletedMessage->redactForRetention(new \DateTimeImmutable());

            $newConversation = new SocialConversation($sender, SocialConversation::TYPE_DIRECT);
            $newReader = new SocialConversationParticipant($newConversation, $reader);
            $newSender = new SocialConversationParticipant($newConversation, $sender, SocialConversationParticipant::ROLE_OWNER);
            $newUnreadMessage = new SocialMessage($newConversation, $sender, 'PRIVATE-NEW-'.$suffix);

            $inactiveConversation = new SocialConversation($sender, SocialConversation::TYPE_DIRECT);
            $inactiveReader = new SocialConversationParticipant($inactiveConversation, $reader);
            $inactiveReader->remove(new \DateTimeImmutable());
            $inactiveSender = new SocialConversationParticipant($inactiveConversation, $sender, SocialConversationParticipant::ROLE_OWNER);
            $inactiveMessage = new SocialMessage($inactiveConversation, $sender, 'PRIVATE-INACTIVE-'.$suffix);

            $otherConversation = new SocialConversation($sender, SocialConversation::TYPE_DIRECT);
            $otherSender = new SocialConversationParticipant($otherConversation, $sender, SocialConversationParticipant::ROLE_OWNER);
            $otherMessage = new SocialMessage($otherConversation, $sender, 'PRIVATE-OTHER-'.$suffix);

            $readConversation = new SocialConversation($sender, SocialConversation::TYPE_DIRECT);
            $readReader = new SocialConversationParticipant($readConversation, $reader);
            $readReader->markRead(new \DateTimeImmutable('+1 minute'));
            $readSender = new SocialConversationParticipant($readConversation, $sender, SocialConversationParticipant::ROLE_OWNER);
            $readMessage = new SocialMessage($readConversation, $sender, 'PRIVATE-ALREADY-READ-'.$suffix);

            foreach ([$markedConversation, $newConversation, $inactiveConversation, $otherConversation, $readConversation] as $conversation) {
                $em->persist($conversation);
            }
            foreach ([$markedReader, $markedSender, $newReader, $newSender, $inactiveReader, $inactiveSender, $otherSender, $readReader, $readSender] as $participant) {
                $em->persist($participant);
            }
            foreach ([$unreadMessage, $ownMessage, $deletedMessage, $newUnreadMessage, $inactiveMessage, $otherMessage, $readMessage] as $message) {
                $em->persist($message);
            }
            $em->flush();

            $this->remember($ids, 'users', $reader->getId());
            $this->remember($ids, 'users', $sender->getId());
            foreach ([$markedConversation, $newConversation, $inactiveConversation, $otherConversation, $readConversation] as $conversation) {
                $this->remember($ids, 'conversations', $conversation->getId());
            }
            foreach ([$markedReader, $markedSender, $newReader, $newSender, $inactiveReader, $inactiveSender, $otherSender, $readReader, $readSender] as $participant) {
                $this->remember($ids, 'participants', $participant->getId());
            }
            foreach ([$unreadMessage, $ownMessage, $deletedMessage, $newUnreadMessage, $inactiveMessage, $otherMessage, $readMessage] as $message) {
                $this->remember($ids, 'messages', $message->getId());
            }

            $registry = $client->getContainer()->get(WidgetRegistry::class);
            $definition = $registry->get(self::WIDGET_KEY);
            self::assertInstanceOf(WidgetDefinition::class, $definition);
            self::assertSame('social', $definition->module);
            self::assertTrue($registry->available(self::WIDGET_KEY));
            $availableKeys = array_map(
                static fn (WidgetDefinition $item): string => $item->key,
                $registry->availableDefinitions(),
            );
            self::assertContains(self::WIDGET_KEY, $availableKeys);

            $query = $client->getContainer()->get(UnreadConversationsQuery::class);
            self::assertSame(
                ['unreadConversations' => 2, 'unreadMessages' => 2],
                $query->forUser($reader),
            );

            $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('#widget-'.self::WIDGET_ID, 'Melde dich an');
            self::assertStringNotContainsString('PRIVATE-UNREAD-'.$suffix, (string) $client->getResponse()->getContent());

            $client->loginUser($reader);
            $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('#widget-'.self::WIDGET_ID, 'Ungelesene Unterhaltungen: 2');
            self::assertSelectorTextContains('#widget-'.self::WIDGET_ID, 'Ungelesene Nachrichten: 2');
            foreach (['PRIVATE-UNREAD-', 'PRIVATE-OWN-', 'PRIVATE-DELETED-', 'PRIVATE-NEW-', 'PRIVATE-INACTIVE-', 'PRIVATE-OTHER-', 'PRIVATE-ALREADY-READ-'] as $privateBody) {
                self::assertStringNotContainsString($privateBody.$suffix, (string) $client->getResponse()->getContent());
            }
            $this->assertPrivateNoStore($client);

            foreach ([$markedConversation, $newConversation] as $conversation) {
                $conversationId = $conversation->getId();
                self::assertNotNull($conversationId);
                $client->request('GET', '/social/conversations/'.$conversationId);
                self::assertResponseIsSuccessful();
            }

            $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('#widget-'.self::WIDGET_ID, 'Du hast keine ungelesenen Nachrichten.');
            self::assertSelectorTextContains('#widget-'.self::WIDGET_ID, 'Posteingang öffnen');
            $this->assertPrivateNoStore($client);
        } finally {
            $this->cleanup($client, $ids);
        }
    }

    public function testMemberWithoutConversationMembershipSeesNoUnreadData(): void
    {
        $client = static::createClient();
        $ids = $this->newIds();
        $em = $this->em($client);
        $this->enableSocial($client);
        $this->saveHomeWidget($client);
        $suffix = bin2hex(random_bytes(5));

        try {
            $viewer = $this->newUser($suffix.'-viewer');
            $sender = $this->newUser($suffix.'-sender');
            $em->persist($viewer);
            $em->persist($sender);
            $conversation = new SocialConversation($sender, SocialConversation::TYPE_DIRECT);
            $senderParticipant = new SocialConversationParticipant($conversation, $sender, SocialConversationParticipant::ROLE_OWNER);
            $message = new SocialMessage($conversation, $sender, 'PRIVATE-NON-MEMBER-'.$suffix);
            $em->persist($conversation);
            $em->persist($senderParticipant);
            $em->persist($message);
            $em->flush();

            $this->remember($ids, 'users', $viewer->getId());
            $this->remember($ids, 'users', $sender->getId());
            $this->remember($ids, 'conversations', $conversation->getId());
            $this->remember($ids, 'participants', $senderParticipant->getId());
            $this->remember($ids, 'messages', $message->getId());

            $client->loginUser($viewer);
            $client->request('GET', '/');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('#widget-'.self::WIDGET_ID, 'Du hast keine ungelesenen Nachrichten.');
            self::assertStringNotContainsString('PRIVATE-NON-MEMBER-'.$suffix, (string) $client->getResponse()->getContent());
            $this->assertPrivateNoStore($client);
        } finally {
            $this->cleanup($client, $ids);
        }
    }

    public function testDisabledSocialHidesTheWidgetAndItsData(): void
    {
        $client = static::createClient();
        $ids = $this->newIds();
        $this->enableSocial($client);
        $this->saveHomeWidget($client);
        $em = $this->em($client);
        $state = $em->find(CmsModuleState::class, 'social');
        self::assertInstanceOf(CmsModuleState::class, $state);
        $state->setEnabled(false);
        $em->flush();

        try {
            $registry = $client->getContainer()->get(WidgetRegistry::class);
            self::assertFalse($registry->available(self::WIDGET_KEY));
            self::assertSame([], $registry->data(self::WIDGET_KEY, []));
            self::assertNotContains(
                self::WIDGET_KEY,
                array_map(static fn (WidgetDefinition $item): string => $item->key, $registry->availableDefinitions()),
            );

            $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('#widget-'.self::WIDGET_ID);
        } finally {
            $this->cleanup($client, $ids);
        }
    }

    /** @return array{users: list<int>, conversations: list<int>, participants: list<int>, messages: list<int>} */
    private function newIds(): array
    {
        return ['users' => [], 'conversations' => [], 'participants' => [], 'messages' => []];
    }

    /** @param array<string, list<int>> $ids */
    private function remember(array &$ids, string $collection, ?int $id): void
    {
        if ($id !== null) {
            $ids[$collection][] = $id;
        }
    }

    private function enableSocial(KernelBrowser $client): void
    {
        $em = $this->em($client);
        $existing = $em->find(CmsModuleState::class, 'social');
        if ($existing instanceof CmsModuleState) {
            $em->remove($existing);
            $em->flush();
        }

        $state = (new CmsModuleState())->setModuleKey('social')->updateVersion('1.0.0');
        $state->setEnabled(true);
        $em->persist($state);
        $em->flush();
    }

    private function saveHomeWidget(KernelBrowser $client): void
    {
        $container = $client->getContainer();
        $validator = $container->get(LayoutValidator::class);
        $document = $validator->defaults('nebula')->toArray();
        $document['widgets'][] = [
            'id' => self::WIDGET_ID,
            'type' => self::WIDGET_KEY,
            'region' => 'main',
            'enabled' => true,
            'config' => [],
        ];
        $document = $validator->validate($document)->toArray();

        $em = $this->em($client);
        $existing = $em->find(PageLayout::class, 'home');
        if ($existing instanceof PageLayout) {
            $em->remove($existing);
            $em->flush();
        }
        $layout = new PageLayout('home');
        $layout->replace($document);
        $em->persist($layout);
        $em->flush();
    }

    /** @param array<string, list<int>> $ids */
    private function cleanup(KernelBrowser $client, array $ids): void
    {
        $em = $this->em($client);
        $layout = $em->find(PageLayout::class, 'home');
        if ($layout instanceof PageLayout) {
            $em->remove($layout);
        }
        $state = $em->find(CmsModuleState::class, 'social');
        if ($state instanceof CmsModuleState) {
            $em->remove($state);
        }

        foreach ([
            'messages' => SocialMessage::class,
            'participants' => SocialConversationParticipant::class,
            'conversations' => SocialConversation::class,
            'users' => User::class,
        ] as $collection => $class) {
            foreach ($ids[$collection] as $id) {
                $entity = $em->find($class, $id);
                if ($entity !== null) {
                    $em->remove($entity);
                }
            }
        }

        $em->flush();
    }

    private function newUser(string $suffix): User
    {
        return (new User())
            ->setEmail('social-widget-'.$suffix.'@example.test')
            ->setDisplayName('Social widget '.$suffix)
            ->setPassword('unused-test-hash')
            ->verifyEmail();
    }

    private function assertPrivateNoStore(KernelBrowser $client): void
    {
        $cacheControl = strtolower((string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringContainsString('max-age=0', $cacheControl);
        self::assertSame('no-cache', $client->getResponse()->headers->get('Pragma'));
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
