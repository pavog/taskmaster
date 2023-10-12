<?php

namespace Aternos\Taskmaster\Worker;

use Aternos\Taskmaster\Communication\CommunicatorInterface;
use Aternos\Taskmaster\Communication\Promise\Promise;
use Aternos\Taskmaster\Communication\Promise\ResponsePromise;
use Aternos\Taskmaster\Task\TaskInterface;
use Aternos\Taskmaster\TaskmasterOptions;

interface WorkerInstanceInterface extends CommunicatorInterface
{
    public function __construct(TaskmasterOptions $options);

    public function init(): static;

    public function start(): Promise;

    public function getStatus(): WorkerStatus;

    public function runTask(TaskInterface $task): ResponsePromise;

    public function update(): static;

    public function stop(): static;
}