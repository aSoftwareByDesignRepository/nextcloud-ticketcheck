<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Db\TicketSurvey;
use OCA\Ticketcheck\Db\TicketSurveyMapper;
use OCA\Ticketcheck\Service\SurveyService;
use OCA\Ticketcheck\Service\TicketWorkflowLock;
use OCP\DB\Exception as DbException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SurveyServiceTest extends TestCase
{
    /** @var TicketSurveyMapper&MockObject */
    private TicketSurveyMapper $mapper;
    /** @var TicketMapper&MockObject */
    private TicketMapper $ticketMapper;
    /** @var TicketWorkflowLock&MockObject */
    private TicketWorkflowLock $workflowLock;
    private SurveyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = $this->createMock(TicketSurveyMapper::class);
        $this->ticketMapper = $this->createMock(TicketMapper::class);
        $this->workflowLock = $this->createMock(TicketWorkflowLock::class);
        $this->workflowLock->method('withTicketLocks')->willReturnCallback(
            static function (array $ids, callable $callback) {
                return $callback();
            }
        );
        $this->service = new SurveyService($this->mapper, $this->ticketMapper, $this->workflowLock);
    }

    private function doneTicket(int $id): Ticket
    {
        $ticket = new Ticket();
        $ticket->setId($id);
        $ticket->setStatus(Ticket::STATUS_DONE);
        $ticket->setMergedIntoId(null);
        return $ticket;
    }

    public function testSubmitSurveyRejectsInvalidRating(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('satisfaction_survey_rating_invalid');

        $this->service->submitSurvey(1, 0);
    }

    public function testSubmitSurveyRejectsWhenTicketNotDone(): void
    {
        $open = new Ticket();
        $open->setId(5);
        $open->setStatus(Ticket::STATUS_NEW);
        $this->ticketMapper->method('find')->with(5)->willReturn($open);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('satisfaction_survey_only_when_done');

        $this->service->submitSurvey(5, 4);
    }

    public function testSubmitSurveyRejectsDuplicateSubmission(): void
    {
        $this->ticketMapper->method('find')->with(5)->willReturn($this->doneTicket(5));
        $this->mapper->method('findByTicket')->with(5)->willReturn(new TicketSurvey());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('satisfaction_survey_already_submitted');

        $this->service->submitSurvey(5, 4);
    }

    public function testSubmitSurveyMapsUniqueViolationToAlreadySubmitted(): void
    {
        $this->ticketMapper->method('find')->with(7)->willReturn($this->doneTicket(7));
        $this->mapper->method('findByTicket')->with(7)->willReturn(null);
        $dbException = $this->createMock(DbException::class);
        $dbException->method('getReason')->willReturn(DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION);
        $this->mapper->method('insert')->willThrowException($dbException);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('satisfaction_survey_already_submitted');

        $this->service->submitSurvey(7, 5);
    }

    public function testSubmitSurveyPersistsValidRating(): void
    {
        $this->ticketMapper->method('find')->with(3)->willReturn($this->doneTicket(3));
        $this->mapper->method('findByTicket')->with(3)->willReturn(null);
        $this->mapper->expects($this->once())->method('insert')->willReturnCallback(static function (TicketSurvey $survey): TicketSurvey {
            $survey->setId(99);
            return $survey;
        });

        $survey = $this->service->submitSurvey(3, 5, 'Great support');

        $this->assertSame(99, $survey->getId());
        $this->assertSame(5, $survey->getRating());
        $this->assertSame('Great support', $survey->getComment());
    }
}
