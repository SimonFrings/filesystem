<?php

namespace React\Filesystem\Node;

use React\Filesystem\AdapterInterface;
use React\Promise\Deferred;
use React\Promise\FulfilledPromise;

class Directory implements DirectoryInterface, GenericOperationInterface
{

    use GenericOperationTrait;

    protected $typeClassMapping = [
        EIO_DT_DIR => '\React\Filesystem\Node\Directory',
        EIO_DT_REG => '\React\Filesystem\Node\File',
    ];

    protected $recursiveInvoker;

    /**
     * @return RecursiveInvoker
     */
    protected function getRecursiveInvoker()
    {
        if ($this->recursiveInvoker instanceof RecursiveInvoker) {
            return $this->recursiveInvoker;
        }

        $this->recursiveInvoker = new RecursiveInvoker($this);
        return $this->recursiveInvoker;
    }

    /**
     * @param $path
     * @param AdapterInterface $filesystem
     * @param RecursiveInvoker $recursiveInvoker
     */
    public function __construct($path, AdapterInterface $filesystem, RecursiveInvoker $recursiveInvoker = null)
    {
        $this->path = $path;
        $this->filesystem = $filesystem;
        $this->recursiveInvoker = $recursiveInvoker;
    }

    /**
     * {@inheritDoc}
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * {@inheritDoc}
     */
    public function ls()
    {
        $deferred = new Deferred();

        $this->filesystem->ls($this->path)->then(function ($result) use ($deferred) {
            $this->filesystem->getLoop()->futureTick(function () use ($result, $deferred) {
                $deferred->resolve($this->processLsContents($result));
            });
        }, function ($error) use ($deferred) {
            $deferred->reject($error);
        });

        return $deferred->promise();
    }

    /**
     * @param $result
     * @return array
     */
    protected function processLsContents($result)
    {
        $list = [];
        if (isset($result['dents'])) {
            foreach ($result['dents'] as $entry) {
                if (isset($this->typeClassMapping[$entry['type']])) {
                    $path = $this->path . DIRECTORY_SEPARATOR . $entry['name'];
                    $list[$entry['name']] = new $this->typeClassMapping[$entry['type']]($path, $this->filesystem);
                }
            }
        }
        return $list;
    }

    /**
     * {@inheritDoc}
     */
    public function size($recursive = false)
    {
        $deferred = new Deferred();

        $this->ls()->then(function ($result) use ($deferred, $recursive) {
            $this->filesystem->getLoop()->futureTick(function () use ($result, $deferred, $recursive) {
                $this->processSizeContents($result, $recursive)->then(function ($numbers) use ($deferred) {
                    $deferred->resolve($numbers);
                });
            });
        }, function ($error) use ($deferred) {
            $deferred->reject($error);
        });

        return $deferred->promise();
    }

    /**
     * @param $nodes
     * @param $recursive
     * @return \React\Promise\Promise
     */
    protected function processSizeContents($nodes, $recursive)
    {
        $deferred = new Deferred();
        $numbers = [
            'directories' => 0,
            'files' => 0,
            'size' => 0,
        ];

        $promises = [];
        foreach ($nodes as $node) {
            switch (true) {
                case $node instanceof Directory:
                    $numbers['directories']++;
                    if ($recursive) {
                        $promises[] = $node->size()->then(function ($size) use (&$numbers) {
                            $numbers['directories'] += $size['directories'];
                            $numbers['files'] += $size['files'];
                            $numbers['size'] += $size['size'];
                            return new FulfilledPromise();
                        });
                    }
                    break;
                case $node instanceof File:
                    $numbers['files']++;
                    $promises[] = $node->size()->then(function ($size) use (&$numbers) {
                        $numbers['size'] += $size;
                        return new FulfilledPromise();
                    });
                    break;
            }
        }

        \React\Promise\all($promises)->then(function () use ($deferred, &$numbers) {
            $deferred->resolve($numbers);
        });

        return $deferred->promise();
    }

    /**
     * {@inheritDoc}
     */
    public function create()
    {
        return $this->filesystem->mkdir($this->path);
    }

    /**
     * {@inheritDoc}
     */
    public function remove()
    {
        return $this->filesystem->rmdir($this->path);
    }

    /**
     * {@inheritDoc}
     */
    public function createRecursive()
    {
        $deferred = new Deferred();

        $parentPath = explode(DIRECTORY_SEPARATOR, $this->path);
        array_pop($parentPath);
        $parentPath = implode(DIRECTORY_SEPARATOR, $parentPath);

        $parentDirectory = new Directory($parentPath, $this->filesystem);
        $parentDirectory->stat()->then(null, function () use ($parentDirectory, $deferred) {
            return $parentDirectory->createRecursive();
        })->then(function () use ($deferred) {
            return $this->create();
        })->then(function () use ($deferred) {
            $deferred->resolve();
        });

        return $deferred->promise();
    }

    /**
     * {@inheritDoc}
     */
    public function chmodRecursive($mode)
    {
        return $this->getRecursiveInvoker()->execute('chmod', [$mode]);
    }

    /**
     * {@inheritDoc}
     */
    public function chownRecursive($uid = -1, $gid = -1)
    {
        return $this->getRecursiveInvoker()->execute('chown', [$uid, $gid]);
    }

    /**
     * {@inheritDoc}
     */
    public function removeRecursive()
    {
        return $this->getRecursiveInvoker()->execute('remove', []);
    }

    /**
     * {@inheritDoc}
     */
    public function sizeRecursive()
    {
        return $this->size(true);
    }

    /**
     * {@inheritDoc}
     */
    public function lsRecursive(\SplObjectStorage $list = null)
    {
        if ($list === null) {
            $list = new \SplObjectStorage();
        }
        return $this->ls()->then(function ($nodes) use ($list) {
            return $this->processLsRecursiveContents($nodes, $list);
        });
    }

    /**
     * @param $nodes
     * @param $list
     * @return \React\Promise\Promise
     */
    protected function processLsRecursiveContents($nodes, $list)
    {
        $promises = [];
        foreach ($nodes as $node) {
            if ($node instanceof Directory || $node instanceof File) {
                $list->attach($node);
            }
            if ($node instanceof Directory) {
                $promises[] = $node->lsRecursive($list);
            }
        }

        $deferred = new Deferred();

        \React\Promise\all($promises)->then(function () use ($deferred, $list) {
            $deferred->resolve($list);
        });

        return $deferred->promise();
    }
}
