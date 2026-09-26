<?php

declare(strict_types=1);

namespace App\ExternalConnector;

final readonly class ExternalMailMessage
{
    private const MAX_RECIPIENTS = 100;
    private const MAX_RECIPIENT_LENGTH = 320;
    private const MAX_SUBJECT_LENGTH = 200;
    private const MAX_TEXT_LENGTH = 10_000;
    private const MAX_HTML_LENGTH = 20_000;

    /** @var list<string> */
    public array $recipients;
    public string $subject;
    public string $text;
    public ?string $html;

    /** @param list<string> $recipients */
    public function __construct(
        array $recipients,
        string $subject,
        string $text,
        ?string $html = null,
    ) {
        if ($recipients === [] || count($recipients) > self::MAX_RECIPIENTS) {
            throw new \InvalidArgumentException('External mail has too many or no recipients.');
        }

        /** @var list<string> $normalizedRecipients */
        $normalizedRecipients = [];
        foreach ($recipients as $recipient) {
            if (!is_string($recipient)
                || !$this->isSafeText($recipient, self::MAX_RECIPIENT_LENGTH)
            ) {
                throw new \InvalidArgumentException('External mail contains an invalid recipient.');
            }

            $recipient = trim($recipient);
            if ($recipient === '' || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
                throw new \InvalidArgumentException('External mail contains an invalid recipient.');
            }
            if (!in_array($recipient, $normalizedRecipients, true)) {
                $normalizedRecipients[] = $recipient;
            }
        }

        if ($normalizedRecipients === []) {
            throw new \InvalidArgumentException('External mail has no valid recipients.');
        }

        $this->recipients = $normalizedRecipients;
        $this->subject = $this->normalizeRequiredText($subject, self::MAX_SUBJECT_LENGTH, 'subject');
        $this->text = $this->normalizeRequiredText($text, self::MAX_TEXT_LENGTH, 'text', true);
        $this->html = $this->normalizeOptionalText($html, self::MAX_HTML_LENGTH, 'HTML body');
    }

    private function normalizeRequiredText(string $value, int $maxLength, string $field, bool $allowLineBreaks = false): string
    {
        if (!$this->isSafeText($value, $maxLength, $allowLineBreaks)) {
            throw new \InvalidArgumentException('External mail '.$field.' is invalid.');
        }

        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException('External mail '.$field.' is invalid.');
        }

        return $value;
    }

    private function normalizeOptionalText(?string $value, int $maxLength, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!$this->isSafeText($value, $maxLength, true)) {
            throw new \InvalidArgumentException('External mail '.$field.' is invalid.');
        }

        $value = trim($value);
        return $value === '' ? null : $value;
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
