<?php

namespace Mautic\UserBundle\Tests\EventListener;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\UserBundle\EventListener\SAMLSubscriber;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Routing\Router;

class SAMLSubscriberTest extends TestCase
{
    /**
     * @var RequestEvent|MockObject
     */
    private MockObject $event;

    /**
     * @var CoreParametersHelper|MockObject
     */
    private MockObject $coreParametersHelper;

    /**
     * @var Router|MockObject
     */
    private MockObject $router;

    protected function setUp(): void
    {
        $this->event = $this->createMock(RequestEvent::class);
        $this->event->expects($this->once())
            ->method('isMainRequest')
            ->willReturn(true);

        $this->coreParametersHelper = $this->createMock(CoreParametersHelper::class);
        $this->router               = $this->createMock(Router::class);
    }

    public function testSamlRoutesAreRedirectedToDefaultLoginIfSamlIsDisabled(): void
    {
        $subscriber = new SAMLSubscriber($this->coreParametersHelper, $this->router);

        $request             = $this->createMock(Request::class);
        $request->attributes = new ParameterBag();

        $request->method('getRequestUri')
            ->willReturn('/saml/login');

        $this->event->method('getRequest')
            ->willReturn($request);

        $this->coreParametersHelper->expects($this->once())
            ->method('get')
            ->with('saml_idp_metadata')
            ->willReturn('');

        $this->router->expects($this->once())
            ->method('generate')
            ->willReturn('/s/login');

        $this->event->expects($this->once())
            ->method('setResponse')
            ->with($this->isInstanceOf(RedirectResponse::class));

        $subscriber->onKernelRequest($this->event);
    }

    public function testRedirectIsIgnoredIfSamlEnabled(): void
    {
        $subscriber = new SAMLSubscriber($this->coreParametersHelper, $this->router);

        $request             = $this->createMock(Request::class);
        $request->attributes = new ParameterBag();

        $request->method('getRequestUri')
            ->willReturn('/saml/login');

        $this->event->method('getRequest')
            ->willReturn($request);

        $this->coreParametersHelper->expects($this->once())
            ->method('get')
            ->with('saml_idp_metadata')
            ->willReturn('1');

        $this->router->expects($this->never())
            ->method('generate');

        $this->event->expects($this->never())
            ->method('setResponse');

        $subscriber->onKernelRequest($this->event);
    }
}
