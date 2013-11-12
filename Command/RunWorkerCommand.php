<?php

namespace Supertag\Bundle\GearmanBundle\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Process\ProcessBuilder;
use Symfony\Component\Process\Process;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\ParameterBag;
use Supertag\Bundle\GearmanBundle\Event\JobFailedEvent;
use Supertag\Bundle\GearmanBundle\Event\JobBeginEvent;
use Supertag\Bundle\GearmanBundle\Event\JobEndEvent;
use Supertag\Bundle\GearmanBundle\Workload;
use GearmanWorker;
use GearmanJob;
use RuntimeException, ReflectionClass, ReflectionMethod;

class RunWorkerCommand extends ContainerAwareCommand
{
    const NAME = 'supertag:gearman:run-worker';

    /**
     * All collected gearman jobs
     *
     * @var array
     */
    private $jobs = array();

    /**
     * List of retries based on job hash
     *
     * @var \Symfony\Component\HttpFoundation\ParameterBag
     */
    public $retries;

    /**
     * Whether running in verbose mode
     *
     * @var boolean
     */
    public $verbose;

    /**
     * Current environment
     *
     * @var string
     */
    public $env;

    /**
     * {@inheritDoc}
     */
    protected function configure()
    {
        $this
            ->setName(self::NAME)
            ->setDescription('Run gearman worker')
            ->setHelp(<<<EOF
The <info>%command.name%</info> command looks for gearman job commands from all
kernel bundles and registers them as gearman jobs.

<info>php %command.full_name%</info>
<info>php %command.full_name% --env=prod</info>
EOF
        );
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        foreach ($this->getContainer()->get('kernel')->getBundles() as $bundle) {
            if (is_dir($cmdDir = $bundle->getPath().'/Command')) {
                $finder = new Finder;
                $finder->files()->in($cmdDir)->name('*Command.php');
                $prefix = $bundle->getNamespace().'\\Command';
                foreach ($finder as $file) {
                    $ns = $prefix;
                    if ($relativePath = $file->getRelativePath()) {
                        $ns .= '\\'.strtr($relativePath, '/', '\\');
                    }
                    $class = $ns.'\\'.$file->getBasename('.php');
                    $r = new ReflectionClass($class);
                    $ok = $r->isSubclassOf('Symfony\\Component\\Console\\Command\\Command')
                        && !$r->isAbstract() && !$r->getConstructor()->getNumberOfRequiredParameters()
                        && $r->implementsInterface('Supertag\\Bundle\\GearmanBundle\\Command\\GearmanJobCommandInterface');
                    // if is gearman job command
                    if ($ok) {
                        $job = new $class; // will init configure for command
                        $this->jobs[$job->getName()] = $job;
                    }
                }
            }
        }
        if (!$this->jobs) {
            throw new RuntimeException("Could not find any gearman job commands in any of registered bundles..");
        }

        $this->verbose = $input->getOption('verbose');
        $this->env = $input->getOption('env');
        $this->retries = new ParameterBag;
        $gmworker = new GearmanWorker;
        $gmworker->addServers($this->getContainer()->getParameter('supertag_gearman.servers'));

        foreach ($this->jobs as $job) {
            $this->registerJob($output, $gmworker, $job);
        }

        while ($gmworker->work()) {}
    }

    /**
     * Registers a gearman job
     *
     * @param OutputInterface $output
     * @param GearmanWorker $gmw
     * @param GearmanJobCommandInterface $job
     */
    private function registerJob(OutputInterface $output, GearmanWorker $gmw, GearmanJobCommandInterface $job)
    {
        $disp = $this->getContainer()->get('event_dispatcher');
        $gmc = $this->getContainer()->get('supertag_gearman.client');
        $pname = $gmc->getJobName($job->getName());

        $now = date('Y-m-d H:i:s');
        $output->writeLn("{$now} -> Registering job: <comment>{$job->getName()}</comment>");

        $self = $this;
        $gmw->addFunction($pname, function(GearmanJob $gmj) use ($job, $gmc, $output, $disp, $self) {
            $result = null;
            $hash = sha1($job->getName() . $gmj->workload());
            $lastOutput = $cmd = '';

            try {
                $event = new JobBeginEvent($job, $gmj->workload());
                $disp->dispatch(JobBeginEvent::NAME, $event);

                $jobArgs = unserialize($gmj->workload());
                $commandArgs = $self->prepareCommandArguments($jobArgs);
                // will validate the input arguments and options
                $input = new ArrayInput($jobArgs, $job->getDefinition());
                // convert parameters to string, console v2.2 does not have to string conversion yet
                // build job command
                $processBuilder = $self->getCommandProcessBuilder()->add($job->getName());
                array_walk($commandArgs, array($processBuilder, 'add'));
                $process = $processBuilder->getProcess();
                $cmd = $job->getName() . ' ' . implode(' ', $commandArgs);
                $output->writeLn(date('Y-m-d H:i:s') . " -> Running job command: {$cmd}");

                // output read callback
                $cb = function($type, $text) use($output, &$lastOutput) {
                    $output->writeLn($text);
                    $lastOutput .= $text;
                };
                // run the job command
                if (0 !== $process->run($cb)) {
                    throw new RuntimeException("Failed while processing..");
                }
                // cleanup retries
                $self->retries->has($hash) && $self->retries->remove($hash);
            } catch (\Exception $e) {
                $msg = "<error>Failed:</error> " . $e->getMessage() . ": <info>{$cmd}</info>. ";
                $gmj->sendFail();
                // for retries we use a specific hash to determine how many retries were
                // applied already. hash is generated from {jobName}{workload} sha-1
                $numRetriesLeft = $self->retries->has($hash) ? $self->retries->get($hash) : $job->getNumRetries();
                $msg .= "Number of retries left: <info>".($numRetriesLeft - 1)."</info>";
                $output->writeLn(date('Y-m-d H:i:s') . ' -> ' . $msg);
                if ($numRetriesLeft > 1) {
                    $self->retries->set($hash, $numRetriesLeft - 1);
                    // reschedule job, always in low priority background
                    $gmc->doLowBackground($job->getName(), new Workload(unserialize($gmj->workload())));
                } else {
                    $self->retries->remove($hash);
                    // fire an event to take some action with failed job
                    $lastOutput = 'Exception -> ' . $e->getMessage() . " with last output:\n\n" . $lastOutput;
                    $args = $self->prepareCommandArguments(unserialize($gmj->workload()), false);
                    $event = new JobFailedEvent($job, $args, $lastOutput);
                    $disp->dispatch(JobFailedEvent::NAME, $event);
                }
                return false;
            }
            $event = new JobEndEvent($job, $gmj->workload());
            $disp->dispatch(JobEndEvent::NAME, $event);

            $gmj->sendComplete(null);
            return true;
        });
    }

    public function getCommandProcessBuilder()
    {
        $pb = new ProcessBuilder();

        // PHP wraps the process in "sh -c" by default, but we need to control
        // the process directly.
        if ( ! defined('PHP_WINDOWS_VERSION_MAJOR')) {
            $pb->add('exec');
        }

        return $pb->add('php')->add($this->getContainer()->getParameter('kernel.root_dir').'/console');
    }

    public function prepareCommandArguments(array $data, $withEnv = true)
    {
        $params = array();
        $escape = function($token) {
            return preg_match('{^[\w-]+$}', $token) ? $token : escapeshellarg($token);
        };
        foreach ($data as $param => $val) {
            if ($param && '-' === $param[0]) {
                $params[] = $param . ('' != $val ? '='.$escape($val) : '');
            } else {
                $params[] = $val;
            }
        }
        if ($withEnv) {
            $params[] = '--env='.$this->env;
            if ($this->verbose) {
                $params[] = '--verbose';
            }
        }
        return $params;
    }
}
