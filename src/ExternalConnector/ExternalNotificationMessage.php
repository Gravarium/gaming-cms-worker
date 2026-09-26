<?php

declare(strict_types=1);

namespace App\ExternalConnector;

final readonly class ExternalNotificationMessage
{
    private const MAX_TYPE_LENGTH = 64;
    private const MAX_TITLE_LENGTH = 200;
    private const MAX_MESSAGE_LENGTH = 4000;
    private const MAX_LINK_LENGTH = 500;
    private const MAX_RECIPIENT_REFERENCE_LENGTH = 200;

    public string $type;
    public string $title;
    public string $message;
    public ?string $link;
    public ?string $recipientReference;

    public function __construct(
        string $type,
        string $title,
        string $message,
        ?string $link = null,
        ?string $recipientReference = null,
    ) {
        $this->type = $this->normalizeType($type);
        $this->title = $this->normalizeRequiredText($title, self::MAX_TITLE_LENGTH, 'title');
        $this->message = $this->normalizeRequiredText($message, self::MAX_MESSAGE_LENGTH, 'message', true);
        $this->link = $this->normalizeOptionalText($link, self::MAX_LINK_LENGTH, 'link');
        $this->recipientReference = $this->normalizeOptionalText($recipientReference, self::MAX_RECIPIENT_REFERENCE_LENGTH, 'recipient reference');
    }

    private function normalizeType(string $type): string
    {
        if (!$this->isSafeText($type, self::MAX_TYPE_LENGTH)) {
            throw new \InvalidArgumentException('External notification type is invalid.');
        }

        $type = strtolower(trim($type));
        if ($type === '' || preg_match('/^[a-z0-9][a-z0-9_.:-]*$/D', $type) !== 1) {
            throw new \InvalidArgumentException('External notification type is invalid.');
        }

        return $type;
    }

    private function normalizeRequiredText(string $value, int $maxLength, string $field, bool $allowLineBreaks = false): string
    {
        if (!$this->isSafeText($value, $maxLength, $allowLineBreaks)) {
            throw new \InvalidArgumentException('External notification '.$field.' is invalid.');
        }

        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException('External notification '.$field.' is invalid.');
        }

        return $value;
    }

    private function normalizeOptionalText(?string $value, int $maxLength, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!$this->isSafeText($value, $maxLength)) {
            throw new \InvalidArgumentException('External notification '.$field.' is invalid.');
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return $value;
    }

    private function isSafeText(string $value, int $maxLength, bool $allowLineBreaks = false): bool
    {
        if (!mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $maxLength) {
            return false;
        }

        $pattern = $allowLineBreaks
            ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u'
            : '/[\x00-\x1F\x7F]/u';

        return preg_match($pattern, $value) === 0;
    }
}
