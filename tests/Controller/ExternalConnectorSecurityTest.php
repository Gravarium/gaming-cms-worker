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
