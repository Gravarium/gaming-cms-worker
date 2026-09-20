<?php

declare(strict_types=1);

namespace App\ExternalConnector;

final readonly class ExternalMailMessage
{
    /** @var list<string> */
    public array $recipients;

    /** @param list<string> $recipients */
    public function __construct(
        array $recipients,
        public string $subject,
        public string $text,
        public ?string $html = null,
    ) {
        if ($recipients === [] || trim($subject) === '' || trim($text) === '') {
            throw new \InvalidArgumentException('External mail requires recipients, subject, and text.');
        }

        foreach ($recipients as $recipient) {
            if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
                throw new \InvalidArgumentException('External mail contains an invalid recipient.');
            }
        }

        $this->recipients = array_values(array_unique($recipients));
    }
}
