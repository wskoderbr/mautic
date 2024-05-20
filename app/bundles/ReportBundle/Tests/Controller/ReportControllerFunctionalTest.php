<?php

namespace Mautic\ReportBundle\Tests\Controller;

use Doctrine\ORM\Exception\NotSupported;
use Doctrine\Persistence\Mapping\MappingException;
use Mautic\CoreBundle\Entity\IpAddress;
use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\PageBundle\Entity\Hit;
use Mautic\PageBundle\Entity\Page;
use Mautic\ReportBundle\Entity\Report;
use Mautic\ReportBundle\Scheduler\Enum\SchedulerEnum;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Request;

class ReportControllerFunctionalTest extends MauticMysqlTestCase
{
    public function testHitRepositoryMostVisited(): void
    {
        $page = $this->createPage('test page 1');
        $this->createHit($page);
        $this->createHit(null);

        $query = $this->em->getConnection()->createQueryBuilder();
        $query->from(MAUTIC_TABLE_PREFIX.'page_hits', 'ph');
        $query->leftJoin('ph', MAUTIC_TABLE_PREFIX.'pages', 'p', 'ph.page_id = p.id');

        $pageModel = self::$container->get('mautic.page.model.page');
        $res       = $pageModel->getHitRepository()->getMostVisited($query);   // $this->em->getRepository(Hit::class);

        foreach ($res as $hit) {
            Assert::assertNotNull($hit['id']);
            Assert::assertNotNull($hit['title']);
            Assert::assertNotNull($hit['hits']);
        }
    }

    public function testMostVisitedPagesReport(): void
    {
        $page = $this->createPage('test page 1');
        $this->createHit($page);
        $this->createHit(null);

        $report = $this->createReport('Report Most Visited Pages', 'page.hits', [
            'mautic.page.table.most.visited.unique',
            'mautic.page.table.most.visited',
        ]);

        // Check the details page
        $this->client->request('GET', '/s/reports/view/'.$report->getId());

        Assert::assertTrue($this->client->getResponse()->isOk());
    }

    public function testCreatingNewReportAndClone(): void
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/s/reports/new/');
        Assert::assertTrue($this->client->getResponse()->isOk(), $this->client->getResponse()->getContent());

        $saveButton = $crawler->selectButton('Save');
        $form       = $saveButton->form();
        $form['report[name]']->setValue('Report ABC');

        $this->client->submit($form);
        Assert::assertTrue($this->client->getResponse()->isOk(), $this->client->getResponse()->getContent());
        $report = $this->em->getRepository(Report::class)->findOneBy(['name' => 'Report ABC']);

        $crawler = $this->client->request(Request::METHOD_GET, "/s/reports/clone/{$report->getId()}");
        Assert::assertTrue($this->client->getResponse()->isOk(), $this->client->getResponse()->getContent());

        $saveButton = $crawler->selectButton('Save');
        $form       = $saveButton->form();
        $form['report[name]']->setValue('Report ABC - cloned');

        $this->client->submit($form);
        Assert::assertTrue($this->client->getResponse()->isOk(), $this->client->getResponse()->getContent());
        $reportClone = $this->em->getRepository(Report::class)->findOneBy(['name' => 'Report ABC - cloned']);

        Assert::assertSame($report->getId() + 1, $reportClone->getId());
    }

    public function testContactReportSqlInjectionDontWork(): void
    {
        $report = new Report();
        $report->setName('Contact report');
        $report->setDescription('<b>This is allowed HTML</b>');
        $report->setSource('leads');
        $coulmns = [
            'l.firstname',
            'l.lastname',
            'l.email',
            'l.date_added',
        ];
        $report->setColumns($coulmns);

        $this->getContainer()->get('mautic.report.model.report')->saveEntity($report);

        // Check sql injection in parameter orderby
        $this->client->request('GET', '/s/reports/view/'.$report->getId().'?tmpl=list&name=report.'.$report->getId().'&orderby=a_id\'');
        $this->assertStringNotContainsString(
            'You have an error in your SQL syntax',
            $this->client->getResponse()->getContent()
        );

        // Check sql injection in parameter name
        $this->client->request('GET', '/s/reports/view/'.$report->getId().'?tmpl=list&name=report.'.$report->getId().'\'&orderby=a_id');
        $this->assertStringNotContainsString(
            'You have an error in your SQL syntax',
            $this->client->getResponse()->getContent()
        );

        // Check sql injection in parameter tmpl
        $this->client->request('GET', '/s/reports/view/'.$report->getId().'?tmpl=list\'&name=report.'.$report->getId().'&orderby=a_id');
        $this->assertStringNotContainsString(
            'You have an error in your SQL syntax',
            $this->client->getResponse()->getContent()
        );

        // Check sql injection in parameter id
        $this->client->request('GET', '/s/reports/view/'.$report->getId().'\'?tmpl=list&name=report.'.$report->getId().'&orderby=a_id');
        $this->assertStringNotContainsString(
            'You have an error in your SQL syntax',
            $this->client->getResponse()->getContent()
        );

        $this->client->request('GET', '/s/reports/view/'.$report->getId().'?tmpl=list&name=report.'.$report->getId().'&orderby=a_id');
        Assert::assertTrue($this->client->getResponse()->isOk());
    }

    public function testContactReportwithComanyDateAddedColumn(): void
    {
        $report = new Report();
        $report->setName('Contact report');
        $report->setDescription('<b>This is allowed HTML</b>');
        $report->setSource('leads');
        $coulmns = [
            'l.firstname',
            'companies_lead.date_added',
        ];
        $report->setColumns($coulmns);

        static::getContainer()->get('mautic.report.model.report')->saveEntity($report);

        // Check the details page
        $this->client->request('GET', '/s/reports/view/'.$report->getId());
        Assert::assertTrue($this->client->getResponse()->isOk());
    }

    public function testEmailReportWithAggregatedColumnsAndTotals(): void
    {
        $contactModel = static::getContainer()->get('mautic.lead.model.lead');

        // Create and save contacts
        $payload = [
            [
                'email'   => 'test1@example.com',
                'company' => 'Test',
                'points'  => 50,
            ],
            [
                'email'   => 'test2@example.com',
                'company' => 'Test',
                'points'  => 25,
            ],
            [
                'email'   => 'test3@example.com',
                'company' => 'Test',
                'points'  => 123,
            ],
            [
                'email'   => 'test4@example.com',
                'company' => 'Example',
                'points'  => 1234,
            ],
            [
                'email'   => 'test5@example.com',
                'company' => 'Example Test',
                'points'  => 0,
            ],
            [
                'email'   => 'test6@example.com',
                'company' => 'Example Test',
                'points'  => -10,
            ],
        ];

        foreach ($payload as $item) {
            $contact = new Lead();
            $contact->setEmail($item['email']);
            $contact->setCompany($item['company']);
            $contact->setPoints($item['points']);

            $contactModel->saveEntity($contact);
        }

        // Create and save report
        $report = new Report();
        $report->setName('Company lead points report');
        $report->setSource('leads');
        $columns = [
            'l.company',
        ];
        $report->setColumns($columns);
        $report->setGroupBy(['l.company']);
        $report->setTableOrder([
            [
                'column'    => 'l.company',
                'direction' => 'ASC',
            ],
        ]);
        $report->setAggregators([
            [
                'column'    => 'l.points',
                'function'  => 'MIN',
            ],
            [
                'column'    => 'l.points',
                'function'  => 'MAX',
            ],
            [
                'column'    => 'l.points',
                'function'  => 'SUM',
            ],
            [
                'column'    => 'l.points',
                'function'  => 'COUNT',
            ],
            [
                'column'    => 'l.points',
                'function'  => 'AVG',
            ],
        ]);
        static::getContainer()->get('mautic.report.model.report')->saveEntity($report);

        // Expected report table values [ID, Company name, MIN Points, Max Points, SUM Points, COUNT Points, AVG Points]
        $expected = [
            ['1', 'Example', '1234', '1234', '1234', '1', '1234.0000'],
            ['2', 'Example Test', '-10', '0', '-10', '2', '-5.0000'],
            ['3', 'Test', '25', '123', '198', '3', '66.0000'],
            ['Totals', '&nbsp;',  '-10', '1234', '1422', '6', '431.6667'],
        ];

        // Get report view
        $this->client->request('GET', '/s/reports/view/'.$report->getId());
        $response = $this->client->getResponse();
        Assert::assertTrue($response->isOk());

        // Load view content as HTML and convert the report table to result array
        $result  = [];
        $content = $response->getContent();
        $dom     = new \DOMDocument('1.0', 'utf-8');
        $dom->loadHTML(mb_encode_numericentity($content, [0x80, 0x10FFFF, 0, 0xFFFFF], 'UTF-8'), LIBXML_NOERROR);
        $tbody = $dom->getElementById('reportTable')->getElementsByTagName('tbody')[0];
        $rows  = $tbody->getElementsByTagName('tr');

        for ($i = 0; $i < count($rows); ++$i) {
            $cells = $rows[$i]->getElementsByTagName('td');
            foreach ($cells as $c) {
                $result[$i][] = htmlentities(trim($c->nodeValue));
            }
        }

        Assert::assertSame($expected, $result);
        Assert::assertCount($tbody->childElementCount, $expected);
    }

    public function testContactReportNotLikeExpression(): void
    {
        $contactModel = self::$container->get('mautic.lead.model.lead');

        // Create and save contacts
        $payload = [
            [
                'email'     => 'test1@example.com',
                'firstname' => 'Tester',
            ],
            [
                'email'     => 'test2@example.com',
                'firstname' => 'Example',
            ],
        ];

        foreach ($payload as $item) {
            $contact = new Lead();
            $contact->setEmail($item['email']);
            $contact->setFirstname($item['firstname']);

            $contactModel->saveEntity($contact);
        }

        $report = new Report();
        $report->setName('Contact report');
        $report->setDescription('<b>This is allowed HTML</b>');
        $report->setSource('leads');
        $coulmns = [
            'l.firstname',
        ];
        $report->setColumns($coulmns);
        $report->setFilters([
            [
                'column'    => 'l.firstname',
                'glue'      => 'and',
                'value'     => 'Test',
                'condition' => 'notLike',
            ]]
        );

        $this->getContainer()->get('mautic.report.model.report')->saveEntity($report);

        // Check the details page
        $this->client->request('GET', '/s/reports/view/'.$report->getId());
        Assert::assertTrue($this->client->getResponse()->isOk());
        $response = $this->client->getResponse();
        $content  = $response->getContent();

        $dom     = new \DOMDocument('1.0', 'utf-8');

        $dom->loadHTML(mb_encode_numericentity($content, [0x80, 0x10FFFF, 0, 0xFFFFF], 'UTF-8'), LIBXML_NOERROR);
        $tbody = $dom->getElementById('reportTable')->getElementsByTagName('tbody')[0];
        $rows  = $tbody->getElementsByTagName('tr');

        for ($i = 0; $i < count($rows); ++$i) {
            $cells = $rows[$i]->getElementsByTagName('td');
            foreach ($cells as $c) {
                $result[$i][] = htmlentities(trim($c->nodeValue));
            }
        }
        $this->assertEquals(2, count($result));
    }

    /**
     * @dataProvider scheduleProvider
     *
     * @throws NotSupported
     * @throws MappingException
     */
    public function testScheduleEdit(string $oldScheduleUnit, ?string $oldScheduleDay, ?string $oldScheduleMonthFrequency, string $newScheduleUnit, ?string $newScheduleDay, ?string $newScheduleMonthFrequency): void
    {
        $report = new Report();
        $report->setName('Checking for schedule change');
        $report->setDescription('<b>This is a report</b>');
        $report->setSource('leads');
        $columns = [
            'l.firstname',
        ];
        $report->setColumns($columns);

        $report->setIsScheduled(true);
        $report->setScheduleUnit($oldScheduleUnit);
        $report->setScheduleDay($oldScheduleDay);
        $report->setScheduleMonthFrequency($oldScheduleMonthFrequency);
        $this->getContainer()->get('mautic.report.model.report')->saveEntity($report);

        $schedule = $report->getSchedule();

        $this->assertIsArray($schedule, 'Schedule should be an array');
        $this->assertArrayHasKey('schedule_unit', $schedule);
        $this->assertArrayHasKey('schedule_day', $schedule);
        $this->assertArrayHasKey('schedule_month_frequency', $schedule);
        $this->assertEquals(['schedule_unit' => $oldScheduleUnit, 'schedule_day' => $oldScheduleDay, 'schedule_month_frequency' => $oldScheduleMonthFrequency], $schedule, 'Old schedule should be set correctly');

        $crawler        = $this->client->request(Request::METHOD_GET, 's/reports/edit/'.$report->getId());
        $buttonCrawler  =  $crawler->selectButton('Save & Close');
        $form           = $buttonCrawler->form();
        $form['report[scheduleUnit]']->setValue($newScheduleUnit);
        if (!is_null($newScheduleDay)) {
            $form['report[scheduleDay]']->setValue($newScheduleDay);
        }
        if (!is_null($newScheduleMonthFrequency)) {
            $form['report[scheduleMonthFrequency]']->setValue($newScheduleMonthFrequency);
        }

        $this->client->submit($form);
        $response = $this->client->getResponse();
        $this->assertTrue($response->isOk());

        $report   = $this->em->getRepository(Report::class)->find($report->getId());
        $schedule = $report->getSchedule();
        $this->getContainer()->get('mautic.report.model.report')->saveEntity($report);

        $this->em->clear();

        $this->assertEquals(['schedule_unit' => $newScheduleUnit, 'schedule_day' => $newScheduleDay, 'schedule_month_frequency' => $newScheduleMonthFrequency], $schedule, 'Schedule should be edited correctly');
    }

    /**
     * @return array<mixed>[]
     */
    public function scheduleProvider(): array
    {
        return [
            'daily_to_weekly'  => [SchedulerEnum::UNIT_DAILY, null, null, SchedulerEnum::UNIT_WEEKLY, SchedulerEnum::DAY_MO, null],
            'daily_to_monthly' => [SchedulerEnum::UNIT_DAILY, null, null, SchedulerEnum::UNIT_MONTHLY, SchedulerEnum::DAY_MO, '1'],

            'weekly_to_daily'   => [SchedulerEnum::UNIT_WEEKLY, SchedulerEnum::DAY_MO, null, SchedulerEnum::UNIT_DAILY, null, null],
            'weekly_to_weekly'  => [SchedulerEnum::UNIT_WEEKLY, SchedulerEnum::DAY_MO, null, SchedulerEnum::UNIT_WEEKLY, SchedulerEnum::DAY_TU, null],
            'weekly_to_monthly' => [SchedulerEnum::UNIT_WEEKLY, SchedulerEnum::DAY_WE, null, SchedulerEnum::UNIT_MONTHLY, SchedulerEnum::DAY_TH, '-1'],

            'monthly_to_daily'   => [SchedulerEnum::UNIT_MONTHLY, SchedulerEnum::DAY_FR, '-1', SchedulerEnum::UNIT_DAILY, null, null],
            'monthly_to_weekly'  => [SchedulerEnum::UNIT_MONTHLY, SchedulerEnum::DAY_FR, '1', SchedulerEnum::UNIT_WEEKLY, SchedulerEnum::DAY_SA, null],
            'monthly_to_monthly' => [SchedulerEnum::UNIT_MONTHLY, SchedulerEnum::DAY_FR, '1', SchedulerEnum::UNIT_MONTHLY, SchedulerEnum::DAY_SU, '-1'],
        ];
    }

    public function testDescriptionIsNotEscaped(): void
    {
        $report = new Report();
        $report->setName('HTML Test');
        $report->setDescription('<b>This is allowed HTML</b>');
        $report->setSource('email');
        static::getContainer()->get('mautic.report.model.report')->saveEntity($report);

        // Check the details page
        $this->client->request('GET', '/s/reports/'.$report->getId());
        $clientResponse        = $this->client->getResponse();
        $clientResponseContent = $clientResponse->getContent();
        $this->assertStringContainsString('<small><b>This is allowed HTML</b></small>', $clientResponseContent);

        // Check the list
        $this->client->request('GET', '/s/reports');
        $clientResponse        = $this->client->getResponse();
        $clientResponseContent = $clientResponse->getContent();
        $this->assertStringContainsString('<small><b>This is allowed HTML</b></small>', $clientResponseContent);
    }

    /**
     * @param string[] $graphs
     */
    private function createReport(string $name, string $source, array $graphs): Report
    {
        $report = new Report();
        $report->setName($name);
        $report->setDescription('<b>This is allowed HTML</b>');
        $report->setSource($source);
        $report->setGraphs($graphs);

        $this->em->persist($report);
        $this->em->flush();

        return $report;
    }

    private function createPage(string $title): Page
    {
        $page = new Page();
        $page->setTitle($title);
        $page->setAlias(str_replace(' ', '_', $title));

        $this->em->persist($page);
        $this->em->flush();

        return $page;
    }

    private function createHit(?Page $page): Hit
    {
        $hit = new Hit();
        $hit->setDateHit(new \DateTime());
        $hit->setCode(200);
        $hit->setTrackingId(hash('sha1', uniqid('mt_rand()', true)));
        $hit->setIpAddress(new IpAddress('127.0.0.1'));
        $hit->setPage($page);

        $this->em->persist($hit);
        $this->em->flush();

        return $hit;
    }
}
