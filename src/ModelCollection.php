<?php
namespace Hirest\Hiorm;


class ModelCollection implements Iterator{

    private $position = 0;
    private $items = [];




    public function __construct(){
        $this->position = 0;
    }




    public function rewind(){
        $this->position = 0;
    }




    public function current(){
        return $this->items[$this->position];
    }




    public function key(){
        return $this->position;
    }




    public function valid(){
        return isset( $this->items[$this->position] );
    }




    /**
     * Удаляет из коллекции текущий элемент
     * @return $this
     */
    public function removeKey($key = null){
        if($key === null){
            $key = $this->position;
        }

        if($key == $this->position){
            $this->next();
        }

        unset( $this->items[$key] );

        return $this;
    }




    public function next(){
        ++$this->position;
    }




    /**
     * Добавляет модель в коллекцию
     * @param $model \Hirest\Hiorm\Model
     * @return $this
     */
    public function add(\Hirest\Hiorm\Model $model){
        $this->items[] = $model;

        return $this;
    }




    /**
     * Возвращает массив данных моделей в JSON формате
     * @param null $options - опции json_encode
     * @return string
     */
    public function toJson($options = null){
        return json_encode( $this->toArray(), $options );
    }




    /**
     * Возвращает массив modelData моделей в коллекции
     * @param string $key - если нужно использовать в качестве ключа поле из записи
     * @return array
     */
    public function toArray($key = null){
        $array = [];
        foreach($this->items AS $item){
            if($key){
                $array[$item->{$key}] = $item->getModelData();
            }else{
                $array[] = $item->getModelData();
            }

        }

        return $array;
    }




    /**
     * Проверяет пустая ли коллекция
     * @return mixed
     */
    public function is_empty(){
        return empty( $this->items );
    }




    /**
     * Возвращает кол-во моделей в коллекции
     * @return int
     */
    public function count(){
        return count( $this->items );
    }




    /**
     * Возвращает первую модель в коллекции
     * @return \Hirest\Hiorm\Model
     */
    public function first(){
        return array_shift( $this->items );
    }




    /**
     * Выполняет переданную функцию для всех моделей в коллекции
     * @param callable $callback
     * @return $this
     */
    public function map(callable $callback){
        foreach($this->items AS $item){
            $callback( $item );
        }

        return $this;
    }


}