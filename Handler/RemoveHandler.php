<?php

namespace Vich\UploaderBundle\Handler;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Vich\UploaderBundle\Event\Event;
use Vich\UploaderBundle\Event\Events;
use Vich\UploaderBundle\Mapping\PropertyMappingFactory;
use Vich\UploaderBundle\Storage\StorageInterface;
use SplObjectStorage;

/**
 * Remove handler.
 *
 * @author Kévin Gomez <contact@kevingomez.fr>
 * @author Kim Wüstkamp <kim@wuestkamp.com>
 */
class RemoveHandler extends AbstractHandler
{
    /**
     * @var EventDispatcherInterface
     */
    protected $dispatcher;

    /**
     * @var SplObjectStorage
     */
    protected $queue;

    public function __construct(PropertyMappingFactory $factory, StorageInterface $storage, EventDispatcherInterface $dispatcher)
    {
        parent::__construct($factory, $storage);

        $this->dispatcher = $dispatcher;
        $this->queue = new SplObjectStorage();
    }

    /**
     * Adds file to remove-queue (which will be removed during postFlush event).
     *
     * @param $obj
     * @param string $fieldName
     */
    public function addToQueue($obj, string $fieldName): void
    {
        $mapping = $this->getMapping($obj, $fieldName);
        $fieldNames = [$fieldName];

        if ($this->queue->contains($obj)) {
            $data = $this->queue[$obj];
            $fieldNames = array_merge($fieldNames, $data['fieldNames']);
        }

        $this->dispatch(Events::PRE_ADD_REMOVE_QUEUE, new Event($obj, $mapping));

        $this->queue->attach($obj, ['fieldNames' => $fieldNames]);

        $this->dispatch(Events::POST_ADD_REMOVE_QUEUE, new Event($obj, $mapping));
    }

    /**
     * Removes whole object or specified fieldName from remove-queue
     *
     * @param $obj
     * @param string $fieldName
     */
    public function removeFromQueue($obj, string $fieldName = null): void
    {
        if (null === $fieldName) {
            $this->queue->detach($obj);
        } else {
            foreach ($this->queue as $item) {
                $data = $this->queue->getInfo();

                if (array_key_exists($fieldName, $data['fieldNames'])) {
                    unset($data['fieldNames'][$fieldName]);
                }

                $this->queue->detach($item);
                $this->queue->attach($item, $data);
            }
        }
    }

    /**
     * Returns remove-queue
     * 
     * @return SplObjectStorage
     */
    public function getQueue()
    {
        return $this->queue;
    }

    /**
     * Removes all files in queue. Will return array of updated entities to be persisted.
     *
     * @return array list of updated entities
     */
    public function removeFilesInQueue(): array
    {
        $updatedEntities = [];

        foreach ($this->queue as $obj) {
            $data = $this->queue->getInfo();

            foreach ($data['fieldNames'] as $fieldName) {
                $this->remove($obj, $fieldName);
            }

            $updatedEntities[] = $obj;
            $this->removeFromQueue($obj);
        }

        return $updatedEntities;
    }

    /**
     * Removes file from filesystem and objects mapping.
     *
     * @param $obj
     * @param string $fieldName
     */
    public function remove($obj, string $fieldName): void
    {
        $mapping = $this->getMapping($obj, $fieldName);
        $oldFilename = $mapping->getFileName($obj);

        // nothing to remove, avoid dispatching useless events
        if (empty($oldFilename)) {
            return;
        }

        $this->dispatch(Events::PRE_REMOVE, new Event($obj, $mapping));

        $this->storage->remove($obj, $mapping);
        $mapping->erase($obj);

        $this->dispatch(Events::POST_REMOVE, new Event($obj, $mapping));
    }

    protected function dispatch(string $eventName, Event $event): void
    {
        $this->dispatcher->dispatch($eventName, $event);
    }
}
