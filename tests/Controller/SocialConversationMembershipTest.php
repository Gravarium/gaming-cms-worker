<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Profile\MemberProfile;
use App\Entity\Social\SocialConversation;
use App\Entity\Social\SocialConversationParticipant;
use App\Entity\Social\SocialBlock;
use App\Entity\Social\SocialMessage;
use App\Entity\Social\SocialPrivacySettings;
use App\Entity\User;
use App\Social\SocialAccessPolicy;
use App\Social\SocialConversationMembershipService;
use App\Social\SocialMessagingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class SocialConversationMembershipTest extends WebTestCase
{
    public function testOwnerCanAddMemberAndNewMemberCannotReadEarlierMessages(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        $owner = $this->user($em, 'WCP-599 Owner');
        $existing = $this->user($em, 'WCP-599 Existing');
        $newMember = $this->user($em, 'WCP-599 New Member');
        $users = [$owner, $existing, $newMember];
        $this->profile($em, $existing);
        $this->profile($em, $newMember);
        $conversation = $this->group($em, $owner, [$existing]);
        $conversationId = $this->id($conversation);
        $newMemberId = $this->id($newMember);
        $oldMessage = new SocialMessage($conversation, $owner, 'private-before-new-member');
        $em->persist($oldMessage);
        $em->flush();
        $oldMessageId = $this->id($oldMessage);

        try {
            $client->loginUser($owner);
            $crawler = $client->request('GET', '/social/conversations/'.$conversationId.'/members');
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertStringContainsString($newMember->getDisplayName(), (string) $client->getResponse()->getContent());

            $form = $crawler->selectButton('Mitglied hinzufügen')->form();
            $form['social_conversation_member[recipientIds]'] = [(string) $newMemberId];
            $client->submit($form);
            self::assertResponseRedirects('/social/conversations/'.$conversationId.'/members');

            $em = $this->em($client);
            $storedConversation = $em->find(SocialConversation::class, $conversationId);
            $storedNewMember = $em->find(User::class, $newMemberId);
            self::assertInstanceOf(SocialConversation::class, $storedConversation);
            self::assertInstanceOf(User::class, $storedNewMember);
            $participant = $em->getRepository(SocialConversationParticipant::class)->findOneBy([
                'conversation' => $storedConversation,
                'user' => $storedNewMember,
            ]);
            self::assertInstanceOf(SocialConversationParticipant::class, $participant);
            self::assertTrue($participant->isActive());

            $storedExisting = $em->find(User::class, $this->id($existing));
            self::assertInstanceOf(User::class, $storedExisting);
            $messagingForWrite = $client->getContainer()->get(SocialMessagingService::class);
            $messagingForWrite->send($storedExisting, $storedConversation, 'visible-after-new-member');
            $em->flush();

            $storedOldMessage = $em->find(SocialMessage::class, $oldMessageId);
            self::assertInstanceOf(SocialMessage::class, $storedOldMessage);
            $access = $client->getContainer()->get(SocialAccessPolicy::class);
            self::assertFalse($access->canAccessMessage($storedNewMember, $storedOldMessage));

            $messaging = $client->getContainer()->get(SocialMessagingService::class);
            $visibleBodies = array_map(
                static fn (SocialMessage $message): string => $message->getBody(),
                $messaging->read($storedNewMember, $storedConversation),
            );
            self::assertNotContains('private-before-new-member', $visibleBodies);
            self::assertContains('visible-after-new-member', $visibleBodies);
        } finally {
            $this->cleanup($client, $users);
        }
    }

    public function testRejoiningResetsReadMarkerAndStartsANewHistoryWindow(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        $owner = $this->user($em, 'WCP-599 Rejoin Owner');
        $member = $this->user($em, 'WCP-599 Rejoining Member');
        $other = $this->user($em, 'WCP-599 Rejoin Other');
        $users = [$owner, $member, $other];
        $this->profile($em, $member);
        $conversation = $this->group($em, $owner, [$other]);
        $conversationId = $this->id($conversation);
        $memberParticipant = new SocialConversationParticipant($conversation, $member);
        $em->persist($memberParticipant);
        $em->flush();
        $oldMessage = new SocialMessage($conversation, $other, 'private-before-rejoin');
        $em->persist($oldMessage);
        $em->flush();
        $oldMessageId = $this->id($oldMessage);
        $memberParticipant->markRead(new \DateTimeImmutable());
        $memberParticipant->leave(new \DateTimeImmutable());
        $em->flush();
        $memberId = $this->id($member);

        try {
            $client->loginUser($owner);
            $crawler = $client->request('GET', '/social/conversations/'.$conversationId.'/members');
            self::assertResponseIsSuccessful();
            $form = $crawler->selectButton('Mitglied hinzufügen')->form();
            $form['social_conversation_member[recipientIds]'] = [(string) $memberId];
            $client->submit($form);
            self::assertResponseRedirects('/social/conversations/'.$conversationId.'/members');

            $em = $this->em($client);
            $storedConversation = $em->find(SocialConversation::class, $conversationId);
            $storedMember = $em->find(User::class, $memberId);
            $storedOldMessage = $em->find(SocialMessage::class, $oldMessageId);
            self::assertInstanceOf(SocialConversation::class, $storedConversation);
            self::assertInstanceOf(User::class, $storedMember);
            self::assertInstanceOf(SocialMessage::class, $storedOldMessage);
            $participant = $em->getRepository(SocialConversationParticipant::class)->findOneBy([
                'conversation' => $storedConversation,
                'user' => $storedMember,
            ]);
            self::assertInstanceOf(SocialConversationParticipant::class, $participant);
            self::assertTrue($participant->isActive());
            self::assertNull($participant->getLastReadAt());
            self::assertGreaterThanOrEqual($storedOldMessage->getCreatedAt(), $participant->getJoinedAt());

            $access = $client->getContainer()->get(SocialAccessPolicy::class);
            self::assertFalse($access->canAccessMessage($storedMember, $storedOldMessage));

            $storedOther = $em->find(User::class, $this->id($other));
            self::assertInstanceOf(User::class, $storedOther);
            $messagingForWrite = $client->getContainer()->get(SocialMessagingService::class);
            $messagingForWrite->send($storedOther, $storedConversation, 'visible-after-rejoin');
            $em->flush();

            $messaging = $client->getContainer()->get(SocialMessagingService::class);
            $visibleBodies = array_map(
                static fn (SocialMessage $message): string => $message->getBody(),
                $messaging->read($storedMember, $storedConversation),
            );
            self::assertNotContains('private-before-rejoin', $visibleBodies);
            self::assertContains('visible-after-rejoin', $visibleBodies);
        } finally {
            $this->cleanup($client, $users);
        }
    }

    public function testInactiveLockedAndPolicyConflictingRecipientsCannotBeAdded(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        $owner = $this->user($em, 'WCP-599 Eligibility Owner');
        $member = $this->user($em, 'WCP-599 Eligibility Member');
        $inactive = $this->user($em, 'WCP-599 Inactive Candidate');
        $locked = $this->user($em, 'WCP-599 Locked Candidate');
        $blocked = $this->user($em, 'WCP-599 Blocked Candidate');
        $users = [$owner, $member, $inactive, $locked, $blocked];
        foreach ([$member, $inactive, $locked, $blocked] as $candidate) {
            $this->profile($em, $candidate);
        }
        $inactive->setActive(false);
        $locked->lockUntil(new \DateTimeImmutable('+1 day'), 'WCP-599 test');
        $em->persist(new SocialBlock($member, $blocked));
        $conversation = $this->group($em, $owner, [$member]);
        $conversationId = $this->id($conversation);
        $candidateIds = [
            $this->id($inactive),
            $this->id($locked),
            $this->id($blocked),
        ];

        try {
            $client->loginUser($owner);
            $crawler = $client->request('GET', '/social/conversations/'.$conversationId.'/members');
            self::assertResponseIsSuccessful();
            $body = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsString($inactive->getDisplayName(), $body);
            self::assertStringNotContainsString($locked->getDisplayName(), $body);
            self::assertStringContainsString($blocked->getDisplayName(), $body);

            foreach ($candidateIds as $candidateId) {
                $crawler = $client->request('GET', '/social/conversations/'.$conversationId.'/members');
                $token = (string) $crawler->filter('input[name="social_conversation_member[_token]"]')->attr('value');
                $client->request('POST', '/social/conversations/'.$conversationId.'/members', [
                    'social_conversation_member' => [
                        '_token' => $token,
                        'recipientIds' => [(string) $candidateId],
                    ],
                ]);
                self::assertResponseStatusCodeSame(422);
            }

            $em = $this->em($client);
            $storedConversation = $em->find(SocialConversation::class, $conversationId);
            self::assertInstanceOf(SocialConversation::class, $storedConversation);
            foreach ($candidateIds as $candidateId) {
                $candidate = $em->find(User::class, $candidateId);
                self::assertInstanceOf(User::class, $candidate);
                self::assertNull($em->getRepository(SocialConversationParticipant::class)->findOneBy([
                    'conversation' => $storedConversation,
                    'user' => $candidate,
                ]));
            }
        } finally {
            $this->cleanup($client, $users);
        }
    }

    public function testDuplicateRecipientIdsAreRejected(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        $owner = $this->user($em, 'WCP-599 Duplicate Owner');
        $member = $this->user($em, 'WCP-599 Duplicate Existing');
        $candidate = $this->user($em, 'WCP-599 Duplicate Candidate');
        $users = [$owner, $member, $candidate];
        $this->profile($em, $candidate);
        $conversation = $this->group($em, $owner, [$member]);
        $conversationId = $this->id($conversation);
        $candidateId = $this->id($candidate);

        try {
            $client->loginUser($owner);
            $crawler = $client->request('GET', '/social/conversations/'.$conversationId.'/members');
            self::assertResponseIsSuccessful();
            $token = (string) $crawler->filter('input[name="social_conversation_member[_token]"]')->attr('value');
            $client->request('POST', '/social/conversations/'.$conversationId.'/members', [
                'social_conversation_member' => [
                    '_token' => $token,
                    'recipientIds' => [(string) $candidateId, (string) $candidateId],
                ],
            ]);
            self::assertResponseStatusCodeSame(422);

            $em = $this->em($client);
            $storedConversation = $em->find(SocialConversation::class, $conversationId);
            $storedCandidate = $em->find(User::class, $candidateId);
            self::assertInstanceOf(SocialConversation::class, $storedConversation);
            self::assertInstanceOf(User::class, $storedCandidate);
            self::assertNull($em->getRepository(SocialConversationParticipant::class)->findOneBy([
                'conversation' => $storedConversation,
                'user' => $storedCandidate,
            ]));
        } finally {
            $this->cleanup($client, $users);
        }
    }

    public function testParticipantFromAnotherConversationCannotBeRemoved(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        $owner = $this->user($em, 'WCP-599 Foreign Owner');
        $member = $this->user($em, 'WCP-599 Foreign Member');
        $foreignOwner = $this->user($em, 'WCP-599 Foreign Group Owner');
        $foreignMember = $this->user($em, 'WCP-599 Foreign Group Member');
        $users = [$owner, $member, $foreignOwner, $foreignMember];
        $conversation = $this->group($em, $owner, [$member]);
        $foreignConversation = $this->group($em, $foreignOwner, [$foreignMember]);
        $foreignParticipantId = $this->participantId($em, $this->id($foreignConversation), $this->id($foreignMember));

        try {
            $membership = $client->getContainer()->get(SocialConversationMembershipService::class);
            try {
                $membership->removeMember($owner, $conversation, $foreignParticipantId);
                self::fail('A participant from another conversation must not be removable.');
            } catch (NotFoundHttpException) {
                self::assertTrue(true);
            }

            $em = $this->em($client);
            $storedForeignConversation = $em->find(SocialConversation::class, $this->id($foreignConversation));
            $storedForeignMember = $em->find(User::class, $this->id($foreignMember));
            self::assertInstanceOf(SocialConversation::class, $storedForeignConversation);
            self::assertInstanceOf(User::class, $storedForeignMember);
            $foreignParticipant = $em->getRepository(SocialConversationParticipant::class)->findOneBy([
                'conversation' => $storedForeignConversation,
                'user' => $storedForeignMember,
            ]);
            self::assertInstanceOf(SocialConversationParticipant::class, $foreignParticipant);
            self::assertTrue($foreignParticipant->isActive());
        } finally {
            $this->cleanup($client, $users);
        }
    }

    public function testOwnerCanRemoveNonOwnerAndRemovedMemberCannotReadTheConversation(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        $owner = $this->user($em, 'WCP-599 Remove Owner');
        $member = $this->user($em, 'WCP-599 Remove Member');
        $users = [$owner, $member];
        $conversation = $this->group($em, $owner, [$member]);
        $conversationId = $this->id($conversation);
        $memberId = $this->id($member);

        try {
            $client->loginUser($owner);
            $crawler = $client->request('GET', '/social/conversations/'.$conversationId.'/members');
            self::assertResponseIsSuccessful();
            $em = $this->em($client);
            $storedConversation = $em->find(SocialConversation::class, $conversationId);
            $storedMember = $em->find(User::class, $memberId);
            self::assertInstanceOf(SocialConversation::class, $storedConversation);
            self::assertInstanceOf(User::class, $storedMember);
            $participant = $em->getRepository(SocialConversationParticipant::class)->findOneBy([
                'conversation' => $storedConversation,
                'user' => $storedMember,
            ]);
            self::assertInstanceOf(SocialConversationParticipant::class, $participant);

            $action = '/social/conversations/'.$conversationId.'/members/'.$this->id($participant).'/remove';
            $form = $crawler->filter('form[action="'.$action.'"]')->form();
            $client->submit($form);
            self::assertResponseRedirects('/social/conversations/'.$conversationId.'/members');

            $em = $this->em($client);
            $storedConversation = $em->find(SocialConversation::class, $conversationId);
            $storedMember = $em->find(User::class, $memberId);
            self::assertInstanceOf(SocialConversation::class, $storedConversation);
            self::assertInstanceOf(User::class, $storedMember);
            $removed = $em->getRepository(SocialConversationParticipant::class)->findOneBy([
                'conversation' => $storedConversation,
                'user' => $storedMember,
            ]);
            self::assertInstanceOf(SocialConversationParticipant::class, $removed);
            self::assertSame(SocialConversationParticipant::STATUS_REMOVED, $removed->getStatus());

            $access = $client->getContainer()->get(SocialAccessPolicy::class);
            self::assertFalse($access->canReadConversation($storedMember, $storedConversation));
        } finally {
            $this->cleanup($client, $users);
        }
    }

    public function testOwnerDepartureTransfersOwnershipToEarliestRemainingMember(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        $owner = $this->user($em, 'WCP-599 Leaving Owner');
        $first = $this->user($em, 'WCP-599 First Member');
        $second = $this->user($em, 'WCP-599 Second Member');
        $users = [$owner, $first, $second];
        $conversation = $this->group($em, $owner, [$first, $second]);
        $conversationId = $this->id($conversation);

        try {
            $em = $this->em($client);
            $participants = $em->getRepository(SocialConversationParticipant::class)->activeParticipants($conversation);
            $expectedSuccessor = null;
            foreach ($participants as $participant) {
                if (!$participant->isOwner()) {
                    $expectedSuccessor = $participant->getUser();
                    break;
                }
            }
            self::assertInstanceOf(User::class, $expectedSuccessor);
            $successorId = $this->id($expectedSuccessor);

            $client->loginUser($owner);
            $crawler = $client->request('GET', '/social/conversations/'.$conversationId);
            self::assertResponseIsSuccessful();
            $form = $crawler->filter('form[action="/social/conversations/'.$conversationId.'/leave"]')->form();
            $client->submit($form);
            self::assertResponseRedirects('/social');

            $em = $this->em($client);
            $storedConversation = $em->find(SocialConversation::class, $conversationId);
            $storedSuccessor = $em->find(User::class, $successorId);
            self::assertInstanceOf(SocialConversation::class, $storedConversation);
            self::assertInstanceOf(User::class, $storedSuccessor);
            $ownerParticipant = $em->getRepository(SocialConversationParticipant::class)->findOneBy([
                'conversation' => $storedConversation,
                'user' => $em->find(User::class, $this->id($owner)),
            ]);
            $successorParticipant = $em->getRepository(SocialConversationParticipant::class)->findOneBy([
                'conversation' => $storedConversation,
                'user' => $storedSuccessor,
            ]);
            self::assertInstanceOf(SocialConversationParticipant::class, $ownerParticipant);
            self::assertInstanceOf(SocialConversationParticipant::class, $successorParticipant);
            self::assertSame(SocialConversationParticipant::STATUS_LEFT, $ownerParticipant->getStatus());
            self::assertTrue($successorParticipant->isOwner());

            $messaging = $client->getContainer()->get(SocialMessagingService::class);
            $messaging->leave($storedSuccessor, $storedConversation);

            $em = $this->em($client);
            $storedConversation = $em->find(SocialConversation::class, $conversationId);
            self::assertInstanceOf(SocialConversation::class, $storedConversation);
            $remaining = $em->getRepository(SocialConversationParticipant::class)->activeParticipants($storedConversation);
            self::assertCount(1, $remaining);
            self::assertTrue($remaining[0]->isOwner());
        } finally {
            $this->cleanup($client, $users);
        }
    }

    public function testDirectConversationCanStillBeLeftByOneParticipant(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        $owner = $this->user($em, 'WCP-599 Direct Owner');
        $recipient = $this->user($em, 'WCP-599 Direct Recipient');
        $users = [$owner, $recipient];
        $conversation = new SocialConversation($owner, SocialConversation::TYPE_DIRECT);
        $em->persist($conversation);
        $em->persist(new SocialConversationParticipant($conversation, $owner, SocialConversationParticipant::ROLE_OWNER));
        $em->persist(new SocialConversationParticipant($conversation, $recipient));
        $em->flush();
        $conversationId = $this->id($conversation);

        try {
            $client->loginUser($owner);
            $crawler = $client->request('GET', '/social/conversations/'.$conversationId);
            $form = $crawler->filter('form[action="/social/conversations/'.$conversationId.'/leave"]')->form();
            $client->submit($form);
            self::assertResponseRedirects('/social');

            $em = $this->em($client);
            $storedConversation = $em->find(SocialConversation::class, $conversationId);
            self::assertInstanceOf(SocialConversation::class, $storedConversation);
            $messaging = $client->getContainer()->get(SocialMessagingService::class);
            self::assertSame([], $messaging->read($recipient, $storedConversation));
        } finally {
            $this->cleanup($client, $users);
        }
    }

    public function testHiddenOrNonAcceptingRecipientsCannotBeAdded(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        $owner = $this->user($em, 'WCP-599 Policy Owner');
        $member = $this->user($em, 'WCP-599 Policy Member');
        $hidden = $this->user($em, 'WCP-599 Hidden Recipient');
        $nonAccepting = $this->user($em, 'WCP-599 Nonaccepting Recipient');
        $users = [$owner, $member, $hidden, $nonAccepting];
        $this->profile($em, $hidden, MemberProfile::VISIBILITY_PRIVATE);
        $this->profile($em, $nonAccepting);
        $em->persist((new SocialPrivacySettings($nonAccepting))->setMessagePolicy(SocialPrivacySettings::MESSAGE_NOBODY));
        $conversation = $this->group($em, $owner, [$member]);
        $conversationId = $this->id($conversation);
        $hiddenId = $this->id($hidden);
        $nonAcceptingId = $this->id($nonAccepting);

        try {
            $client->loginUser($owner);
            $crawler = $client->request('GET', '/social/conversations/'.$conversationId.'/members');
            self::assertResponseIsSuccessful();
            $body = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsString($hidden->getDisplayName(), $body);
            self::assertStringContainsString($nonAccepting->getDisplayName(), $body);

            $token = (string) $crawler->filter('input[name="social_conversation_member[_token]"]')->attr('value');
            $client->request('POST', '/social/conversations/'.$conversationId.'/members', [
                'social_conversation_member' => [
                    '_token' => $token,
                    'recipientIds' => [(string) $hiddenId],
                ],
            ]);
            self::assertResponseStatusCodeSame(422);

            $crawler = $client->request('GET', '/social/conversations/'.$conversationId.'/members');
            $token = (string) $crawler->filter('input[name="social_conversation_member[_token]"]')->attr('value');
            $client->request('POST', '/social/conversations/'.$conversationId.'/members', [
                'social_conversation_member' => [
                    '_token' => $token,
                    'recipientIds' => [(string) $nonAcceptingId],
                ],
            ]);
            self::assertResponseStatusCodeSame(422);

            $em = $this->em($client);
            $storedConversation = $em->find(SocialConversation::class, $conversationId);
            self::assertInstanceOf(SocialConversation::class, $storedConversation);
            foreach ([$hiddenId, $nonAcceptingId] as $recipientId) {
                $recipient = $em->find(User::class, $recipientId);
                self::assertInstanceOf(User::class, $recipient);
                self::assertNull($em->getRepository(SocialConversationParticipant::class)->findOneBy([
                    'conversation' => $storedConversation,
                    'user' => $recipient,
                ]));
            }
        } finally {
            $this->cleanup($client, $users);
        }
    }

    public function testGroupCannotGrowBeyondTwentyActiveMembers(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        $owner = $this->user($em, 'WCP-599 Full Group Owner');
        $users = [$owner];
        $members = [];
        for ($index = 0; $index < 19; ++$index) {
            $members[] = $this->user($em, sprintf('WCP-599 Full Group Member %02d', $index));
        }
        array_push($users, ...$members);
        $candidate = $this->user($em, 'WCP-599 Full Group Candidate');
        $users[] = $candidate;
        $this->profile($em, $candidate);
        $conversation = $this->group($em, $owner, $members);
        $conversationId = $this->id($conversation);
        $candidateId = $this->id($candidate);

        try {
            $client->loginUser($owner);
            $crawler = $client->request('GET', '/social/conversations/'.$conversationId.'/members');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'Die Gruppe hat bereits 20 aktive Mitglieder.');

            $token = (string) $crawler->filter('input[name="social_conversation_member[_token]"]')->attr('value');
            $client->request('POST', '/social/conversations/'.$conversationId.'/members', [
                'social_conversation_member' => [
                    '_token' => $token,
                    'recipientIds' => [(string) $candidateId],
                ],
            ]);
            self::assertResponseStatusCodeSame(422);

            $em = $this->em($client);
            $storedConversation = $em->find(SocialConversation::class, $conversationId);
            $storedCandidate = $em->find(User::class, $candidateId);
            self::assertInstanceOf(SocialConversation::class, $storedConversation);
            self::assertInstanceOf(User::class, $storedCandidate);
            self::assertNull($em->getRepository(SocialConversationParticipant::class)->findOneBy([
                'conversation' => $storedConversation,
                'user' => $storedCandidate,
            ]));
        } finally {
            $this->cleanup($client, $users);
        }
    }

    public function testAddRequiresCsrfOwnerAndEnabledSocialModule(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        $owner = $this->user($em, 'WCP-599 Guard Owner');
        $member = $this->user($em, 'WCP-599 Guard Member');
        $target = $this->user($em, 'WCP-599 Guard Target');
        $users = [$owner, $member, $target];
        $this->profile($em, $member);
        $this->profile($em, $target);
        $conversation = $this->group($em, $owner, [$member, $target]);
        $conversationId = $this->id($conversation);
        $targetId = $this->id($target);
        $state = $em->find(CmsModuleState::class, 'social');
        $createdState = !$state instanceof CmsModuleState;
        $originalEnabled = $state?->isEnabled();

        try {
            $client->loginUser($owner);
            $crawler = $client->request('GET', '/social/conversations/'.$conversationId.'/members');
            self::assertResponseIsSuccessful();
            $client->request('POST', '/social/conversations/'.$conversationId.'/members', [
                'social_conversation_member' => [
                    '_token' => 'invalid-token',
                    'recipientIds' => [(string) $targetId],
                ],
            ]);
            self::assertResponseStatusCodeSame(422);

            $em = $this->em($client);
            $storedConversation = $em->find(SocialConversation::class, $conversationId);
            $storedMember = $em->find(User::class, $this->id($member));
            self::assertInstanceOf(SocialConversation::class, $storedConversation);
            self::assertInstanceOf(User::class, $storedMember);
            $targetParticipantId = $this->participantId($em, $conversationId, $targetId);
            $membership = $client->getContainer()->get(SocialConversationMembershipService::class);
            try {
                $membership->removeMember($storedMember, $storedConversation, $targetParticipantId);
                self::fail('A non-owner member cannot remove participants.');
            } catch (AccessDeniedException) {
                self::assertTrue(true);
            }

            $em = $this->em($client);
            $state = $em->find(CmsModuleState::class, 'social');
            if (!$state instanceof CmsModuleState) {
                $state = (new CmsModuleState())->setModuleKey('social');
                $em->persist($state);
            }
            $state->setEnabled(false);
            $em->flush();

            $client->request('GET', '/social/conversations/'.$conversationId.'/members');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $em = $this->em($client);
            $state = $em->find(CmsModuleState::class, 'social');
            if ($state instanceof CmsModuleState) {
                if ($createdState) {
                    $em->remove($state);
                } else {
                    $state->setEnabled((bool) $originalEnabled);
                }
                $em->flush();
            }
            $this->cleanup($client, $users);
        }
    }

    /** @param list<User> $members */
    private function group(EntityManagerInterface $em, User $owner, array $members): SocialConversation
    {
        $conversation = new SocialConversation($owner, SocialConversation::TYPE_GROUP, 'WCP-599 test group');
        $em->persist($conversation);
        $em->persist(new SocialConversationParticipant($conversation, $owner, SocialConversationParticipant::ROLE_OWNER));
        foreach ($members as $member) {
            $em->persist(new SocialConversationParticipant($conversation, $member));
        }
        $em->flush();

        return $conversation;
    }

    private function participantId(EntityManagerInterface $em, int $conversationId, int $userId): int
    {
        $conversation = $em->find(SocialConversation::class, $conversationId);
        $user = $em->find(User::class, $userId);
        self::assertInstanceOf(SocialConversation::class, $conversation);
        self::assertInstanceOf(User::class, $user);
        $participant = $em->getRepository(SocialConversationParticipant::class)->findOneBy([
            'conversation' => $conversation,
            'user' => $user,
        ]);
        self::assertInstanceOf(SocialConversationParticipant::class, $participant);

        return $this->id($participant);
    }

    private function user(EntityManagerInterface $em, string $displayName): User
    {
        $user = (new User())
            ->setEmail('social-membership-'.bin2hex(random_bytes(6)).'@example.test')
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

    private function id(SocialConversation|SocialConversationParticipant|SocialMessage|User $object): int
    {
        $id = $object->getId();
        self::assertIsInt($id);

        return $id;
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
