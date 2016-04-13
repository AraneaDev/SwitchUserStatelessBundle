<?php

namespace SwitchUserStatelessBundle\Security\Firewall;

use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;
use Symfony\Component\Security\Core\Role\Role;
use Symfony\Component\Security\Core\Role\SwitchUserRole;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\Event\SwitchUserEvent;
use Symfony\Component\Security\Http\SecurityEvents;

class SwitchUserStatelessListenerTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @dataProvider handleProvider
     *
     * @param Request                        $request
     * @param TokenStorageInterface          $tokenStorage
     * @param \Exception                     $expectedException
     * @param AccessDecisionManagerInterface $accessDecisionManager
     * @param UserProviderInterface          $userProvider
     * @param UserCheckerInterface           $userChecker
     * @param LoggerInterface                $logger
     * @param EventDispatcherInterface       $eventDispatcher
     */
    public function testHandle(
        Request $request,
        TokenStorageInterface $tokenStorage,
        \Exception $expectedException = null,
        AccessDecisionManagerInterface $accessDecisionManager = null,
        UserProviderInterface $userProvider = null,
        UserCheckerInterface $userChecker = null,
        LoggerInterface $logger = null,
        EventDispatcherInterface $eventDispatcher = null
    ) {
        $userProvider = $userProvider ?: $this->getUserProviderMock()->reveal();
        $userChecker = $userChecker ?: $this->getUserCheckerMock()->reveal();
        $accessDecisionManager = $accessDecisionManager ?: $this->getAccessDecisionManagerMock()->reveal();
        $logger = $logger ?: $this->getLoggerMock()->reveal();
        $eventDispatcher = $eventDispatcher ?: $this->getEventDispatcherMock()->reveal();

        $listener = new SwitchUserStatelessListener(
            $tokenStorage,
            $userProvider,
            $userChecker,
            'myKey',
            $accessDecisionManager,
            $logger,
            'myParameter',
            'myRole',
            $eventDispatcher
        );

        $kernelMock = $this->getKernelMock();
        $event = new GetResponseEvent($kernelMock->reveal(), $request, HttpKernelInterface::MASTER_REQUEST);

        if (null === $expectedException) {
            $this->assertNull($listener->handle($event));
        } else {
            $e = null;
            try {
                $listener->handle($event);
            } catch (\Exception $e) {
            }
            $this->assertEquals($expectedException, $e);
        }
    }

    /**
     * @return array[]
     */
    public function handleProvider()
    {
        return [
            $this->getHandleDataWrongParameter(),
            $this->getHandleDataNotConnected(),
            $this->getHandleDataNotAllowedToSwitch(),
            $this->getHandleDataUnknownUser(),
            $this->getHandleDataAllowedToSwitch(),
        ];
    }

    /**
     * @return array
     */
    private function getHandleDataWrongParameter()
    {
        $request = new Request();
        $request->headers->set('wrongParameter', 'newUser');
        $tokenStorageMock = $this->getTokenStorageMock();
        $tokenStorageMock->getToken()->shouldNotBeCalled();

        return [$request, $tokenStorageMock->reveal()];
    }

    /**
     * @return array
     */
    private function getHandleDataNotConnected()
    {
        $request = new Request();
        $request->headers->set('myParameter', 'newUser');
        $tokenStorageMock = $this->getTokenStorageMock();
        $tokenStorageMock->getToken()->shouldBeCalled();

        return [$request, $tokenStorageMock->reveal(), new \RuntimeException('Token can\'t be null')];
    }

    /**
     * @return array
     */
    private function getHandleDataNotAllowedToSwitch()
    {
        $request = new Request();
        $request->headers->set('myParameter', 'newUser');

        $tokenMock = $this->getTokenMock();
        $tokenMock->getUsername()->willReturn('adminUsername')->shouldBeCalled();
        $tokenStorageMock = $this->getTokenStorageMock();
        $tokenStorageMock->getToken()->willReturn($tokenMock->reveal())->shouldBeCalled();

        $accessDecisionManagerMock = $this->getAccessDecisionManagerMock();
        $accessDecisionManagerMock->decide($tokenMock->reveal(), ['myRole'])->willReturn(false)->shouldBeCalled();

        return [
            $request,
            $tokenStorageMock->reveal(),
            new AccessDeniedException(),
            $accessDecisionManagerMock->reveal(),
        ];
    }

    /**
     * @return array
     */
    private function getHandleDataUnknownUser()
    {
        $request = new Request();
        $request->headers->set('myParameter', 'newUser');

        $tokenMock = $this->getTokenMock();
        $tokenMock->getUsername()->willReturn('adminUsername')->shouldBeCalled();
        $tokenStorageMock = $this->getTokenStorageMock();
        $tokenStorageMock->getToken()->willReturn($tokenMock->reveal())->shouldBeCalled();

        $accessDecisionManagerMock = $this->getAccessDecisionManagerMock();
        $accessDecisionManagerMock->decide($tokenMock->reveal(), ['myRole'])->willReturn(true)->shouldBeCalled();

        $userProvider = $this->getUserProviderMock();
        $exception = new UsernameNotFoundException('unknown user');
        $userProvider->loadUserByUsername('newUser')->willThrow($exception)->shouldBeCalled();

        return [
            $request,
            $tokenStorageMock->reveal(),
            new \LogicException('Switch User failed: "unknown user"'),
            $accessDecisionManagerMock->reveal(),
            $userProvider->reveal(),
        ];
    }

    /**
     * @return array
     */
    private function getHandleDataAllowedToSwitch()
    {
        $request = new Request();
        $request->headers->set('myParameter', 'newUser');

        $previousRole = new Role('MY_ROLE');
        $userMock = $this->getUserMock();
        $userMock->getRoles()->willReturn([$previousRole])->shouldBeCalled();
        $userMock->getPassword()->willReturn('myPassword')->shouldBeCalled();
        $userProvider = $this->getUserProviderMock();
        $userProvider->loadUserByUsername('newUser')->willReturn($userMock->reveal())->shouldBeCalled();
        $userChecker = $this->getUserCheckerMock();
        $userChecker->checkPostAuth($userMock->reveal())->shouldBeCalled();

        $tokenMock = $this->getTokenMock();
        $tokenMock->getUsername()->willReturn('adminUsername')->shouldBeCalled();
        $tokenStorageMock = $this->getTokenStorageMock();
        $tokenStorageMock
            ->setToken(new UsernamePasswordToken($userMock->reveal(), 'myPassword', 'myKey', [
                $previousRole,
                new SwitchUserRole('ROLE_PREVIOUS_ADMIN', $tokenMock->reveal()),
            ]))
            ->shouldBeCalled();
        $tokenStorageMock->getToken()->willReturn($tokenMock->reveal())->shouldBeCalled();

        $accessDecisionManagerMock = $this->getAccessDecisionManagerMock();
        $accessDecisionManagerMock->decide($tokenMock->reveal(), ['myRole'])->willReturn(true)->shouldBeCalled();

        $logger = $this->getLoggerMock();
        $logger
            ->info('Attempt to switch user', ['previousUsername' => 'adminUsername', 'newUsername' => 'newUser'])
            ->shouldBeCalled();

        $switchEvent = new SwitchUserEvent($request, $userMock->reveal());
        $eventDispatcher = $this->getEventDispatcherMock();
        $eventDispatcher->dispatch(SecurityEvents::SWITCH_USER, $switchEvent)->shouldBeCalled();

        return [
            $request,
            $tokenStorageMock->reveal(),
            null,
            $accessDecisionManagerMock->reveal(),
            $userProvider->reveal(),
            $userChecker->reveal(),
            $logger->reveal(),
            $eventDispatcher->reveal(),
        ];
    }

    /**
     * @return ObjectProphecy|TokenStorageInterface
     */
    private function getTokenStorageMock()
    {
        return $this->prophesize('Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface');
    }

    /**
     * @return ObjectProphecy|UserProviderInterface
     */
    private function getUserProviderMock()
    {
        return $this->prophesize('Symfony\Component\Security\Core\User\UserProviderInterface');
    }

    /**
     * @return ObjectProphecy|UserCheckerInterface
     */
    private function getUserCheckerMock()
    {
        return $this->prophesize('Symfony\Component\Security\Core\User\UserCheckerInterface');
    }

    /**
     * @return ObjectProphecy|AccessDecisionManagerInterface
     */
    private function getAccessDecisionManagerMock()
    {
        return $this->prophesize('Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface');
    }

    /**
     * @return ObjectProphecy|LoggerInterface
     */
    private function getLoggerMock()
    {
        return $this->prophesize('Psr\Log\LoggerInterface');
    }

    /**
     * @return ObjectProphecy|EventDispatcherInterface
     */
    private function getEventDispatcherMock()
    {
        return $this->prophesize('Symfony\Component\EventDispatcher\EventDispatcherInterface');
    }

    /**
     * @return ObjectProphecy|HttpKernelInterface
     */
    private function getKernelMock()
    {
        return $this->prophesize('Symfony\Component\HttpKernel\HttpKernelInterface');
    }

    /**
     * @return ObjectProphecy|TokenInterface
     */
    private function getTokenMock()
    {
        return $this->prophesize('Symfony\Component\Security\Core\Authentication\Token\TokenInterface');
    }

    /**
     * @return ObjectProphecy|UserInterface
     */
    private function getUserMock()
    {
        return $this->prophesize('Symfony\Component\Security\Core\User\UserInterface');
    }
}
