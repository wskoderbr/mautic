<?php

namespace Mautic\LeadBundle\EventListener;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomButtonEvent;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\CoreBundle\Twig\Helper\ButtonHelper;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class ButtonSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private TranslatorInterface $translator,
        private RouterInterface $router,
        private CorePermissions $security
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CoreEvents::VIEW_INJECT_CUSTOM_BUTTONS => ['injectViewButtons', 0],
        ];
    }

    public function injectViewButtons(CustomButtonEvent $event): void
    {
        if (!str_contains($event->getRoute(), 'mautic_contact_index')) {
            return;
        }

        if (!$this->security->isAdmin() && !$this->security->isGranted('lead:export:enable', 'MATCH_ONE')) {
            return;
        }

        $exportRoute = $this->router->generate('mautic_contact_action', ['objectAction' => 'batchExport']);

        $event->addButton(
            [
                'attr'      => [
                    'data-toggle'           => 'confirmation',
                    'href'                  => $exportRoute.'?filetype=xlsx',
                    'data-precheck'         => 'batchActionPrecheck',
                    'data-message'          => $this->translator->trans(
                        'mautic.core.export.items',
                        ['%items%' => 'contacts']
                    ),
                    'data-confirm-text'     => $this->translator->trans('mautic.core.export.xlsx'),
                    'data-confirm-callback' => 'executeBatchAction',
                    'data-cancel-text'      => $this->translator->trans('mautic.core.form.cancel'),
                    'data-cancel-callback'  => 'dismissConfirmation',
                ],
                'btnText'   => $this->translator->trans('mautic.core.export.xlsx'),
                'iconClass' => 'ri-file-excel-line',
            ],
            ButtonHelper::LOCATION_BULK_ACTIONS
        );

        $event->addButton(
            [
                'attr'      => [
                    'data-toggle'           => 'confirmation',
                    'href'                  => $exportRoute.'?filetype=csv',
                    'data-precheck'         => 'batchActionPrecheck',
                    'data-message'          => $this->translator->trans(
                        'mautic.core.export.items',
                        ['%items%' => 'contacts']
                    ),
                    'data-confirm-text'     => $this->translator->trans('mautic.core.export.csv'),
                    'data-confirm-callback' => 'executeBatchAction',
                    'data-cancel-text'      => $this->translator->trans('mautic.core.form.cancel'),
                    'data-cancel-callback'  => 'dismissConfirmation',
                ],
                'btnText'   => $this->translator->trans('mautic.core.export.csv'),
                'iconClass' => 'ri-file-text-line',
            ],
            ButtonHelper::LOCATION_BULK_ACTIONS
        );

        $event->addButton(
            [
                'attr'      => [
                    'href'        => $exportRoute.'?filetype=xlsx',
                    'data-toggle' => null,
                ],
                'btnText'   => $this->translator->trans('mautic.core.export.xlsx'),
                'iconClass' => 'ri-file-excel-line',
            ],
            ButtonHelper::LOCATION_PAGE_ACTIONS
        );

        $event->addButton(
            [
                'attr'      => [
                    'href'        => $exportRoute.'?filetype=csv',
                    'data-toggle' => null,
                ],
                'btnText'   => $this->translator->trans('mautic.core.export.csv'),
                'iconClass' => 'ri-file-text-line',
            ],
            ButtonHelper::LOCATION_PAGE_ACTIONS
        );
    }
}
