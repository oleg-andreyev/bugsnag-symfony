<?php

namespace Bugsnag\BugsnagBundle\EventListener;

use Bugsnag\BugsnagBundle\Request\SymfonyResolver;
use Bugsnag\Client;
use Bugsnag\Report;
use InvalidArgumentException;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleExceptionEvent;
use Symfony\Component\Debug\Exception\OutOfMemoryException;
use Symfony\Component\ErrorHandler\Error\OutOfMemoryError;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

class BugsnagListener implements EventSubscriberInterface
{
    /**
     * The bugsnag client instance.
     *
     * @var \Bugsnag\Client
     */
    protected $client;

    /**
     * The request resolver instance.
     *
     * @var \Bugsnag\BugsnagBundle\Request\SymfonyResolver
     */
    protected $resolver;

    /**
     * If auto notifying is enabled.
     *
     * @var bool
     */
    protected $auto;

    /**
     * A regex that matches Symfony's OOM errors.
     *
     * @var string
     */
    private $oomRegex = '/Allowed memory size of (\d+) bytes exhausted \(tried to allocate \d+ bytes\)/';

    /**
     * Create a new bugsnag listener instance.
     *
     * @param \Bugsnag\Client                                $client
     * @param \Bugsnag\BugsnagBundle\Request\SymfonyResolver $resolver
     * @param bool                                           $auto
     *
     * @return void
     */
    public function __construct(Client $client, SymfonyResolver $resolver, $auto)
    {
        $this->client = $client;
        $this->resolver = $resolver;
        $this->auto = $auto;
    }

    /**
     * Handle an incoming request.
     *
     * @param GetResponseEvent|RequestEvent $event
     *
     * @return void
     */
    public function onKernelRequest($event)
    {
        // Compatibility with Symfony < 5 and Symfony >=5
        if (!$event instanceof GetResponseEvent && !$event instanceof RequestEvent) {
            throw new InvalidArgumentException('onKernelRequest function only accepts GetResponseEvent and RequestEvent arguments');
        }

        if ($event->getRequestType() !== HttpKernelInterface::MASTER_REQUEST) {
            return;
        }

        $this->client->setFallbackType('HTTP');

        $this->resolver->set($event->getRequest());
    }

    /**
     * Handle an http kernel exception.
     *
     * @param GetResponseForExceptionEvent|ExceptionEvent $event
     *
     * @return void
     */
    public function onKernelException($event)
    {
        $throwable = $this->resolveThrowable($event);

        if ($this->isOom($throwable)
            && $this->client->getMemoryLimitIncrease() !== null
            && preg_match($this->oomRegex, $throwable->getMessage(), $matches) === 1
        ) {
            $currentMemoryLimit = (int) $matches[1];

            ini_set('memory_limit', $currentMemoryLimit + $this->client->getMemoryLimitIncrease());
        }

        $this->sendNotify($throwable, []);
    }

    /**
     * Handle a console exception (used instead of ConsoleErrorEvent before
     * Symfony 3.3 and kept for backwards compatibility).
     *
     * @param \Symfony\Component\Console\Event\ConsoleExceptionEvent $event
     *
     * @return void
     */
    public function onConsoleException(ConsoleExceptionEvent $event)
    {
        $meta = ['status' => $event->getExitCode()];
        if ($event->getCommand()) {
            $meta['name'] = $event->getCommand()->getName();
        }
        $this->sendNotify($event->getException(), ['command' => $meta]);
    }

    /**
     * Handle a console error.
     *
     * @param \Symfony\Component\Console\Event\ConsoleErrorEvent $event
     *
     * @return void
     */
    public function onConsoleError(ConsoleErrorEvent $event)
    {
        $meta = ['status' => $event->getExitCode()];
        if ($event->getCommand()) {
            $meta['name'] = $event->getCommand()->getName();
        }
        $this->sendNotify($event->getError(), ['command' => $meta]);
    }

    /**
     * @param \Throwable $throwable
     * @param array      $meta
     *
     * @return void
     */
    private function sendNotify($throwable, $meta)
    {
        if (!$this->auto) {
            return;
        }

        $report = Report::fromPHPThrowable(
            $this->client->getConfig(),
            $throwable
        );
        $report->setUnhandled(true);
        $report->setSeverityReason([
            'type' => 'unhandledExceptionMiddleware',
            'attributes' => [
                'framework' => 'Symfony',
            ],
        ]);
        $report->setMetaData($meta);

        $this->client->notify($report);
    }

    /**
     * @param GetResponseForExceptionEvent|ExceptionEvent $event
     *
     * @return \Throwable
     */
    private function resolveThrowable($event)
    {
        // Compatibility with Symfony < 5 and Symfony >=5
        // The additional `method_exists` check is to prevent errors in Symfony 4.3
        // where the ExceptionEvent exists and is used but doesn't implement
        // the `getThrowable` method, which was introduced in Symfony 4.4
        if ($event instanceof ExceptionEvent && method_exists($event, 'getThrowable')) {
            return $event->getThrowable();
        }

        if ($event instanceof GetResponseForExceptionEvent) {
            return $event->getException();
        }

        throw new InvalidArgumentException('onKernelException function only accepts GetResponseForExceptionEvent and ExceptionEvent arguments');
    }

    /**
     * Check if this $throwable is an OOM.
     *
     * This will be represented by an "OutOfMemoryError" on Symfony 4.4+ or an
     * "OutOfMemoryException" on earlier versions.
     *
     * @param \Throwable $throwable
     *
     * @return bool
     */
    private function isOom($throwable)
    {
        return $throwable instanceof OutOfMemoryError
            || $throwable instanceof OutOfMemoryException;
    }

    public static function getSubscribedEvents()
    {
        $listeners = [
            KernelEvents::REQUEST => ['onKernelRequest', 256],
            KernelEvents::EXCEPTION => ['onKernelException', 128],
        ];

        // Added ConsoleEvents in Symfony 2.3
        if (class_exists(ConsoleEvents::class)) {
            // Added with ConsoleEvents::ERROR in Symfony 3.3 to deprecate ConsoleEvents::EXCEPTION
            if (class_exists(ConsoleErrorEvent::class)) {
                $listeners[ConsoleEvents::ERROR] = ['onConsoleError', 128];
            } else {
                $listeners[ConsoleEvents::EXCEPTION] = ['onConsoleException', 128];
            }
        }

        return $listeners;
    }
}
