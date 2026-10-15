<?php

declare(strict_types=1);

namespace App\\Messenger;

use Doctrine\\DBAL\\Platforms\\AbstractPlatform;
use Doctrine\\DBAL\\Platforms\\SQLitePlatform;

final class FailedMessagePayloadSizeSql
{
    public static function expression(AbstractPlatform $platform): string
    {
        if ($platform instanceof SQLitePlatform) {
            return 'LENGTH(CAST(body AS BLOB))';
        }

        return 'OCTET_LENGTH(body)';
    }
}
