<?php

namespace Aternos\Taskmaster\Worker;

use Aternos\Taskmaster\Communication\Promise\ResponsePromise;
use Aternos\Taskmaster\Communication\Request\ExecuteFunctionRequest;
use Aternos\Taskmaster\Communication\Request\RunTaskRequest;
use Aternos\Taskmaster\Communication\RequestHandlingTrait;
use Aternos\Taskmaster\Communication\Response\ErrorResponse;
use Aternos\Taskmaster\Communication\Response\ExceptionResponse;
use Aternos\Taskmaster\Communication\Response\WorkerFailedResponse;
use Aternos\Taskmaster\Communication\ResponseInterface;
use Aternos\Taskmaster\Task\TaskInterface;
use Aternos\Taskmaster\TaskmasterOptions;
use Throwable;

abstract class WorkerInstance implements WorkerInstanceInterface
{
    use RequestHandlingTrait;

    protected WorkerStatus $status = WorkerStatus::STARTING;
    protected ?TaskInterface $currentTask = null;
    protected ?ResponsePromise $currentResponsePromise = null;

    public function __construct(protected TaskmasterOptions $options)
    {
    }

    public function init(): static
    {
        $this->registerRequestHandler(ExecuteFunctionRequest::class, $this->handleExecuteFunctionRequest(...));
        return $this;
    }

    /**
     * @param ExecuteFunctionRequest $request
     * @return mixed
     */
    protected function handleExecuteFunctionRequest(ExecuteFunctionRequest $request): mixed
    {
        $function = $request->getFunction();
        $arguments = $request->getArguments();
        try {
            return $this->currentTask->$function(...$arguments);
        } catch (\Exception $exception) {
            return new ExceptionResponse($request->getRequestId(), $exception);
        }
    }

    /**
     * @param TaskInterface $task
     * @return ResponsePromise
     */
    public function runTask(TaskInterface $task): ResponsePromise
    {
        $this->status = WorkerStatus::WORKING;
        $this->currentTask = $task;
        return $this->sendRunTaskRequest(new RunTaskRequest($task));
    }

    /**
     * @param RunTaskRequest $request
     * @return ResponsePromise
     */
    protected function sendRunTaskRequest(RunTaskRequest $request): ResponsePromise
    {
        $promise = $this->sendRequest($request);
        $this->currentResponsePromise = $promise;
        $promise->then(function (ResponseInterface $response) {
            $this->status = WorkerStatus::IDLE;
            if ($response instanceof ErrorResponse) {
                $this->currentTask->handleError($response);
            } else {
                $this->currentTask->handleResult($response->getData());
            }
            $this->currentTask = null;
        })->catch(function (\Exception $exception) {
            $this->status = WorkerStatus::IDLE;
            $this->currentTask->handleError(new ExceptionResponse(0, $exception));
            $this->currentTask = null;
        });
        return $promise;
    }

    /**
     * @return WorkerStatus
     */
    public function getStatus(): WorkerStatus
    {
        return $this->status;
    }

    /**
     * @param string|null $reason
     * @return $this
     * @throws Throwable
     */
    protected function handleFail(?string $reason = null): static
    {
        $this->status = WorkerStatus::FAILED;
        $this->currentResponsePromise?->resolve(new WorkerFailedResponse($reason));
        //$this->stop();
        return $this;
    }
}