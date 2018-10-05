<?php

namespace Sensi\Api;

use Monolyth\Disclosure\Container;
use Monomelodies\Monki\Handler\Crud;
use Quibble\Transformer\Transformer;
use Psr\Http\Message\ResponseInterface;
use Quibble\Query\{ SelectException, UpdateException, DeleteException, Builder };
use Schema;

class Handler extends Crud
{
    /**
     * @param string $table The table name this ApiHandler instance will operate
     *  on.
     * @return void
     */
    public function __construct(string $table)
    {
        $this->adapter = (new Container)->get('adapter');
        $this->transform = new Transformer;
        $this->table = $table;
    }

    /**
     * @Url /count/
     * @return Psr\Http\Message\ResponseInterface
     */
    public function count() : ResponseInterface
    {
        $query = $this->adapter->selectFrom($this->table);
        if (isset($_GET['filter'])) {
            $filter = json_decode($_GET['filter'], true);
            $query = $this->applyFilter($query, $filter);
        }
        $count = $query->count();
        return $this->jsonResponse(compact('count'));
    }

    /**
     * @return Psr\Http\Message\ResponseInterface
     */
    public function browse() : ResponseInterface
    {
        try {
            $query = $this->adapter->selectFrom($this->table);
            if (isset($_GET['filter'])) {
                $filter = json_decode($_GET['filter'], true);
                $query = $this->applyFilter($query, $filter);
            }
            if (isset($_GET['options'])) {
                $options = json_decode($_GET['options']);
                if (isset($options->order)) {
                    $query->orderBy($options->order);
                }
                if (isset($options->limit, $options->offset)) {
                    $query->limit($options->limit, $options->offset);
                } elseif (isset($options->limit) && $options->limit != 'INF') {
                    $query->limit($options->limit);
                }
            }
            $result = $query->fetchAll();
        } catch (SelectException $e) {
            $result = [];
        }
        return $this->jsonResponse($this->transform->collection(
            $result,
            $this->getTransformer()
        ));
    }

    /**
     * @return Psr\Http\Message\ResponseInterface
     */
    public function create() : ResponseInterface
    {
        $data = $_POST;
        if (method_exists([Schema::class, 'post'])) {
            $data = $this->transform->resource($data, [Schema::class, 'post']);
        }
        $data = $this->removeVirtuals($data);
        $this->adapter->insertInto($this->table)
            ->execute($data);
        return $this->retrieve($this->adapter->lastInsertId($this->table));
    }

    /**
     * @param string $id
     * @return Psr\Http\Message\ResponseInterface
     */
    public function retrieve(string $id) : ResponseInterface
    {
        $query = $this->adapter->selectFrom($this->table)
            ->where('id = ?', $id);
        if (isset($_GET['filter'])) {
            $filter = json_decode($_GET['filter'], true);
            $query = $this->applyFilter($query, $filter);
        }
        if (isset($_GET['options'])) {
            $options = json_decode($_GET['options']);
            if (isset($options->order)) {
                $query->orderBy($options->order);
            }
            if (isset($options->limit, $options->offset)) {
                $query->limit($options->limit, $options->offset);
            }
        }
        try {
            $result = $query->fetch();
            return $this->jsonResponse($this->transform->resource(
                $result,
                $this->getTransformer()
            ));
        } catch (SelectException $e) {
            return $this->emptyResponse(404);
        }
    }

    /**
     * @param string $id
     * @return Psr\Http\Message\ResponseInterface
     */
    public function update(string $id) : ResponseInterface
    {
        $data = $this->transform->resource($_POST, [Schema::class, 'post']);
        $data = $this->removeVirtuals($data);
        try {
            $this->adapter->updateTable($this->table)
                ->where('id = ?', $id)
                ->execute($data);
            return $this->retrieve($id);
        } catch (UpdateException $e) {
            return $this->emptyResponse(500);
        }
    }

    /**
     * @param string $id
     * @return Psr\Http\Message\ResponseInterface
     */
    public function delete(string $id) : ResponseInterface
    {
        try {
            $result = $this->adapter->deleteFrom($this->table)
                ->where('id = ?', $id)
                ->execute();
            return $this->emptyResponse(200);
        } catch (DeleteException $e) {
            return $this->emptyResponse(500);
        }
    }

    /**
     * Apply a given filter to the passed query.
     *
     * @param Quibble\Query\Builder $query
     * @param array $filter Hash of the type ['field' => 'value']. Nested arrays
     *  cause alteration between AND/OR.
     * @param string $type 'AND' (default) or 'OR'.
     * @return Quibble\Query\Builder
     */
    protected function applyFilter(Builder $query, array $filter, string $type = 'AND') : Builder
    {
        $where = strtolower($type).'Where';
        foreach ($filter as $key => $data) {
            if (is_array($data)) {
                $query->$where([$this, 'applyFilter'], $data, $type == 'AND' ? 'OR' : 'AND');
            } else {
                $query->$where("$key = ?", $data);
            }
        }
        return $query;
    }

    /**
     * Retrieve the transformer for this table, if it exists.
     *
     * @return callable
     */
    protected function getTransformer()
    {
        if (method_exists(Schema::class, $this->table)) {
            return [Schema::class, $this->table];
        }
        return function () {};
    }

    /**
     * Remove virtual fields from a hash of data. Virtuals are computed and
     * hence cannot be saved back into the database.
     *
     * @param array $data
     * @return array
     */
    protected function removeVirtuals(array $data) : array
    {
        $return = [];
        foreach ($data as $key => $value) {
            if (!preg_match("@_formatted$@", $key)) {
                if (strlen($value)) {
                    $return[$key] = $value;
                } else {
                    $return[$key] = null;
                }
            }
        }
        return $return;
    }
}

