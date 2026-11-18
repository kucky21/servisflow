<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow;

use App\Exceptions\InvalidTransitionException;
use App\Model\Enum\OrderStatus;
use App\Model\Enum\UserRole;
use App\Model\Workflow\OrderStateMachine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderStateMachineTest extends TestCase
{
    private OrderStateMachine $machine;

    protected function setUp(): void
    {
        $this->machine = new OrderStateMachine();
    }

    /**
     * @return iterable<string, array{OrderStatus, OrderStatus, UserRole}>
     */
    public static function allowedTransitions(): iterable
    {
        yield 'technik zahájí diagnostiku' => [OrderStatus::Received, OrderStatus::Diagnostics, UserRole::Technician];
        yield 'technik odešle ke schválení' => [OrderStatus::Diagnostics, OrderStatus::AwaitingApproval, UserRole::Technician];
        yield 'vedoucí zaznamená schválení' => [OrderStatus::AwaitingApproval, OrderStatus::InRepair, UserRole::Manager];
        yield 'technik čeká na díly' => [OrderStatus::InRepair, OrderStatus::AwaitingParts, UserRole::Technician];
        yield 'díly dorazily' => [OrderStatus::AwaitingParts, OrderStatus::InRepair, UserRole::Technician];
        yield 'technik dokončí' => [OrderStatus::InRepair, OrderStatus::Completed, UserRole::Technician];
        yield 'vedoucí předá' => [OrderStatus::Completed, OrderStatus::HandedOver, UserRole::Manager];
        yield 'vedoucí vrátí do opravy' => [OrderStatus::Completed, OrderStatus::InRepair, UserRole::Manager];
        yield 'vedoucí zruší přijatou' => [OrderStatus::Received, OrderStatus::Cancelled, UserRole::Manager];
        yield 'admin může vše co vedoucí' => [OrderStatus::Completed, OrderStatus::HandedOver, UserRole::Admin];
    }

    #[DataProvider('allowedTransitions')]
    public function testAllowedTransition(OrderStatus $from, OrderStatus $to, UserRole $role): void
    {
        self::assertTrue($this->machine->canTransition($from, $to, $role));
        self::assertSame($to, $this->machine->assertAllowed($from, $to, $role)->to);
    }

    /**
     * @return iterable<string, array{OrderStatus, OrderStatus}>
     */
    public static function forbiddenTransitions(): iterable
    {
        yield 'přeskočení diagnostiky' => [OrderStatus::Received, OrderStatus::InRepair];
        yield 'přímo dokončit' => [OrderStatus::Received, OrderStatus::Completed];
        yield 'předat nedokončenou' => [OrderStatus::InRepair, OrderStatus::HandedOver];
        yield 'z předané zpět' => [OrderStatus::HandedOver, OrderStatus::InRepair];
        yield 'obnovit zrušenou' => [OrderStatus::Cancelled, OrderStatus::Received];
        yield 'zrušit dokončenou' => [OrderStatus::Completed, OrderStatus::Cancelled];
        yield 'zpět na přijato' => [OrderStatus::Diagnostics, OrderStatus::Received];
    }

    #[DataProvider('forbiddenTransitions')]
    public function testForbiddenTransitionForEveryRole(OrderStatus $from, OrderStatus $to): void
    {
        foreach (UserRole::cases() as $role) {
            self::assertFalse($this->machine->canTransition($from, $to, $role), $role->value);
        }

        $this->expectException(InvalidTransitionException::class);
        $this->machine->assertAllowed($from, $to, UserRole::Admin);
    }

    /**
     * @return iterable<string, array{OrderStatus, OrderStatus}>
     */
    public static function managerOnlyTransitions(): iterable
    {
        yield 'schválení zákazníkem' => [OrderStatus::AwaitingApproval, OrderStatus::InRepair];
        yield 'předání' => [OrderStatus::Completed, OrderStatus::HandedOver];
        yield 'zrušení' => [OrderStatus::InRepair, OrderStatus::Cancelled];
        yield 'reklamace' => [OrderStatus::Completed, OrderStatus::InRepair];
    }

    #[DataProvider('managerOnlyTransitions')]
    public function testTechnicianCannotPerformManagerTransitions(OrderStatus $from, OrderStatus $to): void
    {
        self::assertTrue($this->machine->canTransition($from, $to, UserRole::Manager));
        self::assertFalse($this->machine->canTransition($from, $to, UserRole::Technician));

        $this->expectException(InvalidTransitionException::class);
        $this->expectExceptionMessage('Vaše role nemůže');
        $this->machine->assertAllowed($from, $to, UserRole::Technician);
    }

    public function testFinalStatesHaveNoTransitions(): void
    {
        foreach ([OrderStatus::HandedOver, OrderStatus::Cancelled] as $status) {
            self::assertTrue($status->isFinal());
            foreach (UserRole::cases() as $role) {
                self::assertSame([], $this->machine->getAvailableTransitions($status, $role));
            }
        }
    }

    public function testCancellationAndReopeningRequireNote(): void
    {
        self::assertTrue($this->machine->find(OrderStatus::Received, OrderStatus::Cancelled)?->requiresNote);
        self::assertTrue($this->machine->find(OrderStatus::Completed, OrderStatus::InRepair)?->requiresNote);
        self::assertFalse($this->machine->find(OrderStatus::InRepair, OrderStatus::Completed)?->requiresNote);
    }

    public function testEveryActiveStatusCanBeCancelledByManager(): void
    {
        foreach (OrderStatus::activeCases() as $status) {
            self::assertTrue($this->machine->canTransition($status, OrderStatus::Cancelled, UserRole::Manager), $status->value);
        }
    }

    public function testEveryNonFinalStatusHasAWayForward(): void
    {
        foreach (OrderStatus::cases() as $status) {
            if ($status->isFinal()) {
                continue;
            }
            self::assertNotEmpty($this->machine->getAvailableTransitions($status, UserRole::Admin), $status->value);
        }
    }

    public function testDoneStatuses(): void
    {
        self::assertTrue(OrderStatus::Completed->isDone());
        self::assertTrue(OrderStatus::HandedOver->isDone());
        self::assertFalse(OrderStatus::Cancelled->isDone());
        self::assertFalse(OrderStatus::Completed->allowsWork());
        self::assertTrue(OrderStatus::AwaitingParts->allowsWork());
    }
}
