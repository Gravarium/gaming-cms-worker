<?php

declare(strict_types=1);

namespace App\Tests\Controller\Hardware;

use App\Entity\CmsModuleState;
use App\Entity\Hardware\HardwareBenchmarkMeasurement;
use App\Entity\Hardware\HardwareBenchmarkMethodology;
use App\Entity\Hardware\HardwareProduct;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HardwareWorkflowTest extends WebTestCase
{
    public function testPublicCatalogueAndComparisonHideDraftsAndNonEditorialValues(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager($client);
        $suffix = bin2hex(random_bytes(5));
        $first = $this->product('Board A '.$suffix, true);
        $second = $this->product('Board B '.$suffix, true);
        $draft = $this->product('Private Board '.$suffix, false);
        $methodology = (new HardwareBenchmarkMethodology())->setName('Open bench '.$suffix)->setVersion('1.0')
            ->setProcedure('Run the fixed test suite.')->setTestSystem(['CPU' => 'Test CPU'])->setDisclosure('No manufacturer funding.');
        foreach ([$first, $second, $draft, $methodology] as $entity) { $entityManager->persist($entity); }
        $entityManager->flush();

        $entityManager->persist($this->measurement($first, $methodology, 120.5, 'editorial'));
        $entityManager->persist($this->measurement($second, $methodology, 130.0, 'editorial'));
        $entityManager->persist($this->measurement($first, $methodology, 999.0, 'advertising'));
        $entityManager->flush();

        $client->request('GET', '/gaming/hardware');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $first->getName());
        self::assertSelectorTextNotContains('body', $draft->getName());

        $client->request('GET', '/gaming/hardware/compare?'.http_build_query(['ids' => [$first->getId(), $second->getId()]]));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Open bench '.$suffix);
        self::assertSelectorTextContains('body', '120.5');
        self::assertSelectorTextContains('body', '130');
        self::assertSelectorTextNotContains('body', '999');

        $client->request('GET', '/gaming/hardware/compare?'.http_build_query(['ids' => [1, 2, 3, 4, 5]]));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Wähle zwei bis vier veröffentlichte Produkte aus.');
    }

    public function testManagerCanCreateTypedProductAndInvalidSpecificationIsRejected(): void
    {
        $client = static::createClient();
        $manager = $this->manager($client);
        $client->loginUser($manager);
        $suffix = bin2hex(random_bytes(5));
        $formName = $this->formName($client->request('GET', '/admin/gaming/hardware/products/new'));
        $token = $this->formToken($client);
        $client->request('POST', '/admin/gaming/hardware/products/new', [
            $formName => [
                'name' => 'Typed product '.$suffix,
                'category' => 'GPU',
                'manufacturer' => 'Example Labs',
                'disclosure' => 'No sponsorship received.',
                'published' => '1',
                'specificationsJson' => '{"memory":{"value":16,"unit":"GB"},"ray_tracing":{"value":true,"unit":""}}',
                'revisionSummary' => 'Initial catalogue entry',
                '_token' => $token,
            ],
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        $entityManager = $this->entityManager($client);
        $product = $entityManager->getRepository(HardwareProduct::class)->findOneBy(['name' => 'Typed product '.$suffix]);
        self::assertInstanceOf(HardwareProduct::class, $product);
        self::assertTrue($product->isPublished());
        self::assertCount(2, $product->getSpecifications());

        $invalidName = 'Invalid typed product '.$suffix;
        $formName = $this->formName($client->request('GET', '/admin/gaming/hardware/products/new'));
        $token = $this->formToken($client);
        $client->request('POST', '/admin/gaming/hardware/products/new', [
            $formName => [
                'name' => $invalidName,
                'category' => 'GPU',
                'manufacturer' => 'Example Labs',
                'disclosure' => 'No sponsorship received.',
                'published' => '1',
                'specificationsJson' => '{"memory":{"value":[],"unit":"GB"}}',
                'revisionSummary' => 'Invalid input attempt',
                '_token' => $token,
            ],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Specification values must be scalar.');
        self::assertNull($entityManager->getRepository(HardwareProduct::class)->findOneBy(['name' => $invalidName]));
    }

    public function testCommunitySetupStaysPrivateUntilApprovedAndCanBeHiddenAgain(): void
    {
        $ownerClient = static::createClient();
        $entityManager = $this->entityManager($ownerClient);
        $owner = $this->user('hardware-owner');
        $entityManager->persist($owner);
        $product = $this->product('Community GPU '.bin2hex(random_bytes(4)), true);
        $entityManager->persist($product);
        $entityManager->flush();
        $ownerClient->loginUser($owner);

        $crawler = $ownerClient->request('GET', '/account/gaming/hardware/setups');
        self::assertResponseIsSuccessful();
        $formName = $this->formName($crawler);
        $token = $this->formToken($ownerClient);
        $notes = 'A private setup submitted for moderation '.bin2hex(random_bytes(4));
        $ownerClient->request('POST', '/account/gaming/hardware/setups', [
            $formName => ['products' => [(string) $product->getId()], 'notes' => $notes, '_token' => $token],
        ]);
        self::assertResponseRedirects();
        $ownerClient->followRedirect();
        self::assertSelectorTextContains('body', 'Wird moderiert und ist privat');
        $ownerClient->request('GET', '/admin/gaming/hardware');
        self::assertResponseStatusCodeSame(403);

        $ownerClient->request('GET', '/gaming/hardware/products/'.$product->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('body', $notes);

        $moderator = $this->manager($ownerClient);
        $ownerClient->loginUser($moderator);
        $queue = $ownerClient->request('GET', '/admin/gaming/hardware/setups');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $notes);
        $setup = $entityManager->getRepository(\App\Entity\Hardware\HardwareCommunitySetup::class)->findOneBy(['owner' => $owner]);
        self::assertNotNull($setup);
        $moderationPath = '/admin/gaming/hardware/setups/'.$setup->getId().'/moderation';
        $token = (string) $queue->filter('form[action="'.$moderationPath.'"] input[name="_token"]')->attr('value');
        $ownerClient->request('POST', $moderationPath, ['_token' => 'invalid-token', 'action' => 'approve']);
        self::assertResponseStatusCodeSame(403);
        $queue = $ownerClient->request('GET', '/admin/gaming/hardware/setups');
        $token = (string) $queue->filter('form[action="'.$moderationPath.'"] input[name="_token"]')->attr('value');
        $ownerClient->request('POST', $moderationPath, ['_token' => $token, 'action' => 'approve']);
        self::assertResponseRedirects();
        $ownerClient->request('GET', '/gaming/hardware/products/'.$product->getId());
        self::assertSelectorTextContains('body', $notes);

        $queue = $ownerClient->request('GET', '/admin/gaming/hardware/setups');
        $token = (string) $queue->filter('form[action="'.$moderationPath.'"] input[name="_token"]')->attr('value');
        $ownerClient->request('POST', $moderationPath, ['_token' => $token, 'action' => 'hide']);
        self::assertResponseRedirects();
        $ownerClient->request('GET', '/gaming/hardware/products/'.$product->getId());
        self::assertSelectorTextNotContains('body', $notes);
    }

    public function testDisabledGamingModuleHidesHardwareRoutes(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager($client);
        $state = (new CmsModuleState())->setModuleKey('gaming')->updateVersion('1.0.0')->setEnabled(false);
        $entityManager->persist($state);
        $entityManager->flush();

        $client->request('GET', '/gaming/hardware');
        self::assertResponseStatusCodeSame(404);

        $state->setEnabled(true);
        $entityManager->flush();
    }

    private function product(string $name, bool $published): HardwareProduct
    {
        return (new HardwareProduct())->setName($name)->setCategory('GPU')->setManufacturer('Example Labs')
            ->setDisclosure('Testing was independent; no manufacturer funding.')->setPublished($published);
    }

    private function measurement(HardwareProduct $product, HardwareBenchmarkMethodology $methodology, float $value, string $sourceType): HardwareBenchmarkMeasurement
    {
        return (new HardwareBenchmarkMeasurement())->setProduct($product)->setMethodology($methodology)->setSeries('Raster FPS')
            ->setValue($value)->setUnit('FPS')->setSampleCount(5)->setSourceType($sourceType);
    }

    private function user(string $prefix): User
    {
        $suffix = bin2hex(random_bytes(6));
        return (new User())->setEmail($prefix.'-'.$suffix.'@example.test')->setDisplayName('Hardware test '.$suffix)->setPassword('unused-test-password');
    }

    private function manager(KernelBrowser $client): User
    {
        $user = $this->user('hardware-manager')->setPermissions(['CMS_GAMING_MANAGE']);
        $this->entityManager($client)->persist($user);
        $this->entityManager($client)->flush();
        return $user;
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }

    private function formName(\Symfony\Component\DomCrawler\Crawler $crawler): string
    {
        $name = $crawler->filter('form')->first()->attr('name');
        return is_string($name) && $name !== '' ? $name : 'form';
    }

    private function formToken(KernelBrowser $client): string
    {
        $crawler = $client->getCrawler();
        $name = (string) $crawler->filter('input[name$="[_token]"]')->first()->attr('name');
        self::assertMatchesRegularExpression('/^([a-zA-Z0-9_]+)\[_token\]$/', $name);
        return (string) $crawler->filter('input[name="'.$name.'"]')->attr('value');
    }
}
