<?php

namespace Akeneo\Bundle\BatchBundle\Step;

use Symfony\Component\Validator\Constraints as Assert;
use Akeneo\Bundle\BatchBundle\Step\StepExecutionAwareInterface;
use Akeneo\Bundle\BatchBundle\Entity\StepExecution;
use Akeneo\Bundle\BatchBundle\Item\AbstractConfigurableStepElement;
use Akeneo\Bundle\BatchBundle\Item\ItemReaderInterface;
use Akeneo\Bundle\BatchBundle\Item\ItemProcessorInterface;
use Akeneo\Bundle\BatchBundle\Item\ItemWriterInterface;
use Akeneo\Bundle\BatchBundle\Item\InvalidItemException;

/**
 * Basic step implementation that read items, process them and write them
 *
 * @author    Benoit Jacquemont <benoit@akeneo.com>
 * @copyright 2013 Akeneo SAS (http://www.akeneo.com)
 * @license   http://opensource.org/licenses/MIT MIT
 */
class ItemStep extends AbstractStep
{
    /**
     * @var int
     */
    protected $batchSize = 100;

    /**
     * @Assert\Valid
     * @var ItemReaderInterface
     */
    protected $reader = null;

    /**
     * @Assert\Valid
     * @var ItemWriterInterface
     */
    protected $writer = null;

    /**
     * @Assert\Valid
     * @var ItemProcessorInterface
     */
    protected $processor = null;

    /**
     * @var StepExecution
     */
    protected $stepExecution = null;

    /**
     * Set the batch size
     *
     * @param integer $batchSize
     *
     * @return $this
     */
    public function setBatchSize($batchSize)
    {
        $this->batchSize = $batchSize;

        return $this;
    }

    /**
     * Set reader
     *
     * @param ItemReaderInterface $reader
     */
    public function setReader(ItemReaderInterface $reader)
    {
        $this->reader = $reader;
    }

    /**
     * Get reader
     *
     * @return ItemReaderInterface|null
     */
    public function getReader()
    {
        return $this->reader;
    }

    /**
     * Set writer
     * @param ItemWriterInterface $writer
     */
    public function setWriter(ItemWriterInterface $writer)
    {
        $this->writer = $writer;
    }

    /**
     * Get writer
     * @return ItemWriterInterface|null
     */
    public function getWriter()
    {
        return $this->writer;
    }

    /**
     * Set processor
     * @param ItemProcessorInterface $processor
     */
    public function setProcessor(ItemProcessorInterface $processor)
    {
        $this->processor = $processor;
    }

    /**
     * Get processor
     * @return ItemProcessorInterface|null
     */
    public function getProcessor()
    {
        return $this->processor;
    }

    /**
     * {@inheritdoc}
     */
    public function getConfiguration()
    {
        $stepElements = array(
            $this->reader,
            $this->writer,
            $this->processor
        );
        $configuration = array();

        foreach ($stepElements as $stepElement) {
            if ($stepElement instanceof AbstractConfigurableStepElement) {
                foreach ($stepElement->getConfiguration() as $key => $value) {
                    if (!isset($configuration[$key]) || $value) {
                        $configuration[$key] = $value;
                    }
                }
            }
        }

        return $configuration;
    }

    /**
     * {@inheritdoc}
     */
    public function setConfiguration(array $config)
    {
        $stepElements = array(
            $this->reader,
            $this->writer,
            $this->processor
        );

        foreach ($stepElements as $stepElement) {
            if ($stepElement instanceof AbstractConfigurableStepElement) {
                $stepElement->setConfiguration($config);
            }
        }
    }

    /**
     * Get the configurable step elements
     *
     * @return array
     */
    public function getConfigurableStepElements()
    {
        return array(
            'reader'    => $this->getReader(),
            'processor' => $this->getProcessor(),
            'writer'    => $this->getWriter()
        );
    }

    /**
     * {@inheritdoc}
     */
    public function doExecute(StepExecution $stepExecution)
    {
        $itemsToWrite  = array();
        $writeCount    = 0;

        $this->initializeStepElements($stepExecution);

        $stopExecution = false;
        while (!$stopExecution) {

            try {
                $readItem = $this->reader->read();
                if (null === $readItem) {
                    $stopExecution = true;
                    continue;
                }

            } catch (InvalidItemException $e) {
                $this->handleStepExecutionWarning($this->stepExecution, $this->reader, $e);

                continue;
            }

            $processedItem = $this->process($readItem);
            if (null !== $processedItem) {

                $itemsToWrite[] = $processedItem;
                $writeCount++;
                if (0 === $writeCount % $this->batchSize) {
                    $this->write($itemsToWrite);
                    $itemsToWrite = array();
                }
            }
        }

        if (count($itemsToWrite) > 0) {
            $this->write($itemsToWrite);
        }
        $this->flushStepElements();
    }

    /**
     * @param StepExecution $stepExecution
     */
    protected function initializeStepElements(StepExecution $stepExecution)
    {
        $this->stepExecution = $stepExecution;
        foreach ($this->getConfigurableStepElements() as $element) {
            if ($element instanceof StepExecutionAwareInterface) {
                $element->setStepExecution($stepExecution);
            }
            $element->initialize();
        }
    }

    /**
     * Flushes step elements
     */
    public function flushStepElements()
    {
        foreach ($this->getConfigurableStepElements() as $element) {
            $element->flush();
        }
    }

    /**
     * @param mixed $readItem
     *
     * @return mixed processed item
     */
    protected function process($readItem)
    {
        try {
            return $this->processor->process($readItem);

        } catch (InvalidItemException $e) {
            $this->handleStepExecutionWarning($this->stepExecution, $this->processor, $e);

            return null;
        }
    }

    /**
     * @param array $processedItems
     *
     * @return null
     */
    protected function write($processedItems)
    {
        try {
            $this->writer->write($processedItems);

        } catch (InvalidItemException $e) {
            $this->handleStepExecutionWarning($this->stepExecution, $this->writer, $e);
        }
    }

    /**
     * Handle step execution warning
     *
     * @param StepExecution                   $stepExecution
     * @param AbstractConfigurableStepElement $element
     * @param InvalidItemException            $e
     */
    protected function handleStepExecutionWarning(
        StepExecution $stepExecution,
        AbstractConfigurableStepElement $element,
        InvalidItemException $e
    ) {
        if ($element instanceof AbstractConfigurableStepElement) {
            $warningName = $element->getName();
        } else {
            $warningName = get_class($element);
        }

        $stepExecution->addWarning($warningName, $e->getMessage(), $e->getMessageParameters(), $e->getItem());
        $this->dispatchInvalidItemEvent(
            get_class($element),
            $e->getMessage(),
            $e->getMessageParameters(),
            $e->getItem()
        );
    }
}
