<?php

declare(strict_types=1);

namespace App\Tests\Messenger;

use App\Messenger\FailedMessageRecovery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;

final class FailedMessageRecoveryTest extends TestCase
{
    public function testInvalidOrMissingMessageIsNotDispatchedOrAcknowledged(): void
    {
        $receiver = $this->createMock(ListableReceiverInterface::class);
        $bus = $this->createMock(MessageBusInterface::class);
        $receiver->expects(self::never())->method('find');
        $bus->expects(self::never())->method('dispatch');
        $receiver->expects(self::never())->method('ack');

        $recovery = new FailedMessageRecovery($receiver, $bus);
        self::assertFalse($recovery->retry('invalid'));
    }

    public function testMissingNumericMessageIsNotDispatchedOrAcknowledged(): void
    {
        $receiver = $this->createMock(ListableReceiverInterface::class);
        $bus = $this->createMock(MessageBusInterface::class);
        $receiver->expects(self::once())->method('find')->with('42')->willReturn(null);
        $bus->expects(self::never())->method('dispatch');
        $receiver->expects(self::never())->method('ack');

        self::assertFalse((new FailedMessageRecovery($receiver, $bus))->retry('42'));
    }

    public function testOutOfRangeNumericMessageIdIsNotLookedUpOrDispatchedOrAcknowledged(): void
    {
        $receiver = $this->createMock(ListableReceiverInterface::class);
        $bus = $this->createMock(MessageBusInterface::class);
        $receiver->expects(self::never())->method('find');
        $bus->expects(self::never())->method('dispatch');
        $receiver->expects(self::never())->method('ack');

        self::assertFalse((new FailedMessageRecovery($receiver, $bus))->retry((string) PHP_INT_MAX.'0'));
    }

    public function testRetryRemovesFailureAndTransportStampsBeforeDispatchAndAcknowledgesAfterwards(): void
    {
        $message = new \stdClass();
        $failed = (new Envelope($message))
            ->with(new ReceivedStamp('failed'))
            ->with(new SentStamp('failed'))
            ->with(new SentToFailureTransportStamp('async'))
            ->with(new ErrorDetailsStamp(\RuntimeException::class, 0, 'private provider error'))
            ->with(new RedeliveryStamp(3))
            ->with(new DelayStamp(1000))
            ->with(new TransportMessageIdStamp('42'));

        $receiver = $this->createMock(ListableReceiverInterface::class);
        $receiver->expects(self::once())->method('find')->with('42')->willReturn($failed);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->with(self::callback(static function (Envelope $retry) use ($message): bool {
            self::assertSame($message, $retry->getMessage());
            foreach ([ReceivedStamp::class, SentStamp::class, SentToFailureTransportStamp::class, ErrorDetailsStamp::class, RedeliveryStamp::class, DelayStamp::class, TransportMessageIdStamp::class] as $stamp) {
                self::assertSame([], $retry->all($stamp));
            }

            return true;
        }))->willReturnCallback(static fn (Envelope $envelope): Envelope => $envelope);

        $receiver->expects(self::once())->method('ack')->with($failed);

        self::assertTrue((new FailedMessageRecovery($receiver, $bus))->retry('42'));
    }

    public function testDispatchFailureLeavesOriginalFailureUnacknowledged(): void
    {
        $failed = new Envelope(new \stdClass(), [new TransportMessageIdStamp('42')]);
        $receiver = $this->createMock(ListableReceiverInterface::class);
        $receiver->method('find')->with('42')->willReturn($failed);
        $receiver->expects(self::never())->method('ack');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new \RuntimeException('dispatch failed'));

        $this->expectException(\RuntimeException::class);
        (new FailedMessageRecovery($receiver, $bus))->retry('42');
    }
}
