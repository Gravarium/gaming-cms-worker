<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Profile\MemberProfile;
use App\Entity\Social\SocialConversation;
use App\Entity\Social\SocialConversationParticipant;
use App\Entity\Social\SocialPrivacySettings;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SocialGroupConversationCreationTest extends WebTestCase
{
    public function testRecipientChoicesOnlyExposeActiveUnlockedVisibleProfiles(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        $users = [];

        try {
            $actor = $this->user($em, 'WCP-558 Conversation Creator');
            $users[] = $actor;

            $public = $this->user($em, 'WCP-558 Public Member');
            $publicProfile = $this->profile($em, $public);
            $users[] = $public;

            $membersOnly = $this->user($em, 'WCP-558 Members-Only Member');
            $membersOnlyProfile = $this->profile($em, $membersOnly, MemberProfile::VISIBILITY_MEMBERS);
            $users[] = $membersOnly;

            $private = $this->user($em, 'WCP-558 Private Name');
            $privateProfile = $this->profile($em, $private, MemberProfile::VISIBILITY_PRIVATE);
            $users[] = $private;

            $inactive = $this->user($em, 'WCP-558 Inactive Name');
            $inactive->setActive(false);
            $inactiveProfile = $this->profile($em, $inactive);
            $users[] = $inactive;

            $locked = $this->user($em, 'WCP-558 Locked Name');
            $locked->lockUntil(new \DateTimeImmutable('+1 day'), 'WCP-558 fixture');
            $lockedProfile = $this->profile($em, $locked);
            $users[] = $locked;

            $withoutProfile = $this->user($em, 'WCP-558 No Profile Name');
            $users[] = $withoutProfile;
            $em->flush();

            $client->loginUser($actor);
            $client->request('GET', '/social/conversations/new');

            self::assertResponseIsSuccessful();
            self::assertSelectorExists('select[name="social_conversation[recipientIds][]"][multiple]');
            $html = (string) $client->getResponse()->getContent();
            self::assertStringContainsString($public->getDisplayName().' (#'.$public->getId().')', $html);
            self::assertStringContainsString($membersOnly->getDisplayName().' (#'.$membersOnly->getId().')', $html);
            self::assertStringNotContainsString($actor->getDisplayName(), $html);
            self::assertStringNotContainsString($private->getDisplayName(), $html);
            self::assertStringNotContainsString($inactive->getDisplayName(), $html);
            self::assertStringNotContainsString($locked->getDisplayName(), $html);
            self::assertStringNotContainsString($withoutProfile->getDisplayName(), $html);
            foreach ($users as $user) {
                self::assertStringNotContainsString($user->getEmail(), $html);
            }
            self::assertStringNotContainsString('Private biography', $html);
        } finally {
            $this->cleanup($client, $users);
        }
    }

    public function testMemberCanCreateAGroupWithSeveralRecipients(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        $users = [];

        try {
            $actor = $this->user($em, 'WCP-558 Group Creator');
            $first = $this->user($em, 'WCP-558 Group Member One');
            $second = $this->user($em, 'WCP-558 Group Member Two');
            $users = [$actor, $first, $second];
            $this->profile($em, $first);
            $this->profile($em, $second);
            $em->flush();

            $client->loginUser($actor);
            $crawler = $client->request('GET', '/social/conversations/new');
            $form = $crawler->selectButton('Erstellen')->form();
            $form['social_conversation[recipientIds]'] = [
                (string) $first->getId(),
                (string) $second->getId(),
            ];
            $form['social_conversation[title]'] = 'WCP-558 Raid group';
            $client->submit($form);

            self::assertResponseRedirects();
            $location = (string) $client->getResponse()->headers->get('Location');
            self::assertMatchesRegularExpression('#^/social/conversations/\d+$#', $location);
            $conversationId = (int) basename($location);
            $conversation = $this->em($client)->find(SocialConversation::class, $conversationId);
            self::assertInstanceOf(SocialConversation::class, $conversation);
            self::assertTrue($conversation->isGroup());

            $participants = $this->em($client)->getRepository(SocialConversationParticipant::class)->findBy([
                'conversation' => $conversation,
                'status' => SocialConversationParticipant::STATUS_ACTIVE,
            ]);
            self::assertCount(3, $participants);
            $participantIds = array_map(
                static fn (SocialConversationParticipant $participant): ?int => $participant->getUser()->getId(),
                $participants,
            );
            sort($participantIds);
            $expectedIds = [$actor->getId(), $first->getId(), $second->getId()];
            sort($expectedIds);
            self::assertSame($expectedIds, $participantIds);

            $client->request('GET', $location);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'WCP-558 Raid group');
        } finally {
            $this->cleanup($client, $users);
        }
    }

    public function testDirectMessageStillRequiresOneRecipientAndMultipleRecipientsNeedATitle(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        $users = [];

        try {
            $actor = $this->user($em, 'WCP-558 Direct Creator');
            $first = $this->user($em, 'WCP-558 Direct Member One');
            $second = $this->user($em, 'WCP-558 Direct Member Two');
            $users = [$actor, $first, $second];
            $this->profile($em, $first);
            $this->profile($em, $second);
            $em->flush();
            $client->loginUser($actor);

            $crawler = $client->request('GET', '/social/conversations/new');
            $form = $crawler->selectButton('Erstellen')->form();
            $form['social_conversation[recipientIds]'] = [(string) $first->getId()];
            $form['social_conversation[title]'] = '';
            $client->submit($form);
            self::assertResponseRedirects();

            $direct = $this->em($client)->getRepository(SocialConversation::class)->findOneBy([
                'createdBy' => $actor,
                'type' => SocialConversation::TYPE_DIRECT,
            ]);
            self::assertInstanceOf(SocialConversation::class, $direct);

            $crawler = $client->request('GET', '/social/conversations/new');
            $form = $crawler->selectButton('Erstellen')->form();
            $form['social_conversation[recipientIds]'] = [
                (string) $first->getId(),
                (string) $second->getId(),
            ];
            $form['social_conversation[title]'] = '';
            $client->submit($form);

            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('body', 'Für mehrere Mitglieder gib einen Gruppentitel ein.');
            self::assertSame(0, $this->em($client)->getRepository(SocialConversation::class)->count([
                'createdBy' => $actor,
                'type' => SocialConversation::TYPE_GROUP,
            ]));
        } finally {
            $this->cleanup($client, $users);
        }
    }

    public function testGroupCannotExceedTwentyParticipantsIncludingCreator(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        $users = [];

        try {
            $actor = $this->user($em, 'WCP-558 Group Limit Creator');
            $users[] = $actor;
            $recipients = [];
            for ($index = 0; $index < 20; ++$index) {
                $recipient = $this->user($em, sprintf('WCP-558 Group Member %02d', $index));
                $users[] = $recipient;
                $recipients[] = $recipient;
                $this->profile($em, $recipient);
            }
            $em->flush();
            $client->loginUser($actor);

            $crawler = $client->request('GET', '/social/conversations/new');
            $form = $crawler->selectButton('Erstellen')->form();
            $form['social_conversation[recipientIds]'] = array_map(
                static fn (User $recipient): string => (string) $recipient->getId(),
                $recipients,
            );
            $form['social_conversation[title]'] = 'WCP-558 Oversized group';
            $client->submit($form);

            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('body', 'höchstens 20 Personen einschließlich dir enthalten');
            self::assertSame(0, $this->em($client)->getRepository(SocialConversation::class)->count([
                'createdBy' => $actor,
            ]));
        } finally {
            $this->cleanup($client, $users);
        }
    }

    public function testRecipientMessagePrivacyIsEnforcedWhenCreatingAConversation(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        $users = [];

        try {
            $actor = $this->user($em, 'WCP-558 Privacy Creator');
            $recipient = $this->user($em, 'WCP-558 Nobody Recipient');
            $users = [$actor, $recipient];
            $this->profile($em, $recipient);
            $privacy = (new SocialPrivacySettings($recipient))
                ->setMessagePolicy(SocialPrivacySettings::MESSAGE_NOBODY);
            $em->persist($privacy);
            $em->flush();

            $client->loginUser($actor);
            $crawler = $client->request('GET', '/social/conversations/new');
            $form = $crawler->selectButton('Erstellen')->form();
            $form['social_conversation[recipientIds]'] = [(string) $recipient->getId()];
            $form['social_conversation[title]'] = '';
            $client->submit($form);

            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('body', 'does not accept a conversation from you');
            self::assertSame(0, $this->em($client)->getRepository(SocialConversation::class)->count([
                'createdBy' => $actor,
            ]));
        } finally {
            $this->cleanup($client, $users);
        }
    }

    public function testNoVisibleRecipientHasAnExplicitEmptyState(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        $users = [];

        try {
            $actor = $this->user($em, 'WCP-558 Empty Choice Creator');
            $users[] = $actor;
            $em->flush();
            $client->loginUser($actor);

            $client->request('GET', '/social/conversations/new');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'Zurzeit gibt es keine aktiven Mitglieder mit einem für dich sichtbaren Anzeigenamen.');
            self::assertSelectorExists('button[type="submit"][disabled]');
        } finally {
            $this->cleanup($client, $users);
        }
    }

    public function testSocialModuleDeactivationHidesConversationCreation(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        $users = [];
        $state = $em->find(CmsModuleState::class, 'social');
        $createdState = !$state instanceof CmsModuleState;
        $originalEnabled = $state?->isEnabled();

        try {
            $actor = $this->user($em, 'WCP-558 Disabled Module Creator');
            $users[] = $actor;
            if (!$state instanceof CmsModuleState) {
                $state = (new CmsModuleState())->setModuleKey('social');
                $em->persist($state);
            }
            $state->setEnabled(false);
            $em->flush();
            $client->loginUser($actor);

            $client->request('GET', '/social/conversations/new');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $cleanupEm = $this->em($client);
            $currentState = $cleanupEm->find(CmsModuleState::class, 'social');
            if ($currentState instanceof CmsModuleState) {
                if ($createdState) {
                    $cleanupEm->remove($currentState);
                } else {
                    $currentState->setEnabled((bool) $originalEnabled);
                }
            }
            $cleanupEm->flush();
            $this->cleanup($client, $users);
        }
    }

    private function user(EntityManagerInterface $em, string $displayName): User
    {
        $user = (new User())
            ->setEmail('social-group-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName($displayName)
            ->setPassword('unused-test-hash')
            ->verifyEmail();
        $em->persist($user);

        return $user;
    }

    private function profile(EntityManagerInterface $em, User $user, string $visibility = MemberProfile::VISIBILITY_PUBLIC): MemberProfile
    {
        $profile = (new MemberProfile($user))->setDisplayNameVisibility($visibility);
        $em->persist($profile);

        return $profile;
    }

    /** @param list<User> $users */
    private function cleanup(KernelBrowser $client, array $users): void
    {
        $em = $this->em($client);
        $userIds = array_values(array_filter(
            array_map(static fn (User $user): ?int => $user->getId(), $users),
            static fn (?int $id): bool => $id !== null,
        ));

        if ($userIds !== []) {
            $placeholders = implode(', ', array_fill(0, count($userIds), '?'));
            $connection = $em->getConnection();
            $em->clear();
            $connection->executeStatement(
                sprintf(
                    'DELETE FROM social_conversation_participant WHERE user_id IN (%1$s) OR conversation_id IN (SELECT id FROM social_conversation WHERE created_by_id IN (%1$s))',
                    $placeholders,
                ),
                [...$userIds, ...$userIds],
            );
            $connection->executeStatement(
                sprintf('DELETE FROM social_conversation WHERE created_by_id IN (%s)', $placeholders),
                $userIds,
            );
        } else {
            $em->clear();
        }

        foreach ($userIds as $id) {
            $profile = $em->find(MemberProfile::class, $id);
            if ($profile instanceof MemberProfile) {
                $em->remove($profile);
            }

            $privacy = $em->find(SocialPrivacySettings::class, $id);
            if ($privacy instanceof SocialPrivacySettings) {
                $em->remove($privacy);
            }

            $storedUser = $em->find(User::class, $id);
            if ($storedUser instanceof User) {
                $em->remove($storedUser);
            }
        }
        $em->flush();
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
