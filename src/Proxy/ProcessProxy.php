<?php

namespace Aternos\Taskmaster\Proxy;

use Aternos\Taskmaster\Communication\Promise\ResponsePromise;
use Aternos\Taskmaster\Communication\Request\StartWorkerInstanceRequest;
use Aternos\Taskmaster\Communication\Request\StopWorkerInstanceRequest;
use Aternos\Taskmaster\Communication\Request\TerminateRequest;
use Aternos\Taskmaster\Communication\RequestHandlingTrait;
use Aternos\Taskmaster\Communication\Socket\SocketCommunicatorTrait;
use Aternos\Taskmaster\Communication\Socket\SocketInterface;
use Aternos\Taskmaster\Runtime\RuntimeProcess;
use Aternos\Taskmaster\Taskmaster;
use Aternos\Taskmaster\Worker\Instance\ProxyableWorkerInstanceInterface;
use Throwable;

/**
 * Class ProcessProxy
 *
 * A {@link ProxyInterface} implementation for running a proxy in a separate process using {@link proc_open()}.
 * No extensions are required for this proxy, and it should be available in all environments except
 * those that explicitly block {@link proc_open()}.
 *
 * @package Aternos\Taskmaster\Proxy
 */
class ProcessProxy extends Proxy
{
    use RequestHandlingTrait;
    use SocketCommunicatorTrait;

    protected ?ProxySocketInterface $proxySocket = null;

    protected ?RuntimeProcess $process = null;

    /**
     * @inheritDoc
     * @throws Throwable
     */
    public function startWorkerInstance(ProxyableWorkerInstanceInterface $worker): ResponsePromise
    {
        return $this->sendRequest(new StartWorkerInstanceRequest($worker));
    }

    /**
     * @inheritDoc
     * @throws Throwable
     */
    public function stopWorkerInstance(ProxyableWorkerInstanceInterface $worker): ResponsePromise
    {
        return $this->sendRequest(new StopWorkerInstanceRequest($worker->getId()));
    }

    /**
     * @inheritDoc
     */
    public function getSocket(): SocketInterface
    {
        return $this->socket;
    }

    /**
     * @inheritDoc
     */
    public function start(): static
    {
        $this->process = new RuntimeProcess($this->options, ProxyRuntime::class);
        $this->proxySocket = new ProxySocket($this->process->getSocket());
        $this->socket = new ProxiedSocket($this->proxySocket, null);
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getProxySocket(): ProxySocketInterface
    {
        return $this->proxySocket;
    }

    /**
     * @inheritDoc
     * @throws Throwable
     */
    public function stop(): static
    {
        $this->sendRequest(new TerminateRequest());
        while ($this->process->isRunning()) {
            usleep(Taskmaster::SOCKET_WAIT_TIME);
        }
        return $this;
    }

    /**
     * @inheritDoc
     */
    protected function handleFail(?string $reason = null): static
    {
        // TODO: handle proxy fail
    }

    /**
     * @inheritDoc
     */
    public function isRunning(): bool
    {
        return $this->process?->isRunning() === true;
    }
}