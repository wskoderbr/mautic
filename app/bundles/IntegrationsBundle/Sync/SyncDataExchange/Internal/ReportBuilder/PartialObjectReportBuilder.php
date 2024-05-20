<?php

declare(strict_types=1);

namespace Mautic\IntegrationsBundle\Sync\SyncDataExchange\Internal\ReportBuilder;

use Mautic\IntegrationsBundle\Entity\FieldChangeRepository;
use Mautic\IntegrationsBundle\Event\InternalObjectFindEvent;
use Mautic\IntegrationsBundle\IntegrationEvents;
use Mautic\IntegrationsBundle\Sync\DAO\Sync\Report\FieldDAO;
use Mautic\IntegrationsBundle\Sync\DAO\Sync\Report\ObjectDAO as ReportObjectDAO;
use Mautic\IntegrationsBundle\Sync\DAO\Sync\Report\ReportDAO;
use Mautic\IntegrationsBundle\Sync\DAO\Sync\Request\ObjectDAO as RequestObjectDAO;
use Mautic\IntegrationsBundle\Sync\DAO\Sync\Request\RequestDAO;
use Mautic\IntegrationsBundle\Sync\Exception\FieldNotFoundException;
use Mautic\IntegrationsBundle\Sync\Exception\ObjectNotFoundException;
use Mautic\IntegrationsBundle\Sync\Logger\DebugLogger;
use Mautic\IntegrationsBundle\Sync\SyncDataExchange\Helper\FieldHelper;
use Mautic\IntegrationsBundle\Sync\SyncDataExchange\Internal\ObjectProvider;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class PartialObjectReportBuilder
{
    /**
     * @var array
     */
    private $reportObjects = [];

    private array $lastProcessedTrackedId = [];

    private array $objectsWithMissingFields = [];

    private ?ReportDAO $syncReport = null;

    public function __construct(
        private FieldChangeRepository $fieldChangeRepository,
        private FieldHelper $fieldHelper,
        private FieldBuilder $fieldBuilder,
        private ObjectProvider $objectProvider,
        private EventDispatcherInterface $dispatcher
    ) {
    }

    public function buildReport(RequestDAO $requestDAO): ReportDAO
    {
        $this->syncReport = new ReportDAO($requestDAO->getSyncToIntegration());
        $requestedObjects = $requestDAO->getObjects();

        foreach ($requestedObjects as $objectDAO) {
            try {
                if (!isset($this->lastProcessedTrackedId[$objectDAO->getObject()])) {
                    $this->lastProcessedTrackedId[$objectDAO->getObject()] = 0;
                }

                $fieldsChanges = $this->fieldChangeRepository->findChangesBefore(
                    $requestDAO->getSyncToIntegration(),
                    $this->fieldHelper->getFieldObjectName($objectDAO->getObject()),
                    $objectDAO->getToDateTime(),
                    $this->lastProcessedTrackedId[$objectDAO->getObject()]
                );

                $this->reportObjects = [];
                foreach ($fieldsChanges as $fieldChange) {
                    $this->processFieldChange($fieldChange, $objectDAO);
                }

                try {
                    $incompleteObjects = $this->findObjectsWithMissingFields($objectDAO);
                    $this->completeObjectsWithMissingFields($incompleteObjects, $objectDAO);
                } catch (ObjectNotFoundException $exception) {
                    // Process the others
                    DebugLogger::log(
                        $requestDAO->getSyncToIntegration(),
                        $exception->getMessage(),
                        self::class.':'.__FUNCTION__
                    );
                }
            } catch (ObjectNotFoundException $exception) {
                DebugLogger::log(
                    $requestDAO->getSyncToIntegration(),
                    $exception->getMessage(),
                    self::class.':'.__FUNCTION__
                );
            }
        }

        return $this->syncReport;
    }

    /**
     * @throws ObjectNotFoundException
     */
    private function processFieldChange(array $fieldChange, RequestObjectDAO $objectDAO): void
    {
        $objectId = (int) $fieldChange['object_id'];

        // Track the last processed ID to prevent loops for objects that were set to be retried later
        if ($objectId > $this->lastProcessedTrackedId[$objectDAO->getObject()]) {
            $this->lastProcessedTrackedId[$objectDAO->getObject()] = $objectId;
        }

        $object           = $this->objectProvider->getObjectByEntityName($fieldChange['object_type'])->getName();
        $objectId         = (int) $fieldChange['object_id'];
        $modifiedDateTime = new \DateTime($fieldChange['modified_at'], new \DateTimeZone('UTC'));

        if (!array_key_exists($object, $this->reportObjects)) {
            $this->reportObjects[$object] = [];
        }

        if (!array_key_exists($objectId, $this->reportObjects[$object])) {
            /* @var ReportObjectDAO $reportObjectDAO */
            $this->reportObjects[$object][$objectId] = $reportObjectDAO = new ReportObjectDAO($object, $objectId);
            $this->syncReport->addObject($reportObjectDAO);
            $reportObjectDAO->setChangeDateTime($modifiedDateTime);
        }

        /** @var ReportObjectDAO $reportObjectDAO */
        $reportObjectDAO = $this->reportObjects[$object][$objectId];

        $reportObjectDAO->addField(
            $this->fieldHelper->getFieldChangeObject($fieldChange)
        );

        // Track the latest change as the object's change date/time
        if ($reportObjectDAO->getChangeDateTime() > $modifiedDateTime) {
            $reportObjectDAO->setChangeDateTime($modifiedDateTime);
        }
    }

    /**
     * @throws ObjectNotFoundException
     */
    private function findObjectsWithMissingFields(RequestObjectDAO $requestObjectDAO): array
    {
        $objectName                     = $requestObjectDAO->getObject();
        $fields                         = $requestObjectDAO->getFields();
        $syncObjects                    = $this->syncReport->getObjects($objectName);
        $this->objectsWithMissingFields = [];

        foreach ($syncObjects as $syncObject) {
            $missingFields = [];
            foreach ($fields as $field) {
                try {
                    $syncObject->getField($field);
                } catch (FieldNotFoundException) {
                    $missingFields[] = $field;
                }
            }

            if ($missingFields) {
                $this->objectsWithMissingFields[$syncObject->getObjectId()] = $missingFields;
            }
        }

        if (!$this->objectsWithMissingFields) {
            return [];
        }

        $event = new InternalObjectFindEvent($this->objectProvider->getObjectByName($objectName));
        $event->setIds(array_keys($this->objectsWithMissingFields));
        $this->dispatcher->dispatch($event, IntegrationEvents::INTEGRATION_FIND_INTERNAL_RECORDS);

        return $event->getFoundObjects();
    }

    private function completeObjectsWithMissingFields(array $incompleteObjects, RequestObjectDAO $requestObjectDAO): void
    {
        foreach ($incompleteObjects as $incompleteObject) {
            $missingFields   = $this->objectsWithMissingFields[$incompleteObject['id']];
            $reportObjectDAO = $this->syncReport->getObject($requestObjectDAO->getObject(), $incompleteObject['id']);

            foreach ($missingFields as $field) {
                try {
                    $reportFieldDAO = $this->fieldBuilder->buildObjectField(
                        $field,
                        $incompleteObject,
                        $requestObjectDAO,
                        $this->syncReport->getIntegration(),
                        FieldDAO::FIELD_UNCHANGED
                    );
                    $reportObjectDAO->addField($reportFieldDAO);
                } catch (FieldNotFoundException $exception) {
                    // Field is not supported so keep going
                    DebugLogger::log(
                        $this->syncReport->getIntegration(),
                        $exception->getMessage(),
                        self::class.':'.__FUNCTION__
                    );
                }
            }
        }
    }
}
