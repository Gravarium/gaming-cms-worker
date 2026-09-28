<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ExternalConnectorTarget;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ExternalConnectorSecurityTest extends WebTestCase
{
    public function testToggleCannotEnableTargetWithoutConfigurationReference(): void
    {
        $client = static::createClient();
        $target = (new ExternalConnectorTarget())
            ->setCapability(ExternalConnectorTarget::CAPABILITY_MAIL)
            ->setTargetKey('unconfigured-mail')
            ->setProviderKey('test-mail')
            ->setDisplayName('Unconfigured mail')
            ->setEnabled(false)
            ->setConfigurationReference(null);
        $this->em($client)->persist($target);
        $this->em($client)->flush();
        $client->loginUser($this->user($client));
        $crawler = $client->request('GET', '/admin/connectors');
        self::assertResponseIsSuccessful();
        $token = (string) $crawler
            ->filter('form[action="/admin/connectors/'.$target->getId().'/toggle"] input[name="_token"]')
            ->attr('value');

        $client->request('POST', '/admin/connectors/'.$target->getId().'/toggle', ['_token' => $token]);

        self::assertResponseRedirects('/admin/connectors');
        $this->em($client)->clear();
        $stored = $this->em($client)->find(ExternalConnectorTarget::class, $target->getId());
        self::assertInstanceOf(ExternalConnectorTarget::class, $stored);
        self::assertFalse($stored->isEnabled());
    }

    public function testSettingsRejectsOversizedRawPriorityMapWithoutChangingTarget(): void
    {
        $client = static::createClient();
        $target = $this->target($client);
        $targetId = $target->getId();
        self::assertNotNull($targetId);
        $client->loginUser($this->user($client));

        $priorities = array_fill_keys(range(900000001, 900000200), '900');
        $priorities[(string) $targetId] = '900';
        $client->request('POST', '/admin/connectors/settings', [
            '_token' => $this->settingsToken($client),
            'priority' => $priorities,
            'required' => [(string) $targetId => '1'],
        ]);

        self::assertResponseRedirects('/admin/connectors');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Zu viele externe Ziele ausgewählt. Es wurden keine Änderungen vorgenommen.');

        $this->em($client)->clear();
        $stored = $this->em($client)->find(ExternalConnectorTarget::class, $targetId);
        self::assertInstanceOf(ExternalConnectorTarget::class, $stored);
        self::assertSame(10, $stored->getPriority());
        self::assertFalse($stored->isRequired());
    }

    public function testSettingsStillUpdatesAValidBoundedPriorityMap(): void
    {
        $client = static::createClient();
        $target = $this->target($client);
        $targetId = $target->getId();
        self::assertNotNull($targetId);
        $client->loginUser($this->user($client));

        $client->request('POST', '/admin/connectors/settings', [
            '_token' => $this->settingsToken($client),
            'priority' => [(string) $targetId => '25'],
            'required' => [(string) $targetId => '1'],
        ]);

        self::assertResponseRedirects('/admin/connectors');
        $client->followRedirect();

        $this->em($client)->clear();
        $stored = $this->em($client)->find(ExternalConnectorTarget::class, $targetId);
        self::assertInstanceOf(ExternalConnectorTarget::class, $stored);
        self::assertSame(25, $stored->getPriority());
        self::assertTrue($stored->isRequired());
    }

    private function target(KernelBrowser $client): ExternalConnectorTarget
    {
        $target = (new ExternalConnectorTarget())
            ->setCapability(ExternalConnectorTarget::CAPABILITY_MAIL)
            ->setTargetKey('wcp-343-'.bin2hex(random_bytes(6)))
            ->setProviderKey('test-mail')
            ->setDisplayName('WCP 343 test target')
            ->setPriority(10)
            ->setRequired(false)
            ->setEnabled(false);
        $this->em($client)->persist($target);
        $this->em($client)->flush();

        return $target;
    }

    private function settingsToken(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/admin/connectors');
        self::assertResponseIsSuccessful();

        return (string) $crawler
            ->filter('form[action="/admin/connectors/settings"] input[name="_token"]')
            ->attr('value');
    }

    private function user(KernelBrowser $client): User
    {
        $user = (new User())
            ->setEmail('connector-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Connector test')
            ->setPermissions([CmsPermission::CONNECTORS])
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
