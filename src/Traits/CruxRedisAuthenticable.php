<?php
namespace Etlok\Crux\Redis\Traits;

use Illuminate\Support\Str;
use Log;

trait CruxRedisAuthenticable {


    public function storeAuthenticationToken($project, $token)
    {
        $redis = $this->redis();
        $cls = Str::camel(get_class());
        $redis->hset("authenticated_elements",$project.":".$cls.":".$this->id,$token);

    }

    public function forgetAuthenticationToken($project)
    {
        $redis = $this->redis();
        if(!self::isAuthenticated($project, $this->id)) {
            return;
        }
        $cls = Str::camel(get_class());
        $redis->hdel("authenticated_elements",$project.":".$cls.":".$this->id);
        $redis->hdel($project.":".$cls.":".$this->id);

    }

    public static function getAuthenticationToken($project, $id)
    {
        $obj = (new self);
        $redis = $obj->redis();
        $cls = Str::camel(get_class());
        if(!$obj->hasKey("authenticated_elements", $project.":".$cls.":".$id)) {
            return null;
        }
        $verify_token = $redis->hget("authenticated_elements",$project.":".$cls.":".$id);
        return $verify_token;
    }

    public function setPermissions($project, $permissions)
    {
        $redis = $this->redis();
        $token = self::getAuthenticationToken($project, $this->id);
        $cls = Str::camel(get_class());
        if($permissions) {
            foreach ($permissions as $permission) {
                $redis->hset($project.":".$cls.":".$this->id,$permission,$token);
            }
        }
    }

    public static function isAuthenticated($project, $id)
    {
        if(self::getAuthenticationToken($project, $id) !== null) {
            return true;
        }
        return false;
    }

    public static function hasValidToken($project, $id, $token)
    {
        $verify_token = self::getAuthenticationToken($project, $id);
        return $verify_token === $token;
    }

    public static function getClass()
    {
        $cls = Str::camel(get_class());
        return $cls;
    }

    public static function hasPermission($project, $id, $permission)
    {
        $obj = (new self);
        $redis = $obj->redis();
        if(!self::isAuthenticated($project, $id)) {
            return false;
        }
        $cls = Str::camel(get_class());

        if(!$obj->hasKey($project.":".$cls.":".$id, $permission)) {
            return false;
        }
        $verify_token = $redis->hget($project.":".$cls.":".$id, $permission);
        $stored_token = self::getAuthenticationToken($project, $id);
        if($verify_token !== $stored_token) {
            return false;
        }
        return true;
    }

}
