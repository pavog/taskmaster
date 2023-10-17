<?php

namespace Aternos\Taskmaster;

use Aternos\Taskmaster\Communication\Socket\SelectableSocketInterface;
use Aternos\Taskmaster\Communication\Socket\SocketInterface;
use Aternos\Taskmaster\Environment\Fork\ForkWorker;
use Aternos\Taskmaster\Environment\Process\ProcessWorker;
use Aternos\Taskmaster\Environment\Sync\SyncWorker;
use Aternos\Taskmaster\Proxy\ProcessProxy;
use Aternos\Taskmaster\Proxy\ProxyInterface;
use Aternos\Taskmaster\Task\TaskFactoryInterface;
use Aternos\Taskmaster\Task\TaskInterface;
use Aternos\Taskmaster\Worker\SocketWorkerInterface;
use Aternos\Taskmaster\Worker\WorkerInterface;
use Aternos\Taskmaster\Worker\WorkerStatus;

/**
 * Class Taskmaster
 *
 * The Taskmaster class is the main class of the Taskmaster library,
 * it manages the workers and tasks and assigns tasks to workers.
 *
 * @package Aternos\Taskmaster
 */
class Taskmaster
{
    /**
     * Time to wait in microseconds for new updates
     * Also used as timeout for {@link stream_select()}, e.g. in {@link Taskmaster::waitForNewUpdate()}
     *
     * @var int
     */
    public const SOCKET_WAIT_TIME = 1000;

    /**
     * @var TaskInterface[]
     */
    protected array $tasks = [];

    /**
     * @var WorkerInterface[]
     */
    protected array $workers = [];

    /**
     * @var ProxyInterface[]
     */
    protected array $proxies = [];

    /**
     * @var TaskFactoryInterface[]
     */
    protected array $taskFactories = [];

    protected TaskmasterOptions $options;

    /**
     * Taskmaster constructor
     */
    public function __construct()
    {
        $this->options = new TaskmasterOptions();
    }

    /**
     * Add a task to the task list
     *
     * When using a task factory, the task will only be executed after
     * the task factory has stopped creating tasks.
     *
     * @param TaskInterface ...$task
     * @return $this
     */
    public function addTask(TaskInterface ...$task): static
    {
        foreach ($task as $t) {
            $this->tasks[] = $t;
        }
        return $this;
    }

    /**
     * Add a task factory
     *
     * Task factories are used to create tasks on demand, e.g. when a task is finished.
     * Task factories are requested for new tasks in the order they are added.
     * Only if the first task factory does not return a task, the next task factory is requested.
     *
     * @param TaskFactoryInterface $taskFactory
     * @return $this
     */
    public function addTaskFactory(TaskFactoryInterface $taskFactory): static
    {
        $this->taskFactories[] = $taskFactory;
        return $this;
    }

    /**
     * Run the update cycle until all tasks are finished
     *
     * @return $this
     */
    public function wait(): static
    {
        do {
            $this->update();
        } while ($this->isWorking());
        return $this;
    }

    /**
     * Run the update cycle until all tasks are assigned to a worker
     *
     * This can be used to add new tasks if necessary.
     * This cannot be used with task factories.
     * It's still necessary to wait for all tasks to finish later, e.g. with {@link Taskmaster::wait()}.
     *
     * @return $this
     */
    public function waitUntilAllTasksAreAssigned(): static
    {
        do {
            $this->update();
        } while (count($this->getTasks()) > 0);
        return $this;
    }

    /**
     * Stop all workers and proxies
     *
     * Has to be called after all tasks are done, e.g. after {@link Taskmaster::wait()}.
     * Can be called earlier to kill workers and their tasks.
     *
     * @return $this
     */
    public function stop(): static
    {
        foreach ($this->workers as $worker) {
            $worker->stop();
        }
        foreach ($this->proxies as $proxy) {
            $proxy->stop();
        }
        return $this;
    }

    /**
     * Check if there are still workers working
     *
     * If you are running the update cycle manually, you can use this as break condition.
     *
     * @return bool
     */
    public function isWorking(): bool
    {
        return count($this->getWorkingWorkers()) > 0;
    }

    /**
     * Get all workers that are currently working
     *
     * @return array
     */
    protected function getWorkingWorkers(): array
    {
        $runningWorkers = [];
        foreach ($this->workers as $worker) {
            if ($worker->getStatus() === WorkerStatus::WORKING) {
                $runningWorkers[] = $worker;
            }
        }
        return $runningWorkers;
    }

    /**
     * Update workers and proxies, e.g. assign tasks, read sockets, handle requests etc.
     *
     * This method has to be called in a loop to keep the workers and proxies running
     * as done by {@link Taskmaster::wait()} or {@link Taskmaster::waitUntilAllTasksAreAssigned()}.
     *
     * This method also waits a little bit if there are no workers that need to be updated to reduce CPU load.
     *
     * @return void
     */
    public function update(): void
    {
        foreach ($this->workers as $worker) {
            $this->assignNextTaskToWorkerIfPossible($worker);
            $worker->update();
            $this->assignNextTaskToWorkerIfPossible($worker);
        }
        foreach ($this->proxies as $proxy) {
            $proxy->update();
        }
        $this->waitForNewUpdate();
    }

    /**
     * Check if a worker is available and assign the next task to it if possible
     *
     * @param WorkerInterface $worker
     * @return void
     */
    protected function assignNextTaskToWorkerIfPossible(WorkerInterface $worker): void
    {
        if ($worker->getStatus() !== WorkerStatus::AVAILABLE) {
            return;
        }
        $task = $this->getNextTask($worker->getGroup());
        if (!$task) {
            return;
        }
        $worker->assignTask($task);
    }

    /**
     * Wait a little bit if there are no workers that need to be updated to reduce CPU load
     *
     * Uses {@link stream_select()} to wait for new data on worker sockets if possible.
     * Doesn't wait if there are only sync workers.
     *
     * @return void
     */
    protected function waitForNewUpdate(): void
    {
        if ($this->hasOnlySyncWorkers()) {
            return;
        }
        $time = Taskmaster::SOCKET_WAIT_TIME;
        $streams = $this->getSelectableStreams();
        if (count($streams) === 0) {
            usleep($time);
            return;
        }
        stream_select($streams, $write, $except, 0, $time);
    }

    /**
     * Check if there are only sync workers
     *
     * @return bool
     */
    protected function hasOnlySyncWorkers(): bool
    {
        foreach ($this->workers as $worker) {
            if (!$worker instanceof SyncWorker) {
                return false;
            }
        }
        return true;
    }

    /**
     * Get all streams that can be used with {@link stream_select()} from workers and proxies
     *
     * @return resource[]
     */
    protected function getSelectableStreams(): array
    {
        $streams = [];
        foreach ($this->workers as $worker) {
            if (!$worker instanceof SocketWorkerInterface) {
                continue;
            }
            $socket = $worker->getSocket();
            if ($stream = $this->getSelectableReadStreamFromSocket($socket)) {
                $streams[] = $stream;
            }
        }
        foreach ($this->proxies as $proxy) {
            $socket = $proxy->getSocket();
            if ($stream = $this->getSelectableReadStreamFromSocket($socket)) {
                $streams[] = $stream;
            }
        }
        return $streams;
    }

    /**
     * Get a stream that can be used with {@link stream_select()} from a socket
     *
     * A socket has to implement the SelectableSocketInterface and return
     * a valid stream from getSelectableReadStream().
     *
     * @param SocketInterface|null $socket
     * @return resource|null
     */
    protected function getSelectableReadStreamFromSocket(?SocketInterface $socket): mixed
    {
        if (!$socket) {
            return null;
        }
        if (!$socket instanceof SelectableSocketInterface) {
            return null;
        }
        $stream = $socket->getSelectableReadStream();
        if (!is_resource($stream) || feof($stream)) {
            return null;
        }
        return $stream;
    }

    /**
     * Set all workers, replacing existing workers
     *
     * @param WorkerInterface[] $workers
     * @return $this
     */
    public function setWorkers(array $workers): static
    {
        $this->workers = [];
        foreach ($workers as $worker) {
            $this->addWorker($worker);
        }
        return $this;
    }

    /**
     * Add a worker
     *
     * If the worker has a proxy, the proxy will be started if it's not running yet and added to the proxy list.
     * The worker also gets this taskmaster instance assigned.
     *
     * @param WorkerInterface $worker
     * @return $this
     */
    public function addWorker(WorkerInterface $worker): static
    {
        $proxy = $worker->getProxy();
        if ($proxy && !in_array($proxy, $this->proxies, true)) {
            if (!$proxy->isRunning()) {
                $proxy->setOptions($this->options);
                $proxy->start();
            }
            $this->proxies[] = $proxy;
        }

        $worker->setTaskmaster($this);
        $this->workers[] = $worker;
        return $this;
    }

    /**
     * Add a worker multiple times by cloning it
     *
     * The worker should not be running yet.
     * Only clones of the worker will be added, the original worker will not be added.
     *
     * @param WorkerInterface $worker
     * @param int $count
     * @return $this
     */
    public function addWorkers(WorkerInterface $worker, int $count): static
    {
        for ($i = 0; $i < $count; $i++) {
            $this->addWorker(clone $worker);
        }
        return $this;
    }

    /**
     * Automatically detect extensions and add workers accordingly
     *
     * Currently only pcntl is detected and used to add fork workers with process workers as fallback.
     *
     * @param int $count
     * @return $this
     */
    public function autoDetectWorkers(int $count): static
    {
        if (extension_loaded("pcntl")) {
            return $this->addWorkers(new ForkWorker(), $count);
        }
        if (getenv("TASKMASTER_PROXY_FORK")) {
            $proxy = new ProcessProxy();
            return $this->addWorkers((new ForkWorker())->setProxy($proxy), $count);
        }
        return $this->addWorkers(new ProcessWorker(), $count);
    }

    /**
     * Get the next task from a task factory or the task list
     *
     * If a group is specified, only tasks with this group will be returned.
     * Task factories are requested first in the order they are added.
     * Tasks from the task list are only returned if no task factory returns a task.
     *
     * @param string|null $group
     * @return TaskInterface|null
     */
    protected function getNextTask(?string $group = null): ?TaskInterface
    {
        foreach ($this->taskFactories as $taskFactory) {
            $groups = $taskFactory->getGroups();
            if (is_array($groups) && !in_array($group, $groups)) {
                continue;
            }
            $task = $taskFactory->createNextTask($group);
            if ($task) {
                return $task;
            }
        }
        foreach ($this->tasks as $i => $task) {
            if ($task->getGroup() !== $group) {
                continue;
            }
            unset($this->tasks[$i]);
            return $task;
        }
        return null;
    }

    /**
     * Set the bootstrap file, e.g. the composer autoload file
     *
     * If no bootstrap file is set, Taskmaster will try to find the composer autoload file,
     * see {@link TaskmasterOptions::autoDetectBootstrap()}
     *
     * @param string|null $bootstrap
     * @return $this
     */
    public function setBootstrap(?string $bootstrap): static
    {
        $this->options->setBootstrap($bootstrap);
        return $this;
    }

    /**
     * Set the PHP executable
     *
     * If no executable is set, just "php" is used which should follow the PATH environment variable.
     *
     * @param string $phpExecutable
     * @return $this
     */
    public function setPhpExecutable(string $phpExecutable): static
    {
        $this->options->setPhpExecutable($phpExecutable);
        return $this;
    }

    /**
     * Get the current {@link TaskmasterOptions} instance
     *
     * @return TaskmasterOptions
     */
    public function getOptions(): TaskmasterOptions
    {
        return $this->options;
    }

    /**
     * Get all queued tasks from the task list
     *
     * This will not return any tasks from task factories.
     * Tasks are removed from the task list when they are assigned to a worker.
     * Therefore, you have to keep track of the tasks yourself if you want to get the results later.
     *
     * @return TaskInterface[]
     */
    public function getTasks(): array
    {
        return $this->tasks;
    }
}