<?php


namespace Etlok\Crux\Redis\Http\Controllers;
use Etlok\Crux\Http\Controllers\CruxModelController;
use Illuminate\Http\Request;
use Log;

class CruxRedisController extends CruxModelController {

    public $model = '';
    public $modelPlural = '';
    public $modelType = '';

    public function test()
    {
        return response()->json([
            'status'=>0
        ]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  Array $validated
     * @return \Illuminate\Http\JsonResponse
     */
    public function save($validated)
    {
        $is_new = false;
        if(!isset($validated['id'])) {
            //New Model
            $is_new = true;
        }
        $obj = $this->performSave($validated);
        if(!$is_new) {
            $obj->findFromRedis($obj->id);
            $obj->updateSettings([
                'should_queue'=>false
            ])->updateFields($validated);
        }


        return response()->json([
            'status'=>0,
            $this->modelType=>$obj
        ]);
    }


    /**
     * @param int $id
     * @param String $child
     * @param int $child_id
     * @param array $validated
     * @return \Illuminate\Http\JsonResponse
     */
    public function updatePivot($id, $child, $child_id, $validated)
    {
        $obj = $this->model::find($id);
        if(!$obj) {
            return response()->json([
                'status'=>1,
                'errorMessage'=>[
                    'title'=>'Not Found!',
                    'text'=>'Unable to find {$this->modelType}.'
                ]
            ],404);
        }
        if(!method_exists($obj,$child)) {
            return response()->json([
                'status'=>1,
                'errorMessage'=>[
                    'title'=>'Not Found!',
                    'text'=>'Unable to find {$child}.'
                ]
            ],404);
        }

        $obj->$child()->updateExistingPivot($child_id,$validated);
        $obj->findFromRedis($obj->id);
        $obj->updateSettings([
            'should_queue'=>false
        ])->updatePivotInRedis($child, $child_id, $validated);

        return response()->json(['status'=>0],200);

    }

    public function getRedisData(Request $request, $id)
    {
        $traits = class_uses($this->model);
        if(!isset($traits['Etlok\Crux\Redis\Traits\UsesRedis'])) {
            return response()->json([
                'status'=>1,
                'errorMessage'=>[
                    'title'=>'Does Not Use Redis!',
                    'text'=>'{$this->modelType} does not use Redis.'
                ]
            ],404);
        }
        $obj = new ($this->model);

        if($request->has('with')) {
            $with = $request->input('with');
            $obj = $obj->setWith($with);
        }

        $obj->findFromRedis($id);

        return response()->json(['status'=>0,'object'=>$obj->redis_data]);
    }

    public function load($id)
    {
        $traits = class_uses($this->model);
        if(!isset($traits['Etlok\Crux\Redis\Traits\UsesRedis'])) {
            return response()->json([
                'status'=>1,
                'errorMessage'=>[
                    'title'=>'Does Not Use Redis!',
                    'text'=>'{$this->modelType} does not use Redis.'
                ]
            ],404);
        }

        $obj = $this->model::find($id);

        if(!$obj) {
            return response()->json([
                'status'=>1,
                'errorMessage'=>[
                    'title'=>'Not Found!',
                    'text'=>'Unable to find {$this->modelType}.'
                ]
            ],404);
        }

        $obj->loadIntoRedis();

        return response()->json([
            'status'=>0,
            'message'=>[
                'title'=>'Done!',
                'text'=>'Model Data Loaded'
            ]
        ]);

    }

    public function unload($id)
    {
        $traits = class_uses($this->model);
        if(!isset($traits['Etlok\Crux\Redis\Traits\UsesRedis'])) {
            return response()->json([
                'status'=>1,
                'errorMessage'=>[
                    'title'=>'Does Not Use Redis!',
                    'text'=>'{$this->modelType} does not use Redis.'
                ]
            ],404);
        }

        $obj = $this->model::find($id);
        if(!$obj) {
            return response()->json([
                'status'=>1,
                'errorMessage'=>[
                    'title'=>'Not Found!',
                    'text'=>'Unable to find {$this->modelType}.'
                ]
            ],404);
        }

        $obj->unloadFromRedis();
        return response()->json(['status'=>0]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $traits = class_uses($this->model);
        if(!isset($traits['Etlok\Crux\Redis\Traits\UsesRedis'])) {
            return response()->json([
                'status'=>1,
                'errorMessage'=>[
                    'title'=>'Does Not Use Redis!',
                    'text'=>'{$this->modelType} does not use Redis.'
                ]
            ],404);
        }

        $obj = $this->model::find($id);

        if(!$obj) {
            return response()->json([
                'status'=>1,
                'errorMessage'=>[
                    'title'=>'Not Found!',
                    'text'=>'Unable to find {$this->modelType}.'
                ]
            ],404);
        }
        $obj->updateSettings(['should_queue'=>false,'cascade'=>true])->del();
        $obj->delete();

        return response()->json(['status'=>0]);
    }

    public function loadAll()
    {
        $traits = class_uses($this->model);
        if(!isset($traits['Etlok\Crux\Redis\Traits\UsesRedis'])) {
            return response()->json([
                'status'=>1,
                'errorMessage'=>[
                    'title'=>'Does Not Use Redis!',
                    'text'=>'{$this->modelType} does not use Redis.'
                ]
            ],404);
        }

        $objects = $this->model::get();
        if(!$objects->isEmpty()) {
            foreach ($objects as $obj) {
                $obj->loadIntoRedis();
            }
        }


        return response()->json(['status'=>0]);
    }

    public function unloadAll()
    {
        $traits = class_uses($this->model);
        if(!isset($traits['Etlok\Crux\Redis\Traits\UsesRedis'])) {
            return response()->json([
                'status'=>1,
                'errorMessage'=>[
                    'title'=>'Does Not Use Redis!',
                    'text'=>'{$this->modelType} does not use Redis.'
                ]
            ],404);
        }

        $objects = $this->model::get();
        if(!$objects->isEmpty()) {
            foreach ($objects as $obj) {
                $obj->unloadFromRedis();
            }
        }


        return response()->json(['status'=>0]);
    }

    public function buildIndexes()
    {
        $traits = class_uses($this->model);
        if(!isset($traits['Etlok\Crux\Redis\Traits\UsesRedis'])) {
            return response()->json([
                'status'=>1,
                'errorMessage'=>[
                    'title'=>'Does Not Use Redis!',
                    'text'=>'{$this->modelType} does not use Redis.'
                ]
            ],404);
        }
        $obj = (new $this->model);
        $obj->makeIndexes();
        $obj->makeMaps();
        return response()->json(['status'=>0]);
    }

    public function clearIndexes()
    {
        $traits = class_uses($this->model);
        if(!in_array('UsesRedis', $traits)) {
            return response()->json([
                'status'=>1,
                'errorMessage'=>[
                    'title'=>'Does Not Use Redis!',
                    'text'=>'{$this->modelType} does not use Redis.'
                ]
            ],404);
        }
        $obj = (new $this->model);
        $obj->clearIndexes();
        $obj->clearMaps();
        return response()->json(['status'=>0]);
    }

    public function attach($id, $child, $child_id, Request $request)
    {
        $obj = $this->model::find($id);

        if(!$obj) {
            return response()->json([
                'status'=>1,
                'errorMessage'=>[
                    'title'=>'Not Found!',
                    'text'=>'Unable to find {$this->modelType}.'
                ]
            ],404);
        }
        $data = $request->input();
        $obj->$child()->attach($child_id,$data);

        if(!isset($obj->_relationships[$child])) {
            return response()->json([
                'status'=>1,
                'errorMessage'=>[
                    'title'=>'Not Found!',
                    'text'=>'Unable to find the relationship.'
                ]
            ],404);
        }

        $obj->findFromRedis($obj->id);
        $child_props = $obj->_relationships[$child];
        $child_class = $child_props['class'];
        $proxy = (new $child_class);
        if(property_exists($proxy,'binds_dynamically')) {
            $proxy->bind('mysql',$this->getItemsTable());
        }
        $child_obj = $proxy->findFromRedis($child_id);
        $obj->updateSettings(['should_queue'=>false])->addChild($child_obj, $child, $data);

        return response()->json(['status'=>0]);
    }

    public function detach($id, $child, $child_id)
    {
        $obj = $this->model::find($id);

        if(!$obj) {
            return response()->json([
                'status'=>1,
                'errorMessage'=>[
                    'title'=>'Not Found!',
                    'text'=>'Unable to find {$this->modelType}.'
                ]
            ],404);
        }

        $obj->$child()->detach($child_id);

        if(!isset($obj->_relationships[$child])) {
            return response()->json([
                'status'=>1,
                'errorMessage'=>[
                    'title'=>'Not Found!',
                    'text'=>'Unable to find the relationship.'
                ]
            ],404);
        }

        $obj->findFromRedis($obj->id);
        $child_props = $obj->_relationships[$child];
        $child_class = $child_props['class'];
        $proxy = (new $child_class);
        if(property_exists($proxy,'binds_dynamically')) {
            $proxy->bind('mysql',$this->getItemsTable());
        }
        $child_obj = $proxy->findFromRedis($child_id);
        $obj->updateSettings(['should_queue'=>false])->removeChild($child_obj, $child);
        return response()->json(['status'=>0]);
    }


}
