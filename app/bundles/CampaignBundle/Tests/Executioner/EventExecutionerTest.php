<?php

namespace Mautic\CampaignBundle\Tests\Executioner;

use Doctrine\Common\Collections\ArrayCollection;
use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Entity\EventRepository;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\CampaignBundle\Entity\LeadRepository;
use Mautic\CampaignBundle\Event\PendingEvent;
use Mautic\CampaignBundle\EventCollector\Accessor\Event\ActionAccessor;
use Mautic\CampaignBundle\EventCollector\EventCollector;
use Mautic\CampaignBundle\EventListener\CampaignActionJumpToEventSubscriber;
use Mautic\CampaignBundle\Executioner\Event\ActionExecutioner;
use Mautic\CampaignBundle\Executioner\Event\ConditionExecutioner;
use Mautic\CampaignBundle\Executioner\Event\DecisionExecutioner;
use Mautic\CampaignBundle\Executioner\EventExecutioner;
use Mautic\CampaignBundle\Executioner\Logger\EventLogger;
use Mautic\CampaignBundle\Executioner\Result\EvaluatedContacts;
use Mautic\CampaignBundle\Executioner\Scheduler\EventScheduler;
use Mautic\CampaignBundle\Form\Type\CampaignEventJumpToEventType;
use Mautic\CampaignBundle\Helper\RemovedContactTracker;
use Mautic\CoreBundle\Translation\Translator;
use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Form\Type\EmailSendType;
use Mautic\LeadBundle\Entity\Lead;
use PHPUnit\Framework\MockObject\MockBuilder;
use Psr\Log\LoggerInterface;

class EventExecutionerTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var EventCollector|\PHPUnit\Framework\MockObject\MockObject
     */
    private \PHPUnit\Framework\MockObject\MockObject $eventCollector;

    /**
     * @var EventLogger|\PHPUnit\Framework\MockObject\MockObject
     */
    private \PHPUnit\Framework\MockObject\MockObject $eventLogger;

    /**
     * @var ActionExecutioner|\PHPUnit\Framework\MockObject\MockObject
     */
    private \PHPUnit\Framework\MockObject\MockObject $actionExecutioner;

    /**
     * @var ConditionExecutioner|\PHPUnit\Framework\MockObject\MockObject
     */
    private \PHPUnit\Framework\MockObject\MockObject $conditionExecutioner;

    /**
     * @var DecisionExecutioner|\PHPUnit\Framework\MockObject\MockObject
     */
    private \PHPUnit\Framework\MockObject\MockObject $decisionExecutioner;

    /**
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private \PHPUnit\Framework\MockObject\MockObject $logger;

    /**
     * @var EventScheduler|\PHPUnit\Framework\MockObject\MockObject
     */
    private \PHPUnit\Framework\MockObject\MockObject $eventScheduler;

    /**
     * @var RemovedContactTracker|\PHPUnit\Framework\MockObject\MockObject
     */
    private \PHPUnit\Framework\MockObject\MockObject $removedContactTracker;

    /**
     * @var LeadRepository|\PHPUnit\Framework\MockObject\MockObject
     */
    private \PHPUnit\Framework\MockObject\MockObject $leadRepository;

    /**
     * @var EventRepository|MockBuilder
     */
    private \PHPUnit\Framework\MockObject\MockObject $eventRepository;

    /**
     * @var Translator|MockBuilder
     */
    private \PHPUnit\Framework\MockObject\MockObject $translator;

    protected function setUp(): void
    {
        $this->eventCollector        = $this->createMock(EventCollector::class);
        $this->eventLogger           = $this->createMock(EventLogger::class);
        $this->eventLogger->method('persistCollection')
            ->willReturn($this->eventLogger);
        $this->actionExecutioner     = $this->createMock(ActionExecutioner::class);
        $this->conditionExecutioner  = $this->createMock(ConditionExecutioner::class);
        $this->decisionExecutioner   = $this->createMock(DecisionExecutioner::class);
        $this->logger                = $this->createMock(LoggerInterface::class);
        $this->eventScheduler        = $this->createMock(EventScheduler::class);
        $this->removedContactTracker = $this->createMock(RemovedContactTracker::class);
        $this->leadRepository        = $this->createMock(LeadRepository::class);
        $this->eventRepository       = $this->getMockBuilder(EventRepository::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->translator = $this->getMockBuilder(Translator::class)
            ->disableOriginalConstructor()
            ->getMock();
    }

    public function testJumpToEventsAreProcessedAfterOtherEvents(): void
    {
        $campaign = new Campaign();

        $otherEvent = new Event();
        $otherEvent->setEventType(ActionExecutioner::TYPE)
            ->setType('email.send')
            ->setCampaign($campaign);
        $otherConfig = new ActionAccessor(
            [
                'label'                => 'mautic.email.campaign.event.send',
                'description'          => 'mautic.email.campaign.event.send_descr',
                'batchEventName'       => EmailEvents::ON_CAMPAIGN_BATCH_ACTION,
                'formType'             => EmailSendType::class,
                'formTypeOptions'      => ['update_select' => 'campaignevent_properties_email', 'with_email_types' => true],
                'formTheme'            => 'MauticEmailBundle:FormTheme\EmailSendList',
                'channel'              => 'email',
                'channelIdField'       => 'email',
            ]
        );

        $jumpEvent = new Event();
        $jumpEvent->setEventType(ActionExecutioner::TYPE)
            ->setType(CampaignActionJumpToEventSubscriber::EVENT_NAME)
            ->setCampaign($campaign);
        $jumpConfig = new ActionAccessor(
            [
                'label'                  => 'mautic.campaign.event.jump_to_event',
                'description'            => 'mautic.campaign.event.jump_to_event_descr',
                'formType'               => CampaignEventJumpToEventType::class,
                'template'               => '@MauticCampaign/Event/jump.html.twig',
                'batchEventName'         => CampaignEvents::ON_EVENT_JUMP_TO_EVENT,
                'connectionRestrictions' => [
                    'target' => [
                        Event::TYPE_DECISION  => ['none'],
                        Event::TYPE_ACTION    => ['none'],
                        Event::TYPE_CONDITION => ['none'],
                    ],
                ],
            ]
        );

        $events   = new ArrayCollection([$otherEvent, $jumpEvent]);
        $contacts = new ArrayCollection([new Lead()]);

        $this->eventCollector->method('getEventConfig')
            ->willReturnCallback(
                function (Event $event) use ($jumpConfig, $otherConfig) {
                    if (CampaignActionJumpToEventSubscriber::EVENT_NAME === $event->getType()) {
                        return $jumpConfig;
                    }

                    return $otherConfig;
                }
            );

        $this->eventScheduler->expects($this->exactly(2))
            ->method('getExecutionDateTime')
            ->willReturn(new \DateTime());

        $this->eventLogger->expects($this->exactly(2))
            ->method('fetchRotationAndGenerateLogsFromContacts')
            ->willReturnCallback(
                function (Event $event, ActionAccessor $config, ArrayCollection $contacts, $isInactiveEntry) {
                    $logs = new ArrayCollection();
                    foreach ($contacts as $contact) {
                        $log = new LeadEventLog();
                        $log->setLead($contact);
                        $log->setEvent($event);
                        $log->setCampaign($event->getCampaign());
                        $logs->add($log);
                    }

                    return $logs;
                }
            );

        $this->actionExecutioner->expects($this->exactly(2))
            ->method('execute')
            ->withConsecutive(
                [
                    $otherConfig,
                    $this->isInstanceOf(ArrayCollection::class),
                ],
                [
                    $jumpConfig,
                    $this->isInstanceOf(ArrayCollection::class),
                ]
            )
            ->willReturn(new EvaluatedContacts());

        // This should not be called because the rotation is already incremented in the subscriber
        $this->leadRepository->expects($this->never())
            ->method('incrementCampaignRotationForContacts');

        $this->getEventExecutioner()->executeEventsForContacts($events, $contacts);
    }

    /**
     * @return EventExecutioner
     */
    private function getEventExecutioner()
    {
        return new EventExecutioner(
            $this->eventCollector,
            $this->eventLogger,
            $this->actionExecutioner,
            $this->conditionExecutioner,
            $this->decisionExecutioner,
            $this->logger,
            $this->eventScheduler,
            $this->removedContactTracker,
        );
    }

    public function testJumpToEventsExecutedWithoutTarget(): void
    {
        $campaign = new Campaign();

        $event = new Event();
        $event->setEventType(ActionExecutioner::TYPE)
            ->setType(CampaignActionJumpToEventSubscriber::EVENT_NAME)
            ->setCampaign($campaign)
            ->setProperties(['jumpToEvent' => 999]);

        $lead = $this->getMockBuilder(Lead::class)
            ->getMock();
        $lead->method('getId')
            ->willReturn(1);

        $log = $this->getMockBuilder(LeadEventLog::class)
            ->getMock();
        $log->method('getLead')
            ->willReturn($lead);
        $log->method('setIsScheduled')
            ->willReturn($log);
        $log->method('getEvent')
            ->willReturn($event);
        $log->method('getId')
            ->willReturn(1);

        $logs = new ArrayCollection(
            [
                1 => $log,
            ]
        );

        $config = new ActionAccessor(
            [
                'label'                  => 'mautic.campaign.event.jump_to_event',
                'description'            => 'mautic.campaign.event.jump_to_event_descr',
                'formType'               => CampaignEventJumpToEventType::class,
                'template'               => '@MauticCampaign/Event/jump.html.twig',
                'batchEventName'         => CampaignEvents::ON_EVENT_JUMP_TO_EVENT,
                'connectionRestrictions' => [
                    'target' => [
                        Event::TYPE_DECISION  => ['none'],
                        Event::TYPE_ACTION    => ['none'],
                        Event::TYPE_CONDITION => ['none'],
                    ],
                ],
            ]
        );

        $pendingEvent = new PendingEvent($config, $event, $logs);

        $this->eventRepository->method('getEntities')
            ->willReturn([]);

        $eventScheduler = $this->createMock(EventScheduler::class);

        $subscriber = new CampaignActionJumpToEventSubscriber(
            $this->eventRepository,
            $this->getEventExecutioner(),
            $this->translator,
            $this->leadRepository,
            $eventScheduler
        );
        $subscriber->onJumpToEvent($pendingEvent);

        $this->assertEquals(count($pendingEvent->getSuccessful()), 1);
        $this->assertEquals(count($pendingEvent->getFailures()), 0);
    }
}
