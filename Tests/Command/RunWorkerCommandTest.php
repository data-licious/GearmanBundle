<?php

namespace Supertag\Bundle\GearmanBundle\Tests\Command;

use Supertag\Bundle\GearmanBundle\Command\GearmanJobCommandInterface;
use Supertag\Bundle\GearmanBundle\Command\RunWorkerCommand;
use Symfony\Component\DependencyInjection\Container;

class RunWorkerCommandTest extends \PHPUnit_Framework_TestCase
{
    /** @test */
    public function buildProcess()
    {
        $container = new Container();
        $container->setParameter('kernel.root_dir', 'app');

        $command = new RunWorkerCommand();
        $command->setContainer($container);
        $command->env = 'test';

        $gmj = $this->getMock('\GearmanJob', array(), array(), '', false);

        $gmj
            ->expects($this->once())
            ->method('workload')
            ->will($this->returnValue(serialize(array(
                'argument',
                'argument with whitespaces',
                '-o' => 'short option',
                '-s' => 'short',
                '--option' => 'long option',
                '--short' => 'long',
                '--flag' => null,
                '--flag2' => ''
            ))))
        ;

        $process = $command->buildProcess($gmj, new DummyCommand());

        $this->assertEquals($process->getCommandLine(), "'exec' 'php' 'app/console' 'test' 'argument' 'argument with whitespaces' '-o=\"short option\"' '-s=short' '--option=\"long option\"' '--short=long' '--flag' '--flag2' '--env=test'");
    }
}

class DummyCommand implements GearmanJobCommandInterface
{

    function getName()
    {
        return 'test';
    }

    function getNumRetries()
    {
        return 5;
    }

    function getDefinition()
    {

    }
}
