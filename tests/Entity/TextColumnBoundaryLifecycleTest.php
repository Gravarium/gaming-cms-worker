<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AccessRole;
use App\Entity\Guild;
use App\Entity\GuildAnnouncement;
use App\Entity\GuildApplication;
use App\Entity\GuildApplicationQuestion;
use App\Entity\GuildEvent;
use App\Entity\GuildMember;
use App\Entity\GuildRank;
use App\Entity\GuildTeam;
use App\Entity\MediaFolder;
use App\Entity\ModuleStorageSetting;
use App\Entity\SiteSettings;
use Doctrine\ORM\Mapping\HasLifecycleCallbacks;
use Doctrine\ORM\Mapping\PrePersist;
use Doctrine\ORM\Mapping\PreUpdate;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class TextColumnBoundaryLifecycleTest extends TestCase
{
    public function testColumnBoundariesRunBeforeInsertAndUpdate(): void
    {
        $callbacks = [
            AccessRole::class => 'assertKeyColumnBoundary',
            GuildAnnouncement::class => 'assertTitleColumnBoundary',
            GuildApplicationQuestion::class => 'assertLabelColumnBoundary',
            GuildApplication::class => 'assertMessageColumnBoundary',
            GuildEvent::class => 'assertLocationColumnBoundary',
            GuildMember::class => 'assertCharacterNameColumnBoundary',
            GuildRank::class => 'assertNameColumnBoundary',
            GuildTeam::class => 'assertNameColumnBoundary',
            Guild::class => 'assertServerNameColumnBoundary',
            MediaFolder::class => 'assertNameColumnBoundary',
            ModuleStorageSetting::class => 'assertExternalBaseUrlColumnBoundary',
            SiteSettings::class => 'assertHomeTitleColumnBoundary',
        ];

        foreach ($callbacks as $entityClass => $methodName) {
            self::assertCount(1, (new ReflectionClass($entityClass))->getAttributes(HasLifecycleCallbacks::class), $entityClass);
            $method = new ReflectionMethod($entityClass, $methodName);
            self::assertCount(1, $method->getAttributes(PrePersist::class), $entityClass.'::'.$methodName);
            self::assertCount(1, $method->getAttributes(PreUpdate::class), $entityClass.'::'.$methodName);
        }
    }
}
