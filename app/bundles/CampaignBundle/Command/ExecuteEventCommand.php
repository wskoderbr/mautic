<?php

namespace Mautic\CampaignBundle\Command;

use Mautic\CampaignBundle\Executioner\ScheduledExecutioner;
use Mautic\CoreBundle\Twig\Helper\FormatterHelper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class ExecuteEventCommand extends Command
{
    use WriteCountTrait;

    public function __construct(
        private ScheduledExecutioner $scheduledExecutioner,
        private TranslatorInterface $translator,
        private FormatterHelper $formatterHelper
    ) {
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('mautic:campaigns:execute')
            ->addOption(
                '--scheduled-log-ids',
                null,
                InputOption::VALUE_REQUIRED,
                'CSV of specific scheduled log IDs to execute.'
            )
            ->addOption(
                '--execution-time',
                null,
                InputOption::VALUE_REQUIRED,
                'Scheduled execution time of event log'
            );

        parent::configure();
    }

    /**
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        defined('MAUTIC_CAMPAIGN_SYSTEM_TRIGGERED') or define('MAUTIC_CAMPAIGN_SYSTEM_TRIGGERED', 1);

        $now     = empty($input->getOption('execution-time')) ? null : new \DateTime($input->getOption('execution-time'));
        $ids     = $this->formatterHelper->simpleCsvToArray($input->getOption('scheduled-log-ids'), 'int');
        $counter = $this->scheduledExecutioner->executeByIds($ids, $output, $now);

        $this->writeCounts($output, $this->translator, $counter);

        return Command::SUCCESS;
    }

    protected static $defaultDescription = 'Execute specific scheduled events.';
}
