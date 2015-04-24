<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Migration\Mode;

use Migration\App\SetupDeltaLog;
use Migration\App\Mode\StepList;
use Migration\App\Step\Progress;
use Migration\App\Step\RollbackInterface;
use Migration\Logger\Logger;
use Migration\Exception;

/**
 * Class Migration
 */
class Data extends AbstractMode implements \Migration\App\Mode\ModeInterface
{
    /**
     * @var SetupDeltaLog
     */
    protected $setupDeltaLog;

    /**
     * @param Progress $progress
     * @param Logger $logger
     * @param \Migration\App\Mode\StepListFactory $stepListFactory
     * @param SetupDeltaLog $setupDeltaLog
     */
    public function __construct(
        Progress $progress,
        Logger $logger,
        \Migration\App\Mode\StepListFactory $stepListFactory,
        SetupDeltaLog $setupDeltaLog
    ) {
        parent::__construct($progress, $logger, $stepListFactory);
        $this->setupDeltaLog = $setupDeltaLog;
    }

    /**
     * {@inheritdoc}
     */
    public function getUsageHelp()
    {
        return <<<USAGE

Data migration mode usage information:

Main data migration
USAGE;
    }

    /**
     * {@inheritdoc}
     */
    public function run()
    {
        /** @var StepList $steps */
        $steps = $this->stepListFactory->create(['mode' => 'data']);
        $this->runIntegrity($steps);
        $this->setupDeltalog();

        foreach ($steps->getSteps() as $stepName => $step) {
            if (empty($step['data'])) {
                continue;
            }
            $this->runData($step, $stepName);
            $this->runVolume($step, $stepName);
        }

        $this->logger->info(PHP_EOL . "Migration completed");
        return true;
    }

    /**
     * @param StepList $steps
     * @throws Exception
     * @return void
     */
    protected function runIntegrity(StepList $steps)
    {
        $result = true;
        foreach ($steps->getSteps() as $stepName => $step) {
            if (!empty($step['integrity'])) {
                $result = $this->runStage($step['integrity'], $stepName, 'integrity check') && $result;
            }
        }
        if (!$result) {
            throw new Exception('Integrity Check failed');
        }
    }

    /**
     * Setup triggers
     * @throws Exception
     * @return void
     */
    protected function setupDeltalog()
    {
        if (!$this->runStage($this->setupDeltaLog, 'Stage', 'setup triggers')) {
            throw new Exception('Setup triggers failed');
        }
    }

    /**
     * @param array $step
     * @param string $stepName
     * @throws Exception
     * @return void
     */
    protected function runData(array $step, $stepName)
    {
        if (!$this->runStage($step['data'], $stepName, 'data migration')) {
            $this->rollback($step['data'], $stepName);
            throw new Exception('Data Migration failed');
        }
    }

    /**
     * @param array $step
     * @param string $stepName
     * @throws Exception
     * @return void
     */
    protected function runVolume(array $step, $stepName)
    {
        if (empty($step['volume'])) {
            return;
        }
        if (!$this->runStage($step['volume'], $stepName, 'volume check')) {
            $this->rollback($step['data'], $stepName);
            throw new Exception('Volume Check failed');
        }
    }

    /**
     * @param RollbackInterface $stage
     * @param string $stepName
     * @return void
     */
    protected function rollback($stage, $stepName)
    {
        if ($stage instanceof RollbackInterface) {
            $this->logger->info(PHP_EOL . 'Error occurred. Rollback.');
            $this->logger->info(sprintf('%s: rollback', PHP_EOL . $stepName));
            try {
                $stage->rollback();
            } catch (\Exception $e) {
                $this->logger->error(PHP_EOL . $e->getMessage());
            }
            $this->progress->reset($stage);
            $this->logger->info(PHP_EOL . 'Please fix errors and run Migration Tool again');
        }
    }
}
