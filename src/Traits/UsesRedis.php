<?php

namespace Etlok\Crux\Redis\Traits;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

trait UsesRedis {

    public $redis_data = [];
    public $redis_relationships = [];

    public $settings = [
        'should_load'=>true,
        'should_queue'=>true,
        'should_write_analytics'=>true,
        'cascade'=>false
    ];

    protected static function bootUsesRedis()
    {
        static::created(function ($model) {
            if($model->settings['should_load']) {
                $model->loadIntoRedis();
            }
        });
    }

    public function exists($repo)
    {
        $redis = $this->redis();
        $exists = $redis->exists($repo);
        if (intval($exists) !== 1) {
            return false;
        }
        return true;
    }

    public function hasKey($repo, $key)
    {
        $redis = $this->redis();
        $exists = $redis->hexists($repo,$key);
        if (intval($exists) !== 1) {
            return false;
        }
        return true;
    }

    public function contains($repo, $member)
    {
        $redis = $this->redis();
        $exists = $redis->sismember($repo, $member);
        return  intval($exists) === 1;
    }

    public function incr($repo, $key, $value = 1)
    {
        $redis = $this->redis();
        $redis->hincrby($repo, $key, $value);
    }

    public function track($key, $value)
    {
        $repo = $this->_element.":".$this->value('id').":analytics";
        if($this->exists($repo) && $this->hasKey($repo, $key)) {
            $this->incr($repo, $key, $value);
            return;
        }
        $this->hset($repo, $key, $value);
        $write_repo = self::class.":Analytics";
        $this->write_analytics($write_repo, $repo);
    }

    public static function loadAll()
    {
        $objects = self::get();
        if(!$objects->isEmpty()) {
            foreach ($objects as $obj) {
                $obj->loadIntoRedis();
            }
        }

        $obj = (new self);
        $obj->makeIndexes();
        $obj->makeMaps();
        if(method_exists($obj,'onLoadInstances')) {
            $obj->onLoadInstances();
        }
    }

    public function redis()
    {
        if(property_exists($this,'_data_source')) {
            $redis = Redis::connection($this->_data_source);
        } else {
            $redis = Redis::connection();
        }
        return $redis;
    }

    public function loadAnalytics()
    {
        $redis = $this->redis();
        if(property_exists($this,'_analytics')) {
            if($this->_analytics) {
                $key_string = "";
                foreach ($this->_analytics as $i=>$a) {
                    $key_string  .= ($i===0?"":",").DB::raw($a);
                }
                $rows = DB::connection('analytics')
                    ->select(DB::raw('select `key`, SUM(value) as value FROM analytics'.
                        ' WHERE model = '.DB::raw($this->_element).
                        ' AND model_id = '.DB::raw($this->id).
                        ' AND `key` in ('.$key_string.')',
                        ' GROUP BY `key`'));
                if($rows) {
                    $repo = $this->_element.":".$this->id.":analytics";
                    foreach ($rows as $row) {
                        $redis->hset($repo,$row->key, $row->value);
                    }
                }
            }
        }
    }

    public function loadIntoRedis()
    {
        $redis = $this->redis();
        $element = $this->_element;
        $repo = $element.":".$this->id;
        if(!$redis->sismember($element.":all", $repo)) {
            $redis->sadd($element.":all", $repo);
        }

        if($this->_fields) {
            foreach ($this->_fields as $field=>$type) {
                if($type === 'json') {
                    if(gettype($this->$field ) === 'array') {
                        $value = @json_encode($this->$field);
                    } else if(gettype($this->$field) === 'string') {
                        $value = @json_encode(@json_decode($this->$field));
                    }

                    $redis->hset($repo,$field,$value);
                } else {
                    $value = $this->$field;
                    $redis->hset($repo,$field,$value);
                }
            }
            $item = $redis->hgetall($repo);
            if($item) {
                foreach ($item as $k=>$v) {
                    if(isset($this->_fields[$k]) && $this->_fields[$k] === 'int') {
                        $item[$k] = intval($v);
                    } else if(isset($this->_fields[$k]) && $this->_fields[$k] === 'float') {
                        $item[$k] = floatval($v);
                    } else if (isset($this->_fields[$k]) && $this->_fields[$k] === 'json') {
                        $item[$k] = @json_decode($v, true);
                    }
                }
            } else {
                $item = null;
            }

            $this->redis_data = $item;
            foreach ($this->_fields as $field=>$type) {
                if(property_exists($this,'_indexes')) {
                    if (isset($this->_indexes[$field])) {
                        $this->addToIndex($field, $value);
                    }
                }
                if(property_exists($this,'_maps')) {
                    foreach($this->_maps as $map_id=>$map_content) {
                        if(in_array($field,$map_content)) {
                            $this->addToMap($map_id, $map_content);
                        }
                    }
                }
            }
        }
        $this->loadAnalytics();
        if($this->_relationships) {
            $children = array_keys($this->_relationships);
        } else {
            $children = [];
        }

        if($children) {
            foreach ($children as $child) {
                if(!isset($this->_relationships[$child])) {
                    continue;
                }
                $child_props = $this->_relationships[$child];
                if($child_props['has'] === 'one') {
                    $child_id = $child_props['id'];
                    $child_class =$child_props['class'];
                    $proxy = (new $child_class);
                    if(property_exists($proxy,'binds_dynamically')) {
                        $proxy->bind('mysql',$this->getItemsTable());
                    }
                    $child_obj = $proxy->find($this->$child_id);
                    if($child_obj) {
                        if(isset($child_props['should_load']) && $child_props['should_load']) {
                            $child_obj->loadIntoRedis();
                        }
                    }
                } else {
                    $child_objects_repo = $element.":".$this->id.":".$child;
                    $child_objects = $this->$child()->get();
                    if(!$child_objects->isEmpty()) {
                        foreach ($child_objects as $child_obj) {
                            $member = $child_props['element'].":".$child_obj->id;
                            if(!$redis->sismember($child_objects_repo, $member)) {
                                $redis->sadd($child_objects_repo, $member);
                            }
                            if(isset($child_props['should_load']) && $child_props['should_load']) {

                                $child_obj->loadIntoRedis();
                            }

                            if(isset($child_props['pivot'])) {
                                $pivot_repo = $element.":".$this->id.":".$child.":".$child_obj->id;
                                foreach ($child_props['pivot'] as $pik=> $piv) {
                                    $redis->hset($pivot_repo,$pik,$child_obj->pivot->$pik);
                                }
                            }
                        }
                    }
                }
            }
        }

        if(property_exists($this,'_parents')) {
            $parents = $this->_parents;
            if($parents) {
                foreach ($parents as $p=>$parent_props) {
                    $parent_cls = $parent_props['class'];
                    $parent_id_field =$parent_props['id'];
                    $parent_id = $this->$parent_id_field;
                    $parent_obj = (new $parent_cls);
                    $parent_obj->findFromRedis($parent_id);
                    $member = $this->_element.":".$this->id;
                    $child_objects_repo = $parent_obj->_element.":".$parent_id.":".Str::plural($this->_element);
                    if(!$redis->sismember($child_objects_repo, $member)) {
                        $redis->sadd($child_objects_repo, $member);
                    }
                }
            }
        }

        if(method_exists($this,'onLoadIntoRedis')) {
            $this->onLoadIntoRedis();
        }


    }

    public function makeMaps()
    {
        $redis = $this->redis();
        $all = $this->getAll();
        if(property_exists($this,'_maps')) {
            foreach ($all as $item) {
                foreach ($this->_maps as $map_id=>$map_content) {
                    $repo = $this->_element.":map:".$map_id;
                    if($map_content) {
                        $map_key = "";
                        foreach($map_content as $i=>$m) {
                            $map_key .= ($i===0?"":":").$m.":".$item[$m];
                        }
                    }
                    $redis->hset($repo,$map_key,$this->_element.":".$item['id']);
                }
            }
        }
    }

    public function addToMap($map_id,$map_content)
    {

        $redis = $this->redis();
        $repo = $this->_element . ":map:" . $map_id;
        if($map_content) {
            $map_key = "";
            foreach($map_content as $i=>$m) {
                $map_key .= ($i===0?"":":").$m.":".$this->value($m);
            }
        }
        $redis->hset($repo,$map_key,$this->_element.":".$this->value('id'));
    }

    public function removeFromMap($map_id, $map_content)
    {
        $redis = $this->redis();
        $repo = $this->_element . ":map:" . $map_id;
        if($map_content) {
            $map_key = "";
            foreach($map_content as $i=>$m) {
                $map_key .= ($i===0?"":":").$m.":".$this->value($m);
            }
        }
        $redis->hdel($repo,$map_key);
    }

    public function clearMaps()
    {
        $redis = $this->redis();
        if(property_exists($this,'_maps')) {
            foreach ($this->_maps as $map_id=>$map_content) {
                $repo = $this->_element . ":map:" . $map_id;
                $redis->del($repo);
            }
        }
    }

    public function makeIndexes()
    {
        $redis = $this->redis();
        if(property_exists($this,'_indexes')) {
            $all = $this->getAll();
            $index_repo = $this->_element.":indexes";
            foreach ($all as $item) {
                foreach ($this->_indexes as $index) {
                    if(isset($item[$index]) && $item[$index] !== null) {
                        $repo = $this->_element.":index:".$index.":".$item[$index];
                        $redis->sadd($repo,$this->_element.":".$item['id']);
                    } else {
                        $repo = $this->_element.":index:".$index.":null";
                        $redis->sadd($repo,$this->_element.":".$item['id']);
                    }
                    if(!$redis->sismember($index_repo, $repo)) {
                        $redis->sadd($index_repo, $repo);
                    }
                }
            }
        }
    }

    public function clearIndexes()
    {
        $redis = $this->redis();

        if(property_exists($this,'_indexes')) {
            $index_repo = $this->_element.":indexes";
            $members = $redis->smembers($index_repo);
            foreach ($members as $repo) {
                $redis->del($repo);
            }
        }
    }

    public function updateSettings($s)
    {
        $this->settings = array_merge_recursive($this->settings, $s);
        return $this;
    }

    public function updateInRedis($field, $value)
    {
        $redis = $this->redis();
        $existing_value = $this->value($field);
        if(property_exists($this,'_indexes')) {
            if (isset($this->_indexes[$field])) {
                $this->removeFromIndex($field, $existing_value);
            }
        }
        if(property_exists($this,'_maps')) {
            foreach($this->_maps as $map_id=>$map_content) {
                if(in_array($field,$map_content)) {
                    $this->removeFromMap($map_id, $map_content);
                }
            }
        }

        if(property_exists($this,'_parents')) {
            $parents = $this->_parents;
            if($parents) {
                foreach ($parents as $p=>$parent_props) {
                    if($parent_props['id'] === $field) {
                        $parent_cls = $parent_props['class'];
                        $parent_obj = (new $parent_cls);
                        $member = $this->_element.":".$this->value('id');
                        $child_objects_repo = $parent_obj->_element.":".$existing_value.":".Str::plural($this->_element);
                        if($redis->sismember($child_objects_repo, $member)) {
                            $redis->srem($child_objects_repo, $member);
                        }
                    }
                }
            }
        }

        $repo = $this->_element.":".$this->id;
        $redis->hset($repo,$field, $value);
        if(property_exists($this,'_indexes')) {
            if (isset($this->_indexes[$field])) {
                $this->addToIndex($field, $value);
            }
        }
        if(property_exists($this,'_maps')) {
            foreach($this->_maps as $map_id=>$map_content) {
                if(in_array($field,$map_content)) {
                    $this->addToMap($map_id, $map_content);
                }
            }
        }

        if(property_exists($this,'_parents')) {
            $parents = $this->_parents;
            if($parents) {
                foreach ($parents as $p=>$parent_props) {
                    if($parent_props['id'] === $field) {
                        $parent_cls = $parent_props['class'];
                        $parent_obj = (new $parent_cls);
                        $member = $this->_element.":".$this->value('id');
                        $child_objects_repo = $parent_obj->_element.":".$value.":".Str::plural($this->_element);
                        if(!$redis->sismember($child_objects_repo, $member)) {
                            $redis->sadd($child_objects_repo, $member);
                        }
                    }
                }
            }
        }
    }

    public function updateFields($data)
    {
        if($data) {
            foreach($data as $k=>$v) {
                if(isset($this->_fields[$k])) {
                    if($this->_fields[$k] === 'json') {
                        $v = @json_encode($v);
                    }
                    $this->updateInRedis($k, $v);
                }
            }
        }


    }

    public function set($data)
    {
        $redis = $this->redis();
        $this->updateFields($data);
        $repo = $this->_element.":".$this->id;
        $update_repo = self::class.":Updates";
        $this->queue($update_repo, $repo);
    }

    public function queue($list, $repo)
    {
        if(!$this->settings['should_queue']) return;
        $redis = $this->redis();
        $ds = 'jobs';
        $redis->lpush($ds.":actions", $list."|".$repo);
    }

    public function write_analytics($list, $repo)
    {
        if(!$this->settings['should_write_analytics']) return;
        $redis = $this->redis();
        $ds = 'analytics';
        if(!$this->contains($ds.":actions", $list."|".$repo)) {
            $redis->sadd($ds.":actions", $list."|".$repo);
        }

    }

    public function del()
    {
        $repo = $this->_element.":".$this->value('id');
        $redis = $this->redis();
        if(property_exists($this,'_indexes')) {
            foreach ($this->_indexes as $index) {
                $this->removeFromIndex($index,$this->value($index));
            }
        }
        if(property_exists($this,'_maps')) {
            foreach($this->_maps as $map_id=>$map_content) {
                $this->removeFromIndex($map_id, $map_content);
            }
        }

        $analytics_repo = $this->_element.":".$this->value('id').":analytics";
        if($this->exists($analytics_repo)) {
            $redis->del($analytics_repo);
        }
        $redis->del($repo);

        if($redis->sismember($this->_element.":all", $repo)) {
            $redis->srem($this->_element.":all", $repo);
        }

        $children = $this->_relationships;

        if($children) {
            foreach($children as $child=>$child_props) {
                if($child_props['has'] === 'one') {
                    $child_key = $child_props['id'];
                    $child_id = $this->value($child_key);
                    $child_class = $child_props['class'];
                    $child_obj = (new $child_class)->findFromRedis($child_id);
                    if($this->settings['cascade']) {
                        $child_obj->updateSettings($this->settings);
                    }
                    if ($child_obj) {
                        if(isset($child_props['should_delete']) && $child_props['should_delete']) {
                            $child_obj->del();
                        }
                    }
                } else {
                    $child_objects_repo = $this->_element.":".$this->value('id').":".$child;
                    $child_repos = $redis->smembers($child_objects_repo);
                    if($child_repos) {
                        foreach ($child_repos as $child_repo) {
                            $child_class = $child_props['class'];
                            $child_obj = (new $child_class)->findFromRedis($child_repo);
                            if($this->settings['cascade']) {
                                $child_obj->updateSettings($this->settings);
                            }
                            if(isset($child_props['pivot'])) {
                                $pivot_repo = $this->_element.":".$this->value('id').":".$child.":".$child_obj->value('id');
                                $exists = $redis->exists($pivot_repo);
                                if(intval($exists) === 1) {
                                    $redis->del($pivot_repo);
                                }
                            }
                            $this->removeChild($child_obj);

                        }
                    }
                    $redis->del($child_objects_repo);
                }

            }
        }

        $delete_repo = self::class.":Deletes";
        $this->queue($delete_repo, $repo);
    }

    public function createNewInRedis($data)
    {
        $redis = $this->redis();
        $element = $this->_element;
        $repo = $element.":".$data['id'];
        $this->redis_data = $data;
        if($this->_fields) {
            foreach ($this->_fields as $field=>$type) {
                if(isset($data[$field])) {
                    $redis->hset($repo,$field,$data[$field]);
                    if(property_exists($this,'_indexes')) {
                        if (isset($this->_indexes[$field])) {
                            $this->addToIndex($field, $data[$field]);
                        }
                    }
                    if(property_exists($this,'_maps')) {
                        foreach($this->_maps as $map_id=>$map_content) {
                            if(in_array($field,$map_content)) {
                                $this->addToMap($map_id, $map_content);
                            }
                        }
                    }
                }
            }
        }
        if(!$redis->sismember($element.":all", $repo)) {
            $redis->sadd($element.":all", $repo);
        }
        $create_repo = self::class.":Creates";
        $this->queue($create_repo, $repo);
        return $this;
    }

    public function hasChild($child, $child_id)
    {
        $redis = $this->redis();
        $child_props = $this->_relationships[$child];
        $element_repo = $this->_element.":".$this->value('id').":".$child;
        $member = $child_props['element'].":".$child_id;
        if($redis->sismember($element_repo, $member)) {
            return true;
        }
        return false;
    }

    public function addChild($child_obj, $relation = "", $pivot = null)
    {
        $redis = $this->redis();
        if($relation === "") {
            $relation = Str::plural($child_obj->_element);
        }
        $child_props = $this->_relationships[$relation];

        $element_repo = $this->_element.":".$this->value('id').":".$relation;
        $member = $child_obj->_element.":".$child_obj->value('id');

        if(!$redis->sismember($element_repo, $member)) {
            $redis->sadd($element_repo, $member);
        }
        $this->childUpdated($relation, $child_obj->value('id'));

        if($pivot !== null) {
            $pivot_repo = $this->_element.":".$this->value('id').":".$relation.":".$child_obj->value('id');
            foreach($pivot as $key=> $value) {
                if(isset($child_props['pivot'][$key])) {
                    $redis->hset($pivot_repo,$key,$value);
                }
            }
        }
        return $this;
    }

    public function childUpdated($child, $child_id)
    {
        $create_repo = self::class.":AddChildren";
        $this->queue($create_repo, $this->_element.":".$this->value('id').":".$child.":".$child_id);

    }

    public function updatePivotInRedis($child, $child_id, $pivot)
    {
        $redis = $this->redis();
        $this->childUpdated($child, $child_id);
        $child_props = $this->_relationships[$child];

        if($pivot !== null) {
            $pivot_repo = $this->_element.":".$this->value('id').":".$child.":".$child_id;
            foreach($pivot as $key=> $value) {
                if(isset($child_props['pivot'][$key])) {
                    $redis->hset($pivot_repo,$key,$value);
                }
            }
        }
        return $this;
    }


    public function removeChild($child_obj, $relation = "")
    {
        $redis = $this->redis();
        if($relation === "") {
            $relation = Str::plural($child_obj->_element);
        }
        $child_props = $this->_relationships[$relation];

        $element_repo = $this->_element.":".$this->value('id').":".$relation;
        $member = $child_obj->_element.":".$child_obj->value('id');
        $child_repo = $this->_element.":".$this->value('id').":".$relation.":".$child_obj->value('id');

        if(!$redis->sismember($element_repo, $member)) {
            $redis->srem($element_repo, $member);
        }

        $exists = $redis->exists($child_repo);
        if(intval($exists) === 1) {
            $redis->del($child_repo);
        }

        $create_repo = self::class.":RemoveChildren";
        $this->queue($create_repo, $child_repo);

        if(isset($child_props['should_delete']) && $child_props['should_delete']) {
            $child_obj->del();
        }

    }

    public function addToIndex($field, $value)
    {
        $redis = $this->redis();
        $index_repo = $this->_element.":index:".$field.":".$value;
        $redis->sadd($index_repo,$this->_element.":".$this->value('id'));
    }

    public function removeFromIndex($field, $value)
    {
        $redis = $this->redis();
        $index_repo = $this->_element.":index:".$field.":".$value;
        $redis->srem($index_repo,$this->_element.":".$this->value('id'));
    }

    public function unloadFromRedis()
    {
        $redis = $this->redis();
        $element = $this->_element;
        $repo = $element.":".$this->id;

        $exists = $redis->exists($repo);
        if(intval($exists) !== 1) {
            return;
        }
        $this->findFromRedis($this->id);

        $children = $this->_with;
        if($children) {
            foreach ($children as $child) {
                if(!isset($this->_relationships[$child])) {
                    continue;
                }
                $child_props = $this->_relationships[$child];
                if($child_props['has'] === 'one') {
                    if(isset($child_props['should_unload']) && $child_props['should_unload']) {
                        $child_key = $child_props['id'];
                        $child_id = $this->value($child_key);
                        $child_class = $child_props['class'];
                        $proxy = (new $child_class);
                        if(property_exists($proxy,'binds_dynamically')) {
                            $proxy->bind('mysql',$this->getItemsTable());
                        }
                        $child_obj = $proxy->find($child_id);
                        if ($child_obj) {
                            $child_obj->unloadFromRedis();
                        }
                    }
                } else {
                    $child_objects_repo = $element.":".$this->id.":".$child;
                    $child_repos = $redis->smembers($child_objects_repo);
                    if($child_repos) {
                        foreach ($child_repos as $child_repo) {
                            $child_class = $child_props['class'];
                            $child_obj = (new $child_class)->findFromRedis($child_repo);
                            if($child_obj !== null) {
                                if($redis->sismember($child_objects_repo, $child_repo)) {
                                    $redis->srem($child_objects_repo, $child_repo);
                                }

                                if(isset($child_props['pivot'])) {
                                    $pivot_repo = $element.":".$this->id.":".$child.":".$child_obj->value('id');
                                    $exists = $redis->exists($pivot_repo);
                                    if(intval($exists) === 1) {
                                        $redis->del($pivot_repo);
                                    }
                                }

                                if(isset($child_props['should_unload']) && $child_props['should_unload']) {
                                    if($child_obj) {
                                        $child_obj->unloadFromRedis();
                                    }
                                }
                            }

                        }
                    }
                }
            }
        }
        if(property_exists($this,'_indexes')) {
            foreach ($this->_indexes as $index) {
                $this->removeFromIndex($index,$this->value($index));
            }
        }
        if(property_exists($this,'_maps')) {
            foreach($this->_maps as $map_id=>$map_content) {
                $this->removeFromIndex($map_id, $map_content);
            }
        }

        $redis->del($repo);
        if($redis->sismember($element.":all", $repo)) {
            $redis->srem($element.":all", $repo);
        }
    }

    public function setWith($with)
    {
        $this->_with = array_merge_recursive($this->_with, $with);
        return $this;
    }

    public function fetchFromMap($map_id, $data)
    {
        $redis = $this->redis();
        $repo = $this->_element . ":map:" . $map_id;
        if(property_exists($this,'_maps')) {
            $map_content = $this->_maps[$map_id];
            if($map_content) {
                $map_key = "";
                foreach($map_content as $i=>$m) {
                    $map_key .= ($i===0?"":":").$m.":".$data[$m];
                }
            }
            $repo = $redis->hget($repo,$map_key);
            return $this->findFromRedis($repo);
        }
        return null;
    }

    public function fetchWithIndex($key, $index)
    {
        $redis = $this->redis();
        $repo = $this->_element.":index:".$index.":".$key;
        $exists = $redis->exists($repo);
        if(intval($exists) === 1) {
            $members = $redis->smembers($repo);
            return $members;
        }
        return [];
    }

    public function findFromRedis($id)
    {
        $redis = $this->redis();
        $element = $this->_element;
        $repo = $element.":".$id;
        if(stristr($id,':')) {
            $repo = $id;
        }

        $item = $redis->hgetall($repo);
        if($item) {
            foreach ($item as $k=>$v) {
                if(isset($this->_fields[$k]) && $this->_fields[$k] === 'int') {
                    $item[$k] = intval($v);
                } else if(isset($this->_fields[$k]) && $this->_fields[$k] === 'float') {
                    $item[$k] = floatval($v);
                } else if (isset($this->_fields[$k]) && $this->_fields[$k] === 'json') {
                    $item[$k] = @json_decode($v, true);
                }
            }
        } else {
            $item = null;
        }

        $this->redis_data = $item;

        $children = $this->_with;
        if($children && $item !== null) {
            foreach ($children as $child) {
                if(!isset($this->_relationships[$child])) {
                    continue;
                }
                $child_props = $this->_relationships[$child];
                $obj = "";
                $this->redis_data[$child] = $this->fetchFromRedis($child, $child_props,[],$obj);
                $this->redis_relationships[$child] = $obj;

            }
        }

        return $this;
    }

    public function pivotValue($field)
    {
        if(isset($this->redis_data['pivot'])
            && isset($this->redis_data['pivot'][$field])) {
            return $this->redis_data['pivot'][$field];
        }
        return null;
    }

    public function value($field)
    {
        if(isset($this->redis_data[$field])) {
            return $this->redis_data[$field];
        }
        return null;
    }

    public function relation($field)
    {
        if(isset($this->redis_relationships[$field])) {
            return $this->redis_relationships[$field];
        }
        return null;
    }

    public function search($field, $id, $search_field = 'id')
    {
        $arr = $this->value($field);

        $parts = explode(".",$search_field);
        $col_array = $arr;
        if($parts) {
            foreach ($parts as $part) {
                $col_array = array_column($col_array, $part);
            }
        }

        $instance_key = array_search($id,$col_array);
        if($instance_key !== false) {
            return $arr[$instance_key];
        }
        return null;
    }

    public function searchAll($field, $id, $search_field = 'id')
    {
        $arr = $this->value($field);

        $parts = explode(".",$search_field);
        $col_array = $arr;
        if($parts) {
            foreach ($parts as $part) {
                $col_array = array_column($col_array, $part);
            }
        }

        $instance_keys = array_keys($col_array,$id);
        if($instance_keys) {
            return array_filter($arr,function($k) use ($instance_keys) {
                return in_array($k, $instance_keys);
            },ARRAY_FILTER_USE_KEY);
        }
        return null;
    }


    public function getAll()
    {
        $redis = $this->redis();
        $element_repo = $this->_element.":all";
        $members = $redis->smembers($element_repo);
        $output = [];
        if($members) {
            foreach ($members as $repo) {
                $obj = (new self)->findFromRedis($repo);
                $output[] = $obj->redis_data;
            }
        }
        return $output;
    }

    public function getAllAsDictionary($key)
    {
        $all = $this->getAll();
        $dict = [];
        if($all) {
            foreach ($all as $type) {
                $dict[$type[$key]] = $type;
            }
        }
        return $dict;
    }

    public function fetchChild($child, $id, $child_props = null, &$objects = null)
    {

        $redis = $this->redis();
        if($child_props === null && isset($this->_relationships[$child])) {
            $child_props = $this->_relationships[$child];
        }
        $child_repo = $child_props['element'].":".$id;
        if(stristr($id,':')) {
            $child_repo = $id;
        }
        $child_class = $child_props['class'];
        $child_obj = (new $child_class)->findFromRedis($child_repo);
        $child_data = $child_obj->redis_data;
        if($child_props !== null && isset($child_props['pivot'])) {
            $pivot_repo = $this->_element.":".$this->value('id').":".$child.":".$child_obj->value('id');
            $pivot_element_exists = $redis->exists($pivot_repo);
            if(intval($pivot_element_exists) === 1) {
                $pivot_data = $redis->hgetall($pivot_repo);
                foreach ($child_props['pivot'] as $pik=> $piv) {
                    if($piv === 'int') {
                        $child_data['pivot'][$pik] = intval($pivot_data[$pik]);
                    } else if ($piv === 'float'){
                        $child_data['pivot'][$pik] = floatval($pivot_data[$pik]);
                    } else if($piv === 'json'){
                        $child_data['pivot'][$pik] = @json_decode($pivot_data[$pik], true);
                    } else {
                        $child_data['pivot'][$pik] = $pivot_data[$pik];
                    }
                }
            }
        }

        if($objects !== null) {
            $objects[] = $child_obj;
        }
        return $child_data;
    }

    public function fetchFromRedis($child, $child_props = null, $filters = [], &$objects = null)
    {
        $redis = $this->redis();
        if($child_props === null && isset($this->_relationships[$child])) {
            $child_props = $this->_relationships[$child];
        }
        $children_data = [];
        $_objects = null;
        if($child_props['has'] === 'one') {
            $child_class = $child_props['class'];
            $child_obj = (new $child_class)->findFromRedis($this->value($child_props['id']));
            $children_data = $child_obj->redis_data;
            $_objects = $child_obj;
        } else {
            $element_repo = $this->_element.":".$this->value('id').":".$child;
            $element_exists = $redis->exists($element_repo);
            if(intval($element_exists) === 1) {
                $children = $redis->smembers($element_repo);
                $children_data = [];
                $_objects = [];
                if ($children) {
                    foreach ($children as $child_repo) {
                        $child_data = $this->fetchChild($child,$child_repo, $child_props,$_objects);
                        $children_data[] = $child_data;
                    }
                }
            }
        }
        if($objects !== null) {
            $objects = $_objects;
        }
        return $children_data;
    }

    public function executeAnalytics($repo)
    {
        $redis = $this->redis();
        $parts = explode(':',$repo);

        if(!$parts[0] === $this->_element) {
            return;
        }
        if(!isset($parts[1])) {
            return;
        }
        $data = $redis->hgetall($repo);

        $rows = [];
        if($data) {
            foreach ($data as $k=>$v) {
                $rows[] = [
                    'model'=>$this->_element,
                    'model_id'=>$parts[1],
                    'key'=>$k,
                    'value'=>$v
                ];
                $redis->hset($k,0);
            }
        }
        DB::connection('analytics')->table('analytics')->insert($rows);

    }

    public function executeCreates($repo)
    {
        $redis = $this->redis();
        $parts = explode(':',$repo);

        if(!$parts[0] === $this->_element) {
            return;
        }
        if(!isset($parts[1])) {
            return;
        }

        if(!$this->exists($repo)) {
            return;
        }

        $data = $redis->hgetall($repo);
        $proxy = (new self);
        if(property_exists($proxy,'binds_dynamically')) {
            foreach ($this->_parents as $parent) {
                if(isset($parent['type']) && $parent['type'] === 'binds_dynamically') {
                    $parent_class = $parent['class'];
                    $parent_id = $data[$parent['id']];
                    $parent = $parent_class::find($parent_id);
                    $proxy->bind('mysql',$parent->getItemsTable());
                }
            }
        }
        $obj = (new self);
        $obj->fill($data);
        $obj->save();
    }

    public function executeUpdates($repo)
    {
        $redis = $this->redis();
        $parts = explode(':',$repo);

        if(!$parts[0] === $this->_element) {
            return;
        }
        if(!isset($parts[1])) {
            return;
        }

        $object_id = $parts[1];
        $data = $redis->hgetall($repo);
        $proxy = (new self);
        if(property_exists($proxy,'binds_dynamically')) {
            $parent_class = $this->_parent['class'];
            $parent_id = $data[$this->_parent['id']];
            $parent = $parent_class::find($parent_id);
            $proxy->bind('mysql',$parent->getItemsTable());
        }
        $obj = $proxy->find($object_id);
        if(!$obj) {
            return;
        }

        if(isset($data['id'])) {
            unset($data['id']);
        }
        $obj->fill($data);
        $obj->save();

    }

    public function executeAddChildren($repo)
    {
        $redis = $this->redis();
        $element = $this->_element;
        $parts = explode(':',$repo);

        if(!$parts[0] === $element) {
            return;
        }

        if(!isset($parts[3])) {
            return;
        }
        if(!isset($parts[2])) {
            return;
        }
        if(!isset($parts[1])) {
            return;
        }


        $object_id = $parts[1];
        $child = $parts[2];
        $child_id = $parts[3];

        $object_repo = $element.":".$object_id;
        $data = $redis->hgetall($object_repo);
        $proxy = (new self);
        if(property_exists($proxy,'binds_dynamically')) {
            $parent_class = $this->_parent['class'];
            $parent_id = $data[$this->_parent['id']];
            $parent = $parent_class::find($parent_id);
            $proxy->bind('mysql',$parent->getItemsTable());
        }
        $obj = $proxy->find($object_id);
        if(!$obj) {
            return;
        }

        $pivot_element_exists = $redis->exists($repo);
        if(intval($pivot_element_exists) === 1) {
            $pivot_data = $redis->hgetall($repo);
            $obj->$child()->syncWithoutDetaching([$child_id => $pivot_data]);
        } else {
            $obj->$child()->syncWithoutDetaching([$child_id]);
        }
    }

    public function executeRemoveChildren($repo)
    {
        $redis = $this->redis();
        $element = $this->_element;
        $parts = explode(':',$repo);

        if(!$parts[0] === $element) {
            return;
        }

        if(!isset($parts[3])) {
            return;
        }
        if(!isset($parts[2])) {
            return;
        }
        if(!isset($parts[1])) {
            return;
        }


        $object_id = $parts[1];
        $child = $parts[2];
        $child_id = $parts[3];

        $object_repo = $element.":".$object_id;
        $data = $redis->hgetall($object_repo);
        $proxy = (new self);
        if(property_exists($proxy,'binds_dynamically')) {
            $parent_class = $this->_parent['class'];
            $parent_id = $data[$this->_parent['id']];
            $parent = $parent_class::find($parent_id);
            $proxy->bind('mysql',$parent->getItemsTable());
        }
        $obj = $proxy->find($object_id);
        if(!$obj) {
            return;
        }
        $obj->$child()->detach($child_id);
    }

    public function executeDeletes($repo)
    {
        $redis = $this->redis();
        $element = $this->_element;
        $parts = explode(':',$repo);

        if(!$parts[0] === $element) {
            return;
        }
        if(!isset($parts[1])) {
            return;
        }

        $object_id = $parts[1];
        $data = $redis->hgetall($repo);
        $proxy = (new self);
        if(property_exists($proxy,'binds_dynamically')) {
            $parent_class = $this->_parent['class'];
            $parent_id = $data[$this->_parent['id']];
            $parent = $parent_class::find($parent_id);
            $proxy->bind('mysql',$parent->getItemsTable());
        }
        $obj = $proxy->find($object_id);
        if(!$obj) {
            return;
        }

        $obj->delete();
    }
}
