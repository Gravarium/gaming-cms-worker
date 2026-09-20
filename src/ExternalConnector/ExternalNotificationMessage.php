<?php
declare(strict_types=1);
namespace App\ExternalConnector;
final readonly class ExternalNotificationMessage
{
    public function __construct(
        public string $type,
        public string $title,
        public string $message,
        public ?string $link = null,
        public ?string $recipientReference = null,
    ) {
        if (trim($type) === '' || trim($title) === '' || trim($message) === '') { throw new \InvalidArgumentException('External notification requires type, title, and message.'); }
    }
}
