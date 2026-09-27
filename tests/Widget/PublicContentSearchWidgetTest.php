<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Entity\CmsModuleState;
use App\Widget\WidgetDefinition;
use App\Widget\WidgetRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class PublicContentSearchWidgetTest extends KernelTestCase
{
    public function testSearchWidgetUsesExistingRouteAndRespectsContentModule(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $connection = $em->getConnection();
        $connection->beginTransaction();

        try {
            $state = $em->getRepository(CmsModuleState::class)->find('content');
            if (!$state instanceof CmsModuleState) {
                $state = (new CmsModuleState())->setModuleKey('content');
                $em->persist($state);
            }
            $state->install('1.0.0')->setEnabled(true);
            $em->flush();

            $registry = self::getContainer()->get(WidgetRegistry::class);
            $definition = $registry->get('content.search');
            self::assertInstanceOf(WidgetDefinition::class, $definition);
            self::assertSame('content', $definition->module);
            self::assertFalse($definition->multiple);
            self::assertTrue($registry->available('content.search'));

            $html = self::getContainer()->get(Environment::class)->render($definition->template);
            self::assertStringContainsString('action="/search"', $html);
            self::assertStringContainsString('method="get"', $html);
            self::assertStringContainsString('role="search"', $html);
            self::assertStringContainsString('for="public-content-search-query"', $html);
            self::assertStringContainsString('type="search"', $html);
            self::assertStringContainsString('name="q"', $html);
            self::assertStringContainsString('maxlength="100"', $html);
            self::assertStringContainsString('type="submit"', $html);

            $state->setEnabled(false);
            $em->flush();
            self::assertFalse($registry->available('content.search'));
            self::assertSame([], $registry->data('content.search', []));
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $em->clear();
        }
    }
}
