<?php

namespace Mautic\EmailBundle\Tests\Helper;

use Mautic\CoreBundle\Factory\MauticFactory;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\InputHelper;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Exception\InvalidEmailException;
use Mautic\EmailBundle\Helper\FromEmailHelper;
use Mautic\EmailBundle\Helper\MailHashHelper;
use Mautic\EmailBundle\Helper\MailHelper;
use Mautic\EmailBundle\Mailer\Exception\BatchQueueMaxException;
use Mautic\EmailBundle\Mailer\Message\MauticMessage;
use Mautic\EmailBundle\MonitoredEmail\Mailbox;
use Mautic\EmailBundle\Tests\Helper\Transport\BatchTransport;
use Mautic\EmailBundle\Tests\Helper\Transport\BcInterfaceTokenTransport;
use Mautic\EmailBundle\Tests\Helper\Transport\SmtpTransport;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadRepository;
use Mautic\LeadBundle\Model\LeadModel;
use Monolog\Logger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Header\HeaderInterface;
use Symfony\Component\Mime\Header\MailboxListHeader;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

class MailHelperTest extends TestCase
{
    private const MINIFY_HTML = '<!doctype html>
    <html lang=3D"en" xmlns=3D"http://www.w3.org/1999/xhtml" xmlns:v=3D"urn:schemas-microsoft-com:vml" xmlns:o=3D"urn:schemas-microsoft-com:office:office">
      <head>
        <title>Test</title>
        <body style=3D"word-spacing:normal;background-color:#FFFFFF;">
            <div  style=3D"background:#FFFFFF;background-color:#FFFFFF;margin:0pxauto;max-width:600px;">
        </body>
    </html>';

    /**
     * @var array<array<string|null>>
     */
    private $defaultParams = [
        ['mailer_from_email', null, 'nobody@nowhere.com'],
        ['mailer_from_name', null, 'No Body'],
    ];

    private FromEmailHelper $fromEmailHelper;

    /**
     * @var MauticFactory&MockObject
     */
    private MockObject $mockFactory;

    /**
     * @var CoreParametersHelper&MockObject
     */
    private MockObject $coreParametersHelper;

    /**
     * @var Mailbox&MockObject
     */
    private MockObject $mailbox;

    /**
     * @var LeadRepository&MockObject
     */
    private MockObject $contactRepository;

    /**
     * @var LoggerInterface&MockObject
     */
    private MockObject $logger;

    /**
     * @var RouterInterface&MockObject
     */
    private MockObject $router;

    private MailHashHelper $mailHashHelper;

    /**
     * @var array<array<string,string|int>>
     */
    protected $contacts = [
        [
            'id'        => 1,
            'email'     => 'contact1@somewhere.com',
            'firstname' => 'Contact',
            'lastname'  => '1',
            'owner_id'  => 1,
        ],
        [
            'id'        => 2,
            'email'     => 'contact2@somewhere.com',
            'firstname' => 'Contact',
            'lastname'  => '2',
            'owner_id'  => 0,
        ],
        [
            'id'        => 3,
            'email'     => 'contact3@somewhere.com',
            'firstname' => 'Contact',
            'lastname'  => '3',
            'owner_id'  => 2,
        ],
        [
            'id'        => 4,
            'email'     => 'contact4@somewhere.com',
            'firstname' => 'Contact',
            'lastname'  => '4',
            'owner_id'  => 1,
        ],
    ];

    protected function setUp(): void
    {
        defined('MAUTIC_ENV') or define('MAUTIC_ENV', 'test');

        $this->contactRepository    = $this->createMock(LeadRepository::class);
        $this->coreParametersHelper = $this->createMock(CoreParametersHelper::class);
        $this->fromEmailHelper      = new FromEmailHelper($this->coreParametersHelper, $this->contactRepository);
        $this->mockFactory          = $this->createMock(MauticFactory::class);
        $this->mailbox              = $this->createMock(Mailbox::class);
        $this->logger               = $this->createMock(LoggerInterface::class);
        $this->router               = $this->createMock(RouterInterface::class);
        $this->mailHashHelper       = new MailHashHelper($this->coreParametersHelper);
    }

    public function testQueueModeThrowsExceptionWhenBatchLimitHit(): void
    {
        $this->expectException(BatchQueueMaxException::class);

        $this->mockFactory->method('getParameter')
            ->will(
                $this->returnValueMap(
                    [
                        ['mailer_return_path', false, null],
                        ['mailer_spool_type', false, 'memory'],
                    ]
                )
            );

        $batchMailHelper = new MailHelper($this->mockFactory, new Mailer(new BatchTransport()), $this->fromEmailHelper, $this->coreParametersHelper, $this->mailbox, $this->logger, $this->mailHashHelper, $this->router);
        $batchMailHelper->enableQueue();
        $batchMailHelper->addTo('somebody@somewhere.com');
        $batchMailHelper->addTo('somebodyelse@somewhere.com');
        $batchMailHelper->addTo('somebodyelse2@somewhere.com');
        $batchMailHelper->addTo('somebodyelse3@somewhere.com');
        $batchMailHelper->addTo('somebodyelse4@somewhere.com');
    }

    public function testQueueModeDisabledDoesNotThrowsExceptionWhenBatchLimitHit(): void
    {
        $this->mockFactory->method('getParameter')
            ->will(
                $this->returnValueMap(
                    [
                        ['mailer_return_path', false, null],
                        ['mailer_spool_type', false, 'memory'],
                    ]
                )
            );

        $singleMailHelper = new MailHelper($this->mockFactory, new Mailer(new BcInterfaceTokenTransport()), $this->fromEmailHelper, $this->coreParametersHelper, $this->mailbox, $this->logger, $this->mailHashHelper, $this->router);

        try {
            $singleMailHelper->addTo('somebody@somewhere.com');
            $singleMailHelper->addTo('somebodyelse@somewhere.com');
        } catch (BatchQueueMaxException) {
            $this->fail('BatchQueueMaxException thrown');
        }

        // Otherwise success
        self::expectNotToPerformAssertions();
    }

    public function testQueuedEmailFromOverride(): void
    {
        $this->coreParametersHelper->method('get')->will($this->returnValueMap($this->defaultParams));

        $singleMailHelper = new MailHelper($this->mockFactory, new Mailer(new BcInterfaceTokenTransport()), $this->fromEmailHelper, $this->coreParametersHelper, $this->mailbox, $this->logger, $this->mailHashHelper, $this->router);
        $singleMailHelper->enableQueue();

        $email = new Email();
        $email->setFromAddress('override@nowhere.com');
        $email->setFromName('Test');
        $email->setUseOwnerAsMailer(false);
        $email->setSubject('Test');

        $singleMailHelper->setEmail($email);

        foreach ($this->contacts as $contact) {
            $singleMailHelper->addTo($contact['email']);
            $singleMailHelper->setLead($contact);
            $singleMailHelper->queue();
        }

        $singleMailHelper->flushQueue();
        $from = $singleMailHelper->message->getFrom();

        $this->assertTrue(1 === count($from));
        $this->assertSame('override@nowhere.com', $from[0]->getAddress());

        $singleMailHelper->reset();
        foreach ($this->contacts as $contact) {
            $singleMailHelper->addTo($contact['email']);
            $singleMailHelper->setLead($contact);
            $singleMailHelper->queue();
        }
        $singleMailHelper->flushQueue();
        $from = $singleMailHelper->message->getFrom();

        $this->assertTrue(1 === count($from));
        $this->assertSame('nobody@nowhere.com', $from[0]->getAddress());
    }

    public function testBatchMode(): void
    {
        $singleMailHelper = new MailHelper($this->mockFactory, new Mailer(new BcInterfaceTokenTransport()), $this->fromEmailHelper, $this->coreParametersHelper, $this->mailbox, $this->logger, $this->mailHashHelper, $this->router);
        $singleMailHelper->enableQueue();

        $email = new Email();
        $email->setSubject('Hello');
        $singleMailHelper->setEmail($email);

        $singleMailHelper->addTo($this->contacts[0]['email']);
        $singleMailHelper->setLead($this->contacts[0]);
        $singleMailHelper->queue();
        $singleMailHelper->flushQueue();
        $errors = $singleMailHelper->getErrors();
        $this->assertTrue(empty($errors['failures']), var_export($errors, true));

        $singleMailHelper->reset(false);
        $singleMailHelper->setEmail($email);
        $singleMailHelper->addTo($this->contacts[1]['email']);
        $singleMailHelper->setLead($this->contacts[1]);
        $singleMailHelper->queue();
        $singleMailHelper->flushQueue();
        $errors = $singleMailHelper->getErrors();
        $this->assertTrue(empty($errors['failures']), var_export($errors, true));
    }

    public function testQueuedOwnerAsMailer(): void
    {
        $this->coreParametersHelper->method('get')->will($this->returnValueMap($this->defaultParams));

        $this->contactRepository->method('getLeadOwner')
            ->willReturnOnConsecutiveCalls(
                ['email' => 'owner1@owner.com', 'first_name' => 'owner 1', 'last_name' => null, 'signature' => 'owner 1'],
                ['email' => 'owner2@owner.com', 'first_name' => 'owner 2', 'last_name' => null, 'signature' => 'owner 2'],
            );
        $transport     = new BatchTransport();
        $symfonyMailer = new Mailer($transport);

        $mailer = new MailHelper($this->mockFactory, $symfonyMailer, $this->fromEmailHelper, $this->coreParametersHelper, $this->mailbox, $this->logger, $this->mailHashHelper, $this->router);

        $email = new Email();
        $email->setUseOwnerAsMailer(true);
        $email->setSubject('Subject');
        $email->setCustomHtml('content');

        $mailer->setEmail($email);
        $mailer->enableQueue();

        foreach ($this->contacts as $contact) {
            $mailer->addTo($contact['email']);
            $mailer->setLead($contact);
            $mailer->queue();
        }

        $mailer->flushQueue([]);

        $this->assertTrue(empty($mailer->getErrors()['failures']));

        $fromAddresses = $transport->getFromAddresses();
        $metadatas     = $transport->getMetadatas();

        $this->assertEquals(3, count($fromAddresses));
        $this->assertEquals(3, count($metadatas));
        $this->assertEquals(['owner1@owner.com', 'nobody@nowhere.com', 'owner2@owner.com'], $fromAddresses);

        foreach ($metadatas as $key => $metadata) {
            $this->assertTrue(isset($metadata[$this->contacts[$key]['email']]));

            if (0 === $key) {
                // Should have two contacts
                $this->assertEquals(2, count($metadata));
                $this->assertTrue(isset($metadata['contact4@somewhere.com']));
            } else {
                $this->assertEquals(1, count($metadata));
            }

            // Check that signatures are valid
            if (1 === $key) {
                // signature should be empty
                $this->assertEquals('', $metadata['contact2@somewhere.com']['tokens']['{signature}']);
            } else {
                $this->assertEquals($metadata[$this->contacts[$key]['email']]['tokens']['{signature}'], 'owner '.$this->contacts[$key]['owner_id']);

                if (0 === $key) {
                    // Ensure the last contact has the correct signature
                    $this->assertEquals($metadata['contact4@somewhere.com']['tokens']['{signature}'], 'owner '.$this->contacts[$key]['owner_id']);
                }
            }
        }

        // Validate that the message object only has the contacts for the last "from" group to ensure we aren't sending duplicates
        $this->assertEquals('contact3@somewhere.com', $mailer->message->getTo()[0]->getAddress());
    }

    public function testMailAsOwnerWithEncodedCharactersInName(): void
    {
        $this->coreParametersHelper->method('get')
            ->will($this->returnValueMap(
                [
                    ['mailer_from_email', null, 'nobody@nowhere.com'],
                    ['mailer_from_name', null, 'No Body&#39;s Business'],
                ]
            ));

        $this->contactRepository->method('getLeadOwner')
            ->willReturnOnConsecutiveCalls(
                ['id' => 1, 'email' => 'owner1@owner.com', 'first_name' => 'owner 1', 'last_name' => '', 'signature' => 'owner 1'],
                ['id' => 2, 'email' => 'owner2@owner.com', 'first_name' => 'owner 2', 'last_name' => '', 'signature' => 'owner 2'],
            );

        $transport     = new BatchTransport();
        $symfonyMailer = new Mailer($transport);

        $mailer = new MailHelper($this->mockFactory, $symfonyMailer, $this->fromEmailHelper, $this->coreParametersHelper, $this->mailbox, $this->logger, $this->mailHashHelper, $this->router);
        $email  = new Email();
        $email->setUseOwnerAsMailer(true);

        $mailer->setEmail($email);
        $mailer->enableQueue();
        $mailer->setSubject('Hello');

        foreach ($this->contacts as $contact) {
            $mailer->addTo($contact['email']);
            $mailer->setLead($contact);
            $mailer->queue();
        }

        $mailer->flushQueue([]);

        $fromAddresses = $transport->getFromAddresses();
        $fromNames     = $transport->getFromNames();

        $this->assertEquals(3, count($fromAddresses)); // 3, not 4, because the last contact has the same owner as the first contact.
        $this->assertEquals(3, count($fromNames));
        $this->assertEquals(['owner1@owner.com', 'nobody@nowhere.com', 'owner2@owner.com'], $fromAddresses);
        $this->assertEquals(['owner 1', 'No Body\'s Business', 'owner 2'], $fromNames);
    }

    public function testBatchIsEnabledWithBcTokenInterface(): void
    {
        $this->coreParametersHelper->method('get')->will($this->returnValueMap($this->defaultParams));

        $this->contactRepository->method('getLeadOwner')
            ->willReturnOnConsecutiveCalls(
                ['id' => 1, 'email' => 'owner1@owner.com', 'first_name' => 'owner 1', 'last_name' => '', 'signature' => 'owner 1'],
                ['id' => 2, 'email' => 'owner2@owner.com', 'first_name' => 'owner 2', 'last_name' => '', 'signature' => 'owner 2'],
            );
        $transport = new BatchTransport();
        $mailer    = new MailHelper($this->mockFactory, new Mailer($transport), $this->fromEmailHelper, $this->coreParametersHelper, $this->mailbox, $this->logger, $this->mailHashHelper, $this->router);
        $email     = new Email();

        $email->setUseOwnerAsMailer(true);

        $mailer->setEmail($email);
        $mailer->enableQueue();
        $mailer->setSubject('Hello');

        foreach ($this->contacts as $contact) {
            $mailer->addTo($contact['email']);
            $mailer->setLead($contact);
            $mailer->queue();
        }

        $mailer->flushQueue([]);

        self::assertTrue(empty($mailer->getErrors()['failures']));

        $fromAddresses = $transport->getFromAddresses();
        $metadatas     = $transport->getMetadatas();

        $this->assertEquals(3, count($fromAddresses));
        $this->assertEquals(3, count($metadatas));
        self::assertSame(
            ['owner1@owner.com', 'nobody@nowhere.com', 'owner2@owner.com'],
            $fromAddresses
        );
    }

    public function testGlobalFromThatAllFromAddressesAreTheSame(): void
    {
        $this->contactRepository->method('getLeadOwner')
            ->willReturnOnConsecutiveCalls(
                ['id' => 1, 'email' => 'owner1@owner.com', 'first_name' => 'owner 1', 'last_name' => '', 'signature' => 'owner 1'],
                ['id' => 2, 'email' => 'owner2@owner.com', 'first_name' => 'owner 2', 'last_name' => '', 'signature' => 'owner 2'],
            );

        $transport     = new BcInterfaceTokenTransport();
        $symfonyMailer = new Mailer($transport);

        $mailer = new MailHelper($this->mockFactory, $symfonyMailer, $this->fromEmailHelper, $this->coreParametersHelper, $this->mailbox, $this->logger, $this->mailHashHelper, $this->router);
        $mailer->enableQueue();
        $mailer->setSubject('Hello');
        $mailer->setFrom('override@owner.com');

        foreach ($this->contacts as $contact) {
            $mailer->addTo($contact['email']);
            $mailer->setLead($contact);
            $mailer->queue();
        }

        $this->assertEmpty($mailer->getErrors());

        $fromAddresses = $transport->getFromAddresses();

        $this->assertEquals(['override@owner.com'], array_unique($fromAddresses));
    }

    public function testStandardEmailFrom(): void
    {
        $transport     = new SmtpTransport();
        $symfonyMailer = new Mailer($transport);
        $mailer        = new MailHelper($this->mockFactory, $symfonyMailer, $this->fromEmailHelper, $this->coreParametersHelper, $this->mailbox, $this->logger, $this->mailHashHelper, $this->router);
        $email         = new Email();

        $email->setUseOwnerAsMailer(false);
        $email->setFromAddress('override@nowhere.com');
        $email->setFromName('Test');
        $email->setSubject('Subject');
        $email->setCustomHtml('content');
        $mailer->setEmail($email);

        foreach ($this->contacts as $contact) {
            $mailer->addTo($contact['email']);
            $mailer->setLead($contact);
            $mailer->setBody('{signature}');
            $mailer->send();
            $address = $mailer->message->getFrom() ? $mailer->message->getFrom()[0]->getAddress() : null;
            $this->assertEquals('override@nowhere.com', $address);
        }
    }

    public function testStandardEmailReplyTo(): void
    {
        $this->coreParametersHelper->method('get')->will($this->returnValueMap($this->defaultParams));

        $transport     = new SmtpTransport();
        $symfonyMailer = new Mailer($transport);
        $mailer        = new MailHelper($this->mockFactory, $symfonyMailer, $this->fromEmailHelper, $this->coreParametersHelper, $this->mailbox, $this->logger, $this->mailHashHelper, $this->router);
        $email         = new Email();

        $email->setSubject('Subject');
        $email->setCustomHtml('content');

        $mailer->setEmail($email);
        $mailer->send();
        $replyTo = $mailer->message->getReplyTo() ? $mailer->message->getReplyTo()[0]->getAddress() : null;
        $this->assertEquals('nobody@nowhere.com', $replyTo);

        $email->setReplyToAddress('replytooverride@nowhere.com');
        $mailer->setEmail($email);
        $mailer->send();
        $replyTo = $mailer->message->getReplyTo() ? $mailer->message->getReplyTo()[0]->getAddress() : null;
        $this->assertEquals('replytooverride@nowhere.com', $replyTo);
    }

    public function testEmailReplyToWithFromEmail(): void
    {
        $this->coreParametersHelper->method('get')->will($this->returnValueMap($this->defaultParams));
        $transport     = new SmtpTransport();
        $symfonyMailer = new Mailer($transport);
        $mailer        = new MailHelper($this->mockFactory, $symfonyMailer, $this->fromEmailHelper, $this->coreParametersHelper, $this->mailbox, $this->logger, $this->mailHashHelper, $this->router);
        $email         = new Email();

        // From address is set
        $email->setFromAddress('from@nowhere.com');
        $email->setSubject('Subject');
        $email->setCustomHtml('content');
        $mailer->setEmail($email);
        $mailer->send();
        $replyTo = $mailer->message->getReplyTo()[0]->getAddress();
        // Expect from address in reply to
        $this->assertEquals('from@nowhere.com', $replyTo);
    }

    public function testEmailReplyToWithFromAndGlobalEmail(): void
    {
        $params = [
            ['mailer_from_email', null, 'nobody@nowhere.com'],
            ['mailer_reply_to_email', null, 'admin@mautic.com'],
        ];

        $this->coreParametersHelper->method('get')->will($this->returnValueMap($params));

        $transport     = new SmtpTransport();
        $symfonyMailer = new Mailer($transport);
        $mailer        = new MailHelper($this->mockFactory, $symfonyMailer, $this->fromEmailHelper, $this->coreParametersHelper, $this->mailbox, $this->logger, $this->mailHashHelper, $this->router);
        $email         = new Email();

        // From address is set
        $email->setFromAddress('from@nowhere.com');
        $email->setSubject('Subject');
        $email->setCustomHtml('content');
        $mailer->setEmail($email);
        $mailer->send();
        $replyTo = $mailer->message->getReplyTo() ? $mailer->message->getReplyTo()[0]->getAddress() : null;
        // Expect from address in reply to
        $this->assertEquals('admin@mautic.com', $replyTo);
    }

    public function testStandardOwnerAsMailer(): void
    {
        $params = [
            ['mailer_from_email', null, 'nobody@nowhere.com'],
        ];
        $this->coreParametersHelper->method('get')->will($this->returnValueMap($params));

        $this->contactRepository->method('getLeadOwner')
            ->willReturnOnConsecutiveCalls(
                ['id' => 1, 'email' => 'owner1@owner.com', 'first_name' => 'owner 1', 'last_name' => '', 'signature' => 'owner 1'],
                ['id' => 2, 'email' => 'owner2@owner.com', 'first_name' => 'owner 2', 'last_name' => '', 'signature' => 'owner 2'],
            );

        $transport     = new SmtpTransport();
        $symfonyMailer = new Mailer($transport);
        $mailer        = new MailHelper($this->mockFactory, $symfonyMailer, $this->fromEmailHelper, $this->coreParametersHelper, $this->mailbox, $this->logger, $this->mailHashHelper, $this->router);

        $email = new Email();
        $email->setUseOwnerAsMailer(true);
        $email->setSubject('Subject');
        $email->setCustomHtml('content');
        $mailer->setEmail($email);

        $mailer->setBody('{signature}');

        foreach ($this->contacts as $contact) {
            $mailer->addTo($contact['email']);
            $mailer->setLead($contact);
            $mailer->send();

            $body = $mailer->message->getHtmlBody();
            $from = $mailer->message->getFrom() ? $mailer->message->getFrom()[0]->getAddress() : null;

            if ($contact['owner_id']) {
                $this->assertEquals('owner'.$contact['owner_id'].'@owner.com', $from);
                $this->assertEquals('owner '.$contact['owner_id'], $body);
            } else {
                $this->assertEquals('nobody@nowhere.com', $from);
                $this->assertEquals('{signature}', $body); // The {signature} token is replaced in a subscriber with the current user's signature. But this is a unit test, so the subscriber doesn't run.
            }
        }
    }

    /**
     * @dataProvider provideEmails
     */
    public function testValidateEmails(string $email, bool $isValid): void
    {
        $helper = $this->mockEmptyMailHelper();
        if (!$isValid) {
            $this->expectException(InvalidEmailException::class);
        }
        $this->assertNull($helper::validateEmail($email)); /** @phpstan-ignore-line as it's testing a deprecated method */
    }

    public function testValidateValidEmails(): void
    {
        $helper    = $this->mockEmptyMailHelper();
        $addresses = [
            'john@doe.com',
            'john@doe.email',
            'john.doe@email.com',
            'john+doe@email.com',
            'john@doe.whatevertldtheycomewithinthefuture',
        ];

        foreach ($addresses as $address) {
            // will throw InvalidEmailException if it will find the address invalid
            $this->assertNull($helper::validateEmail($address)); /** @phpstan-ignore-line as it's testing a deprecated method */
        }
    }

    /**
     * @return mixed[]
     */
    public function provideEmails(): array
    {
        return [
            ['john@doe.com', true],
            ['john@doe.email', true],
            ['john@doe.whatevertldtheycomewithinthefuture', true],
            ['john.doe@email.com', true],
            ['john+doe@email.com', true],
            ['john@doe', false],
            ['jo hn@doe.email', false],
            ['jo^hn@doe.email', false],
            ['jo\'hn@doe.email', false],
            ['jo;hn@doe.email', false],
            ['jo&hn@doe.email', false],
            ['jo*hn@doe.email', false],
            ['jo%hn@doe.email', false],
        ];
    }

    public function testValidateEmailWithoutTld(): void
    {
        $helper = $this->mockEmptyMailHelper();
        $this->expectException(InvalidEmailException::class);
        $helper::validateEmail('john@doe'); /** @phpstan-ignore-line as it's testing a deprecated method */
    }

    public function testValidateEmailWithSpaceInIt(): void
    {
        $helper = $this->mockEmptyMailHelper();
        $this->expectException(InvalidEmailException::class);
        $helper::validateEmail('jo hn@doe.email'); /** @phpstan-ignore-line as it's testing a deprecated method */
    }

    public function testValidateEmailWithCaretInIt(): void
    {
        $helper = $this->mockEmptyMailHelper();
        $this->expectException(InvalidEmailException::class);
        $helper::validateEmail('jo^hn@doe.email'); /** @phpstan-ignore-line as it's testing a deprecated method */
    }

    public function testValidateEmailWithApostropheInIt(): void
    {
        $helper = $this->mockEmptyMailHelper();
        $this->expectException(InvalidEmailException::class);
        $helper::validateEmail('jo\'hn@doe.email'); /** @phpstan-ignore-line as it's testing a deprecated method */
    }

    public function testValidateEmailWithSemicolonInIt(): void
    {
        $helper = $this->mockEmptyMailHelper();
        $this->expectException(InvalidEmailException::class);
        $helper::validateEmail('jo;hn@doe.email'); /** @phpstan-ignore-line as it's testing a deprecated method */
    }

    public function testValidateEmailWithAmpersandInIt(): void
    {
        $helper = $this->mockEmptyMailHelper();
        $this->expectException(InvalidEmailException::class);
        $helper::validateEmail('jo&hn@doe.email'); /** @phpstan-ignore-line as it's testing a deprecated method */
    }

    public function testValidateEmailWithStarInIt(): void
    {
        $helper = $this->mockEmptyMailHelper();
        $this->expectException(InvalidEmailException::class);
        $helper::validateEmail('jo*hn@doe.email'); /** @phpstan-ignore-line as it's testing a deprecated method */
    }

    public function testValidateEmailWithPercentInIt(): void
    {
        $helper = $this->mockEmptyMailHelper();
        $this->expectException(InvalidEmailException::class);
        $helper::validateEmail('jo%hn@doe.email'); /** @phpstan-ignore-line as it's testing a deprecated method */
    }

    public function testGlobalHeadersAreSet(): void
    {
        $params = [
            ['mailer_custom_headers', [], ['X-Mautic-Test' => 'test', 'X-Mautic-Test2' => 'test']],
            ['mailer_from_email', null, 'nobody@nowhere.com'],
        ];
        $this->coreParametersHelper->method('get')->will($this->returnValueMap($params));

        $transport     = new SmtpTransport();
        $symfonyMailer = new Mailer($transport);
        $mailer        = new MailHelper($this->mockFactory, $symfonyMailer, $this->fromEmailHelper, $this->coreParametersHelper, $this->mailbox, $this->logger, $this->mailHashHelper, $this->router);
        $mailer->setBody('{signature}');
        $mailer->addTo($this->contacts[0]['email']);
        $mailer->send();

        $customHeadersFounds = [];

        /** @var array<\Symfony\Component\Mime\Header\AbstractHeader> $headers */
        $headers = $mailer->message->getHeaders()->all();
        foreach ($headers as $header) {
            if (str_contains($header->getName(), 'X-Mautic-Test')) {
                $customHeadersFounds[] = $header->getName();

                $this->assertEquals('test', $header->getBody());
            }
        }

        $this->assertCount(2, $customHeadersFounds);
    }

    public function testGlobalHeadersAreMergedIfEmailEntityIsSet(): void
    {
        $params = [
            ['mailer_custom_headers', [], ['X-Mautic-Test' => 'test', 'X-Mautic-Test2' => 'test']],
            ['mailer_from_email', null, 'nobody@nowhere.com'],
        ];
        $this->coreParametersHelper->method('get')->will($this->returnValueMap($params));
        $transport     = new SmtpTransport();
        $symfonyMailer = new Mailer($transport);
        $mailer        = new MailHelper($this->mockFactory, $symfonyMailer, $this->fromEmailHelper, $this->coreParametersHelper, $this->mailbox, $this->logger, $this->mailHashHelper, $this->router);
        $mailer->addTo($this->contacts[0]['email']);

        $email = new Email();
        $email->setSubject('Test');
        $email->setCustomHtml('{signature}');
        $mailer->setEmail($email);
        $mailer->send();

        /** @var array<HeaderInterface> $headers */
        $headers = iterator_to_array($mailer->message->getHeaders()->all());

        foreach ($headers as $header) {
            if (str_contains($header->getName(), 'X-Mautic-Test')) {
                $this->assertEquals('test', $header->getBody());
            }
        }

        $this->assertSame('test', $headers['x-mautic-test']->getBody());
        $this->assertSame('test', $headers['x-mautic-test2']->getBody());
    }

    public function testEmailHeadersAreSet(): void
    {
        $params = [
            ['mailer_custom_headers', [], ['X-Mautic-Test' => 'test', 'X-Mautic-Test2' => 'test', 'custom-mautic-header' => '{contactfield=email}', 'Reply-to' => '{contactfield=email}']],
            ['mailer_from_email', null, 'nobody@nowhere.com'],
        ];
        $this->coreParametersHelper->method('get')->will($this->returnValueMap($params));

        $transport     = new SmtpTransport();
        $symfonyMailer = new Mailer($transport);
        $mailer        = new MailHelper($this->mockFactory, $symfonyMailer, $this->fromEmailHelper, $this->coreParametersHelper, $this->mailbox, $this->logger, $this->mailHashHelper, $this->router);
        $mailer->addTo($this->contacts[0]['email']);
        $mailer->setTokens([
            '{contactfield=email}' => $this->contacts[0]['email'],
        ]);
        $email = new Email();
        $email->setSubject('Test');
        $email->setCustomHtml('{signature}');
        $email->setHeaders(['X-Mautic-Test3' => 'test2', 'X-Mautic-Test4' => 'test2']);
        $mailer->setEmail($email);
        $mailer->send();

        $customHeadersFounds = [];

        /** @var array<\Symfony\Component\Mime\Header\AbstractHeader> $headers */
        $headers = $mailer->message->getHeaders()->all();

        foreach ($headers as $header) {
            if ('X-Mautic-Test' === $header->getName() || 'X-Mautic-Test2' === $header->getName()) {
                $customHeadersFounds[] = $header->getName();
                $this->assertEquals('test', $header->getBody());
            }
            if ('X-Mautic-Test3' === $header->getName() || 'X-Mautic-Test4' === $header->getName()) {
                $customHeadersFounds[] = $header->getName();
                $this->assertEquals('test2', $header->getBody());
            }
            if ('custom-mautic-header' === $header->getName()) {
                $customHeadersFounds[] = $header->getName();
                $this->assertEquals($this->contacts[0]['email'], $header->getBody());
            }

            if ('Reply-To' === $header->getName()) {
                $customHeadersFounds[] = $header->getName();
                $this->assertCount(1, $header->getBody());
            }
        }

        $this->assertCount(6, $customHeadersFounds);
    }

    public function testUnsubscribeHeader(): void
    {
        $params = [
            ['mailer_custom_headers', [], ['X-Mautic-Test' => 'test', 'X-Mautic-Test2' => 'test']],
            ['secret_key', null, 'secret'],
        ];
        $this->coreParametersHelper->method('get')->will($this->returnValueMap($params));

        $emailSecret = hash_hmac('sha256', 'someemail@email.test', 'secret');

        $this->router->expects($this->once())
            ->method('generate')
            ->with('mautic_email_unsubscribe',
                ['idHash' => 'hash', 'urlEmail' => 'someemail@email.test', 'secretHash' => $emailSecret],
                UrlGeneratorInterface::ABSOLUTE_URL)
            ->willReturn('http://www.somedomain.cz/email/unsubscribe/hash/someemail@email.test/'.$emailSecret);

        $transport     = new SmtpTransport();
        $symfonyMailer = new Mailer($transport);
        $mailer        = new MailHelper($this->mockFactory, $symfonyMailer, $this->fromEmailHelper, $this->coreParametersHelper, $this->mailbox, $this->logger, $this->mailHashHelper, $this->router);
        $mailer->setIdHash('hash');

        $email = new Email();
        $email->setSubject('Test');
        $email->setCustomHtml('<html></html>');
        $lead = new Lead();
        $lead->setEmail('someemail@email.test');
        $mailer->setIdHash('hash');
        $mailer->setEmail($email);
        $mailer->setLead($lead);

        $mailer->setEmailType(MailHelper::EMAIL_TYPE_MARKETING);
        $headers = $mailer->getCustomHeaders();

        $this->assertSame('<http://www.somedomain.cz/email/unsubscribe/hash/someemail@email.test/'.$emailSecret.'>', $headers['List-Unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $headers['List-Unsubscribe-Post']);

        // There are no unsubscribe headers in transactional emails.
        $mailer->setEmailType(MailHelper::EMAIL_TYPE_TRANSACTIONAL);
        $headers = $mailer->getCustomHeaders();
        $this->assertNull($headers['List-Unsubscribe'] ?? null);
        $this->assertNull($headers['List-Unsubscribe-Post'] ?? null);
    }

    public function testUnsubscribeHeaderParameterOrder(): void
    {
        $params = [
            ['mailer_custom_headers', [],
                [
                    'X-Mautic-Test'    => 'test',
                    'X-Mautic-Test2'   => 'test',
                    'List-Unsubscribe' => '<mailto:list@host.com?subject=unsubscribe>',
                ]],
            ['secret_key', null, 'secret'],
        ];
        $this->coreParametersHelper->method('get')->will($this->returnValueMap($params));

        $emailSecret = hash_hmac('sha256', 'someemail@email.test', 'secret');
        $this->router->expects($this->once())
            ->method('generate')
            ->with('mautic_email_unsubscribe',
                ['idHash' => 'hash', 'urlEmail' => 'someemail@email.test', 'secretHash' => $emailSecret],
                UrlGeneratorInterface::ABSOLUTE_URL)
            ->willReturn('http://www.somedomain.cz/email/unsubscribe/hash/someemail@email.test/'.$emailSecret);

        $transport     = new SmtpTransport();
        $symfonyMailer = new Mailer($transport);
        $mailer        = new MailHelper($this->mockFactory, $symfonyMailer, $this->fromEmailHelper, $this->coreParametersHelper, $this->mailbox, $this->logger, $this->mailHashHelper, $this->router);
        $mailer->setIdHash('hash');

        $email = new Email();
        $email->setSubject('Test');
        $email->setCustomHtml('<html></html>');
        $lead = new Lead();
        $lead->setEmail('someemail@email.test');
        $mailer->setIdHash('hash');
        $mailer->setEmail($email);
        $mailer->setLead($lead);

        $mailer->setEmailType(MailHelper::EMAIL_TYPE_MARKETING);
        $headers = $mailer->getCustomHeaders();

        $this->assertSame('<http://www.somedomain.cz/email/unsubscribe/hash/someemail@email.test/'.$emailSecret.'>,<mailto:list@host.com?subject=unsubscribe>', $headers['List-Unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $headers['List-Unsubscribe-Post']);
    }

    protected function mockEmptyMailHelper(): MailHelper
    {
        $transport     = new SmtpTransport();
        $symfonyMailer = new Mailer($transport);

        return new MailHelper($this->mockFactory, $symfonyMailer, $this->fromEmailHelper, $this->coreParametersHelper, $this->mailbox, $this->logger, $this->mailHashHelper, $this->router);
    }

    /**
     * @param mixed[] $parameterMap
     *
     * @phpstan-ignore-next-line as it's using a deprecated class
     *
     * @return MauticFactory&MockObject
     */
    protected function getMockFactory(bool $mailIsOwner = true, array $parameterMap = []): MauticFactory
    {
        $mockLeadRepository = $this->createMock(LeadRepository::class);

        $mockLeadRepository->method('getLeadOwner')
            ->will(
                $this->returnValueMap(
                    [
                        [1, ['id' => 1, 'email' => 'owner1@owner.com', 'first_name' => '', 'last_name' => '', 'signature' => 'owner 1']],
                        [2, ['id' => 2, 'email' => 'owner2@owner.com', 'first_name' => '', 'last_name' => '', 'signature' => 'owner 2']],
                        [3, ['id' => 3, 'email' => 'owner3@owner.com', 'first_name' => 'John', 'last_name' => 'S&#39;mith', 'signature' => 'owner 2']],
                    ]
                )
            );

        $mockLeadModel = $this->createMock(LeadModel::class);

        $mockLeadModel->method('getRepository')->willReturn($mockLeadRepository);

        $mockFactory = $this->mockFactory;

        $parameterMap = array_merge(
            [
                ['mailer_return_path', false, null],
                ['mailer_is_owner', false, $mailIsOwner],
            ],
            $parameterMap
        );

        $fromEmailHelper = new FromEmailHelper($this->createMock(CoreParametersHelper::class), $mockLeadRepository);

        $mockFactory->method('getParameter')->will($this->returnValueMap($parameterMap));
        $mockFactory->method('getModel')->will($this->returnValueMap([['lead', $mockLeadModel]]));
        $mockFactory->method('get')->will($this->returnValueMap([['mautic.helper.from_email_helper', $fromEmailHelper]]));

        $mockLogger = $this->getMockBuilder(Logger::class)
            ->disableOriginalConstructor()
            ->getMock();
        $mockFactory->method('getLogger')
            ->willReturn($mockLogger);

        $mockMailboxHelper = $this->getMockBuilder(Mailbox::class)
            ->disableOriginalConstructor()
            ->getMock();
        $mockMailboxHelper->method('isConfigured')
            ->willReturn(false);

        $mockFactory->method('getHelper')
            ->will(
                $this->returnValueMap(
                    [
                        ['mailbox', $mockMailboxHelper],
                    ]
                )
            );

        return $mockFactory;
    }

    public function testArrayOfAddressesAreRemappedIntoEmailToNameKeyValuePair(): void
    {
        $mockFactory = $this->mockFactory;
        $mockFactory->method('getParameter')
            ->will(
                $this->returnValueMap(
                    [
                        ['mailer_return_path', false, null],
                    ]
                )
            );

        $symfonyMailer = new Mailer(new SmtpTransport());
        $mailer        = new MailHelper($this->mockFactory, $symfonyMailer, $this->fromEmailHelper, $this->coreParametersHelper, $this->mailbox, $this->logger, $this->mailHashHelper, $this->router);

        $mailer->setTo(['sombody@somewhere.com', 'sombodyelse@somewhere.com'], 'test');

        $emailsTo = [];

        foreach ($mailer->message->getTo() as $address) {
            $emailsTo[$address->getAddress()] = $address->getName();
        }
        $this->assertEquals(
            [
                'sombody@somewhere.com'     => 'test',
                'sombodyelse@somewhere.com' => 'test',
            ],
            $emailsTo
        );
    }

    /**
     * @dataProvider minifyHtmlDataProvider
     */
    public function testMinifyHtml(bool $minifyHtml, string $html, string $expectedHtml): void
    {
        $params = [
            ['mailer_from_email', null, 'nobody@nowhere.com'],
        ];
        $params[] = ['minify_email_html', null, $minifyHtml];
        $params[] = ['mailer_is_owner', null, false];
        $params[] = ['mailer_append_tracking_pixel', null, false];
        $this->coreParametersHelper->method('get')->will($this->returnValueMap($params));
        $symfonyMailer = new Mailer(new SmtpTransport());
        $mailer        = new MailHelper($this->mockFactory, $symfonyMailer, $this->fromEmailHelper, $this->coreParametersHelper, $this->mailbox, $this->logger, $this->mailHashHelper, $this->router);

        $mailer->addTo($this->contacts[0]['email']);

        $email = new Email();
        $email->setCustomHtml($html);
        $email->setSubject('Subject');
        $mailer->setEmail($email);
        $this->assertSame($expectedHtml, $mailer->getBody(), $mailer->getBody());
    }

    /**
     * @return array<array<bool|int|string>>
     */
    public static function minifyHtmlDataProvider(): array
    {
        return [
            [false, self::MINIFY_HTML, self::MINIFY_HTML],
            [true, self::MINIFY_HTML, InputHelper::minifyHTML(self::MINIFY_HTML)],
        ];
    }

    public function testHeadersAreTokenized(): void
    {
        $this->coreParametersHelper->method('get')
            ->will(
                $this->returnValueMap(
                    [
                        ['mailer_custom_headers', [], ['X-Mautic-Test-1' => '{tracking_pixel}']],
                        ['mailer_reply_to_email', false, '{tracking_pixel}'],
                        ['mailer_from_email', null, 'nobody@nowhere.com'],
                        ['mailer_from_name', null, 'No Body'],
                    ]
                )
            );

        $smtpMailHelper = new MailHelper($this->mockFactory, new Mailer(new SmtpTransport()), $this->fromEmailHelper, $this->coreParametersHelper, $this->mailbox, $this->logger, $this->mailHashHelper, $this->router);
        $smtpMailHelper->addTo($this->contacts[0]['email']);

        $email = new Email();
        $email->setSubject('Test');
        $email->setCustomHtml('content');
        $email->setHeaders(['X-Mautic-Test-2' => '{tracking_pixel}']);
        $smtpMailHelper->setEmail($email);
        $smtpMailHelper->send();

        /** @var iterable<MailboxListHeader> $headers */
        $headers = $smtpMailHelper->message->getHeaders()->all();

        $realHeaders = [];
        foreach ($headers as $header) {
            $realHeaders[$header->getName()] = $header->getBodyAsString();
        }

        self::assertSame(
            $realHeaders,
            [
                'To'                    => 'contact1@somewhere.com',
                'From'                  => 'No Body <nobody@nowhere.com>',
                'Reply-To'              => 'nobody@nowhere.com',
                'Subject'               => 'Test',
                'X-Mautic-Test-2'       => MailHelper::getBlankPixel(),
                'X-Mautic-Test-1'       => MailHelper::getBlankPixel(),
                'List-Unsubscribe'      => '<{unsubscribe_url}>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            ]
        );
    }

    public function testThatHtmlIsCorrectlyProcessedWhenTheAreEmbeddedImages(): void
    {
        $this->coreParametersHelper->method('get')
            ->will(
                $this->returnValueMap(
                    [
                        ['mailer_from_email', null, 'nobody@nowhere.com'],
                        ['mailer_convert_embed_images', null, true],
                        ['mailer_append_tracking_pixel', null, true],
                        ['mailer_from_name', null, 'Test'],
                        ['mailer_append_tracking_pixel', null, true],
                        ['secret_key', null, 'secret'],
                        ['site_url', null, 'https://mautic.url'],
                    ]
                )
            );

        $this->router->method('generate')->willReturn('http://tracking.url');

        $mailer = new MailHelper($this->mockFactory, new Mailer(new BatchTransport()), $this->fromEmailHelper, $this->coreParametersHelper, $this->mailbox, $this->logger, $this->mailHashHelper, $this->router);
        $email  = new Email();

        // We should use a local image to avoid network requests.
        $sampleImagePath = __DIR__.'/../../../../assets/images/avatar.svg';

        $mailer->setIdHash('IDHASH');
        $email->setSubject('Test');
        $email->setCustomHtml('<img src="'.$sampleImagePath.'"> <img src="'.$sampleImagePath.'">');
        $mailer->setEmail($email);

        foreach ($this->contacts as $contact) {
            $mailer->addTo($contact['email']);
            $mailer->setLead($contact);
            $mailer->send();
        }

        $mailer->send();
        $reflectionMailerObject = new \ReflectionObject($mailer);
        $messageProperty        = $reflectionMailerObject->getProperty('message');
        $mauticMessage          = $messageProperty->getValue($mailer);
        \assert($mauticMessage instanceof MauticMessage);

        $body = $mauticMessage->getHtmlBody();

        $this->assertStringContainsString('<img height="1" width="1" src="http://tracking.url" alt="" />', $body);
        $this->assertSame(2, substr_count($body, 'cid:'));
    }

    public function testThatWeDontEmbedAlreadyEmbeddedImages(): void
    {
        $this->coreParametersHelper->method('get')
            ->will(
                $this->returnValueMap(
                    [
                        ['mailer_from_email', null, 'nobody@nowhere.com'],
                        ['mailer_convert_embed_images', null, true],
                        ['mailer_append_tracking_pixel', null, true],
                        ['mailer_from_name', null, 'Test'],
                        ['mailer_append_tracking_pixel', null, true],
                        ['secret_key', null, 'secret'],
                    ]
                )
            );

        $this->router->method('generate')->willReturn('http://tracking.url');

        $mailer = new MailHelper($this->mockFactory, new Mailer(new BatchTransport()), $this->fromEmailHelper, $this->coreParametersHelper, $this->mailbox, $this->logger, $this->mailHashHelper, $this->router);
        $email  = new Email();

        $email->setUseOwnerAsMailer(false);
        $email->setFromName('Test');
        $email->setSubject('Test');
        $email->setCustomHtml('<img src="cid:abcdefg">');
        $mailer->setEmail($email);

        foreach ($this->contacts as $contact) {
            $mailer->addTo($contact['email']);
            $mailer->setLead($contact);
            $mailer->send();
        }

        $mailer->send();
        $reflectionMailerObject = new \ReflectionObject($mailer);
        $messageProperty        = $reflectionMailerObject->getProperty('message');
        $mauticMessage          = $messageProperty->getValue($mailer);
        \assert($mauticMessage instanceof MauticMessage);

        $body = $mauticMessage->getHtmlBody();

        $this->assertStringContainsString('<img src="cid:abcdefg">', $body);
    }
}
