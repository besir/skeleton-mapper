<?php

/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license. For more information, see
 * <http://www.doctrine-project.org>.
 */

namespace Doctrine\SkeletonMapper;

use Doctrine\Common\EventManager;
use Doctrine\SkeletonMapper\Event\LifecycleEventArgs;
use Doctrine\SkeletonMapper\Event\PreFlushEventArgs;
use Doctrine\SkeletonMapper\Events;
use Doctrine\SkeletonMapper\Persister\ObjectAction;
use Doctrine\SkeletonMapper\Persister\ObjectPersisterFactory;
use Doctrine\SkeletonMapper\Persister\ObjectPersisterInterface;
use Doctrine\SkeletonMapper\Repository\ObjectRepositoryFactory;

/**
 * Class for managing the persistence of objects.
 *
 * @author Jonathan H. Wage <jonwage@gmail.com>
 */
class UnitOfWork
{
    /**
     * @var \Doctrine\SkeletonMapper\ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var \Doctrine\SkeletonMapper\Repository\ObjectRepositoryFactory
     */
    private $objectRepositoryFactory;

    /**
     * @var \Doctrine\SkeletonMapper\Persister\ObjectPersisterFactory
     */
    private $objectPersisterFactory;

    /**
     * @var \Doctrine\SkeletonMapper\ObjectIdentityMap
     */
    private $objectIdentityMap;

    /**
     * @var \Doctrine\Common\EventManager
     */
    private $eventManager;

    /**
     * @var array
     */
    private $objectsToPersist = array();

    /**
     * @var array
     */
    private $objectsToUpdate = array();

    /**
     * @var array
     */
    private $objectsToRemove = array();

    /**
     * @var array
     */
    private $objectActionsToExecute = array();

    /**
     * @param \Doctrine\SkeletonMapper\ObjectManagerInterface             $objectManager
     * @param \Doctrine\SkeletonMapper\Repository\ObjectRepositoryFactory $objectRepositoryFactory
     * @param \Doctrine\SkeletonMapper\Persister\ObjectPersisterFactory   $objectPersisterFactory
     * @param \Doctrine\SkeletonMapper\ObjectIdentityMap                  $objectIdentityMap
     * @param \Doctrine\Common\EventManager                               $eventManager
     */
    public function __construct(
        ObjectManagerInterface $objectManager,
        ObjectRepositoryFactory $objectRepositoryFactory,
        ObjectPersisterFactory $objectPersisterFactory,
        ObjectIdentityMap $objectIdentityMap,
        EventManager $eventManager)
    {
        $this->objectManager = $objectManager;
        $this->objectRepositoryFactory = $objectRepositoryFactory;
        $this->objectPersisterFactory = $objectPersisterFactory;
        $this->objectIdentityMap = $objectIdentityMap;
        $this->eventManager = $eventManager;
    }

    /**
     * @return \Doctrine\Common\EventManager
     */
    public function getEventManager()
    {
        return $this->eventManager;
    }

    /**
     * @param object $object
     */
    public function merge($object)
    {
        $this->objectRepositoryFactory
            ->getRepository(get_class($object))
            ->merge($object);
    }

    /**
     * @param object $object
     */
    public function persist($object)
    {
        if ($this->eventManager->hasListeners(Events::prePersist)) {
            $this->eventManager->dispatchEvent(
                Events::prePersist,
                new LifecycleEventArgs($object, $this->objectManager)
            );
        }

        $this->objectsToPersist[spl_object_hash($object)] = $object;
    }

    /**
     * @param object $object The instance to update
     */
    public function update($object)
    {
        if ($this->eventManager->hasListeners(Events::preUpdate)) {
            $this->eventManager->dispatchEvent(
                Events::preUpdate,
                new LifecycleEventArgs($object, $this->objectManager)
            );
        }

        $this->objectsToUpdate[spl_object_hash($object)] = $object;
    }

    /**
     * @param object $object The object instance to remove.
     */
    public function remove($object)
    {
        if ($this->eventManager->hasListeners(Events::preRemove)) {
            $this->eventManager->dispatchEvent(
                Events::preRemove,
                new LifecycleEventArgs($object, $this->objectManager)
            );
        }

        $this->objectsToRemove[spl_object_hash($object)] = $object;
    }

    /**
     * @param ObjectAction $objectAction The object instance to execute the action for.
     */
    public function action(ObjectAction $objectAction)
    {
        $this->objectActionsToExecute[] = $objectAction;
    }

    /**
     * @param string|null $objectName
     */
    public function clear($objectName = null)
    {
        $this->objectIdentityMap->clear($objectName);

        $this->objectsToPersist = array();
        $this->objectsToUpdate = array();
        $this->objectsToRemove = array();
        $this->objectActionsToExecute = array();

        if ($this->eventManager->hasListeners(Events::onClear)) {
            $this->eventManager->dispatchEvent(
                Events::onClear,
                new Event\OnClearEventArgs($this->objectManager, $objectName)
            );
        }
    }

    /**
     * @param object $object
     */
    public function detach($object)
    {
        $this->objectIdentityMap->detach($object);
    }

    /**
     * @param object $object
     */
    public function refresh($object)
    {
        $this->objectRepositoryFactory
            ->getRepository(get_class($object))
            ->refresh($object);
    }

    /**
     * @param object $object
     */
    public function contains($object)
    {
        return $this->objectIdentityMap->contains($object)
            || $this->isScheduledForPersist($object);
    }

    /**
     * Commit the contents of the unit of work.
     */
    public function commit()
    {
        if ($this->eventManager->hasListeners(Events::preFlush)) {
            $this->eventManager->dispatchEvent(
                Events::preFlush,
                new Event\PreFlushEventArgs($this->objectManager)
            );
        }

        if (! ($this->objectActionsToExecute ||
            $this->objectsToPersist ||
            $this->objectsToUpdate ||
            $this->objectsToRemove)
        ) {
            return; // Nothing to do.
        }

        if ($this->eventManager->hasListeners(Events::onFlush)) {
            $this->eventManager->dispatchEvent(
                Events::onFlush,
                new Event\OnFlushEventArgs($this->objectManager)
            );
        }

        if ($this->objectActionsToExecute) {
            $this->executeObjectActions();
        }

        if ($this->objectsToPersist) {
            $this->executePersists();
        }

        if ($this->objectsToUpdate) {
            $this->executeUpdates();
        }

        if ($this->objectsToRemove) {
            $this->executeRemoves();
        }

        if ($this->eventManager->hasListeners(Events::postFlush)) {
            $this->eventManager->dispatchEvent(
                Events::postFlush,
                new Event\PostFlushEventArgs($this->objectManager)
            );
        }

        $this->objectsToPersist = array();
        $this->objectsToUpdate = array();
        $this->objectsToRemove = array();
        $this->objectActionsToExecute = array();
    }

    /**
     * @param object $object
     *
     * @return bool
     */
    public function isScheduledForPersist($object)
    {
        return isset($this->objectsToPersist[spl_object_hash($object)]);
    }

    /**
     * @param object $object
     *
     * @return bool
     */
    public function isScheduledForUpdate($object)
    {
        return isset($this->objectsToUpdate[spl_object_hash($object)]);
    }

    /**
     * @param object $object
     *
     * @return bool
     */
    public function isScheduledForRemove($object)
    {
        return isset($this->objectsToRemove[spl_object_hash($object)]);
    }

    /**
     * @return void
     */
    private function executeObjectActions()
    {
        foreach ($this->objectActionsToExecute as $objectAction) {
            $this->getObjectPersister($objectAction->getObject())
                ->executeObjectAction($objectAction);
        }
    }

    /**
     * @return void
     */
    private function executePersists()
    {
        foreach ($this->objectsToPersist as $object) {
            $objectData = $this->getObjectPersister($object)
                ->persistObject($object);

            $this->objectIdentityMap->addToIdentityMap($object, $objectData);

            if ($this->eventManager->hasListeners(Events::postPersist)) {
                $this->eventManager->dispatchEvent(
                    Events::postPersist,
                    new LifecycleEventArgs($object, $this->objectManager)
                );
            }
        }
    }

    /**
     * @return void
     */
    private function executeUpdates()
    {
        foreach ($this->objectsToUpdate as $object) {
            $this->getObjectPersister($object)
                ->updateObject($object);

            if ($this->eventManager->hasListeners(Events::postUpdate)) {
                $this->eventManager->dispatchEvent(
                    Events::postUpdate,
                    new LifecycleEventArgs($object, $this->objectManager)
                );
            }
        }
    }

    /**
     * @return void
     */
    private function executeRemoves()
    {
        foreach ($this->objectsToRemove as $object) {
            $this->getObjectPersister($object)
                ->removeObject($object);

            $this->objectIdentityMap->detach($object);

            if ($this->eventManager->hasListeners(Events::postRemove)) {
                $this->eventManager->dispatchEvent(
                    Events::postRemove,
                    new LifecycleEventArgs($object, $this->objectManager)
                );
            }
        }
    }

    /**
     * @return \Doctrine\SkeletonMapper\Persister\ObjectPersisterInterface
     */
    private function getObjectPersister($object)
    {
        return $this->objectPersisterFactory
            ->getPersister(get_class($object));
    }
}
