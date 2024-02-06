<?php

namespace go\core;

use go\core\http\Exception;
use stdClass;

abstract class Controller {

	/**
	 * Only authenticated users can access
	 * @throws Exception
	 */
	public function __construct() {
		$this->authenticate();
	}

	/**
	 * @throws Exception
	 */
	protected function authenticate() {
		if (!go()->getAuthState()->isAuthenticated()) {
			throw new Exception(401, "Unauthorized");
		}

		$this->rights = $this->getClassRights();
		if (!$this->checkModulePermissions()) {
			$mod = \go\core\model\Module::findByClass(static::class, ['name', 'package']);
			throw new Exception(403, str_replace('{module}', ($mod->package ?? "legacy") . "/" . $mod->name, go()->t("Forbidden, you don't have access to module '{module}'.")));
		}
	}

	protected $rights;

	protected function checkModulePermissions() :bool {
		return $this->rights->mayRead;
	}


	/**
	 * Get the permission level of the module this controller belongs to.
	 * 
	 * @return stdClass For example ['mayRead' => true, 'mayManage'=> true, 'mayHaveSuperCowPowers' => true]
	 */
	protected function getClassRights(): stdClass {
		return go()->getAuthState()->getClassRights(static::class);
	}

    /**
     * Check if required params are present and supply defaults.
     *
     * Usage:
     * ```
     * extract($this->checkParams($params, ['id', 'dryRun'=>false, 'delete'=>false, 'maxDeletePercentage' => 5]));
     * ```
     * @param array $params
     * @param array $required
     * @return array
     */
    protected function checkParams(array $params, array $required) :array {
        foreach($required as $key => $value) {
            if(is_numeric($key)) {
                if(!array_key_exists($value, $params)) {
                    throw new \InvalidArgumentException("'". $value . "' param is required");
                }
            } else{
                if(!array_key_exists($key, $params)) {
                    $params[$key] = $value;
                }
            }
        }

        return $params;
    }
}
