<?php

declare(strict_types=1);

namespace App\Messenger;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;

final readonly class FailedMessageRecovery
{
    public function __construct(
        #[Autowire(service: 'messenger.transport.failed')]
        private ListableReceiverInterface $failedReceiver,
        #[Autowire(service: 'messenger.default_bus')]
        private MessageBusInterface $bus,
    ) {
    }

    public function retry(string $id): bool
    {
        if (preg_match('/^[1-9][0-9]*$/', $id) !== 1) {
            return false;
        }

        $envelope = $this->failedReceiver->find($id);
        if ($envelope === null) {
            return false;
        }

        $retry = $envelope
            ->withoutAll(ReceivedStamp::class)
            ->withoutAll(SentStamp::class)
            ->withoutAll(SentToFailureTransportStamp::class)
            ->withoutAll(ErrorDetailsStamp::class)
            ->withoutAll(RedeliveryStamp::class)
            ->withoutAll(DelayStamp::class)
            ->withoutAll(TransportMessageIdStamp::class);

        $this->bus->dispatch($retry);
        $this->failedReceiver->ack($envelope);

        return true;
    }
}
