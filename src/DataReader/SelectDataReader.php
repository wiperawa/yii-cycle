<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Cycle\DataReader;

use Closure;
use Countable;
use Cycle\ORM\Select;
use Cycle\ORM\Select\QueryBuilder;
use InvalidArgumentException;
use Spiral\Database\Query\SelectQuery;
use Spiral\Pagination\PaginableInterface;
use Yiisoft\Data\Reader\DataReaderInterface;
use Yiisoft\Data\Reader\Filter\FilterInterface;
use Yiisoft\Data\Reader\Filter\FilterProcessorInterface;
use Yiisoft\Data\Reader\Sort;
use Yiisoft\Yii\Cycle\DataReader\Cache\CachedCount;
use Yiisoft\Yii\Cycle\DataReader\Cache\CachedCollection;
use Yiisoft\Yii\Cycle\DataReader\Processor\All;
use Yiisoft\Yii\Cycle\DataReader\Processor\Any;
use Yiisoft\Yii\Cycle\DataReader\Processor\Equals;
use Yiisoft\Yii\Cycle\DataReader\Processor\GreaterThan;
use Yiisoft\Yii\Cycle\DataReader\Processor\GreaterThanOrEqual;
use Yiisoft\Yii\Cycle\DataReader\Processor\In;
use Yiisoft\Yii\Cycle\DataReader\Processor\LessThan;
use Yiisoft\Yii\Cycle\DataReader\Processor\LessThanOrEqual;
use Yiisoft\Yii\Cycle\DataReader\Processor\Like;
use Yiisoft\Yii\Cycle\DataReader\Processor\QueryBuilderProcessor;

final class SelectDataReader implements DataReaderInterface
{
    /** @var Select|SelectQuery */
    private $query;
    private ?int $limit = null;
    private ?int $offset = null;
    private ?Sort $sorting = null;
    private ?FilterInterface $filter = null;
    private CachedCount $countCache;
    private CachedCollection $itemsCache;
    private CachedCollection $oneItemCache;
    /** @var FilterProcessorInterface[]|QueryBuilderProcessor[] */
    private array $filterProcessors = [];

    /**
     * @param Select|SelectQuery $query
     */
    public function __construct($query)
    {
        if (!$query instanceof Countable) {
            throw new InvalidArgumentException(sprintf('Query should implement %s interface', Countable::class));
        }
        if (!$query instanceof PaginableInterface) {
            throw new InvalidArgumentException(
                sprintf('Query should implement %s interface', PaginableInterface::class)
            );
        }
        $this->query = clone $query;
        $this->countCache = new CachedCount($this->query);
        $this->itemsCache = new CachedCollection();
        $this->oneItemCache = new CachedCollection();
        $this->setFilterProcessors(
            new All(),
            new Any(),
            new Equals(),
            new GreaterThan(),
            new GreaterThanOrEqual(),
            new In(),
            new LessThan(),
            new LessThanOrEqual(),
            new Like(),
            // new Not()
        );
    }

    public function getSort(): ?Sort
    {
        return $this->sorting;
    }

    public function withLimit(int $limit): self
    {
        $clone = clone $this;
        $clone->setLimit($limit);
        return $clone;
    }

    public function withOffset(int $offset): self
    {
        $clone = clone $this;
        $clone->setOffset($offset);
        return $clone;
    }

    public function withSort(?Sort $sorting): self
    {
        $clone = clone $this;
        $clone->setSort($sorting);
        return $clone;
    }

    public function withFilter(FilterInterface $filter)
    {
        $clone = clone $this;
        $clone->setFilter($filter);
        return $clone;
    }

    public function withFilterProcessors(FilterProcessorInterface ...$filterProcessors)
    {
        $clone = clone $this;
        $clone->setFilterProcessors(...$filterProcessors);
        $clone->resetCountCache();
        $clone->itemsCache = new CachedCollection();
        $clone->oneItemCache = new CachedCollection();
        return $clone;
    }

    public function count(): int
    {
        return $this->countCache->getCount();
    }

    public function read(): iterable
    {
        if ($this->itemsCache->getCollection() !== null) {
            return $this->itemsCache->getCollection();
        }
        $query = $this->buildQuery();
        $this->itemsCache->setCollection($query->fetchAll());
        return $this->itemsCache->getCollection();
    }

    /**
     * @return mixed
     */
    public function readOne()
    {
        if (!$this->oneItemCache->isCollected()) {
            $item = $this->itemsCache->isCollected()
                // get first item from cached collection
                ? $this->itemsCache->getGenerator()->current()
                // read data with limit 1
                : $this->withLimit(1)->getIterator()->current();
            $this->oneItemCache->setCollection($item === null ? [] : [$item]);
        }

        return $this->oneItemCache->getGenerator()->current();
    }

    /**
     * Get Iterator without caching
     */
    public function getIterator(): \Generator
    {
        if ($this->itemsCache->getCollection() !== null) {
            yield from $this->itemsCache->getCollection();
        } else {
            yield from $this->buildQuery()->getIterator();
        }
    }

    public function __toString(): string
    {
        return $this->buildQuery()->sqlStatement();
    }

    private function setSort(?Sort $sorting): void
    {
        if ($this->sorting !== $sorting) {
            $this->sorting = $sorting;
            $this->itemsCache = new CachedCollection();
            $this->oneItemCache = new CachedCollection();
        }
    }

    private function setLimit(?int $limit): void
    {
        if ($this->limit !== $limit) {
            $this->limit = $limit;
            $this->itemsCache = new CachedCollection();
        }
    }

    private function setOffset(?int $offset): void
    {
        if ($this->offset !== $offset) {
            $this->offset = $offset;
            $this->itemsCache = new CachedCollection();
        }
    }

    private function setFilter(FilterInterface $filter): void
    {
        if ($this->filter !== $filter) {
            $this->filter = $filter;
            $this->itemsCache = new CachedCollection();
            $this->oneItemCache = new CachedCollection();
        }
    }

    private function setFilterProcessors(FilterProcessorInterface ...$filterProcessors): void
    {
        $processors = [];
        foreach ($filterProcessors as $filterProcessor) {
            if ($filterProcessor instanceof QueryBuilderProcessor) {
                $processors[$filterProcessor->getOperator()] = $filterProcessor;
            }
        }
        $this->filterProcessors = array_merge($this->filterProcessors, $processors);
    }

    /**
     * @return Select|SelectQuery
     */
    private function buildQuery()
    {
        $newQuery = clone $this->query;
        if ($this->offset !== null) {
            $newQuery->offset($this->offset);
        }
        if ($this->sorting !== null) {
            $newQuery->orderBy($this->sorting->getOrder());
        }
        if ($this->limit !== null) {
            $newQuery->limit($this->limit);
        }
        if ($this->filter !== null) {
            $newQuery->andWhere($this->makeFilterClosure());
        }
        return $newQuery;
    }
    private function makeFilterClosure(): Closure
    {
        return function (QueryBuilder $select) {
            $filter = $this->filter->toArray();
            $operation = array_shift($filter);
            $arguments = $filter;

            $processor = $this->filterProcessors[$operation] ?? null;
            if ($processor === null) {
                throw new \RuntimeException(sprintf('Filter operator "%s" is not supported.', $operation));
            }
            $select->where(...$processor->getAsWhereArguments($arguments, $this->filterProcessors));
        };
    }
    private function resetCountCache()
    {
        $newQuery = clone $this->query;
        if ($this->filter !== null) {
            $newQuery->andWhere($this->makeFilterClosure());
        }
        $this->countCache = new CachedCount($newQuery);
    }
}
