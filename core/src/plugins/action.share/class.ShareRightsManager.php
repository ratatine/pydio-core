<?php
/*
 * Copyright 2007-2015 Abstrium <contact (at) pydio.com>
 * This file is part of Pydio.
 *
 * Pydio is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Pydio is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Pydio.  If not, see <http://www.gnu.org/licenses/>.
 *
 * The latest code can be found at <http://pyd.io/>.
 */

defined('AJXP_EXEC') or die('Access not allowed');

define('PARAM_USER_LOGIN_PREFIX', "user_");
define('PARAM_USER_PASS_PREFIX', "user_pass_");
define('PARAM_USER_RIGHT_WATCH_PREFIX', "right_watch_");
define('PARAM_USER_RIGHT_READ_PREFIX', "right_read_");
define('PARAM_USER_RIGHT_WRITE_PREFIX', "right_write_");
define('PARAM_USER_ENTRY_TYPE', "entry_type_");

class ShareRightsManager
{
    /**
     * @var string
     */
    var $tmpUsersPrefix;
    /**
     * @var bool|MetaWatchRegister
     */
    var $watcher;

    /**
     * ShareRightsManager constructor.
     * @param string $tmpUsersPrefix
     * @param bool $watcher
     */
    public function __construct($tmpUsersPrefix = "", $watcher = false)
    {
        $this->tmpUsersPrefix = $tmpUsersPrefix;
        $this->watcher = $watcher;
    }

    /**
     * @param array $httpVars
     * @param string $userId
     * @param string|null $userPass
     * @throws Exception
     */
    public function makeUniqueUserParameters(&$httpVars, $userId, $userPass = null){

        if(isSet($userPass)) {
            $httpVars["user_pass_0"] = $httpVars["shared_pass"] = $userPass;
        }
        $httpVars["user_0"] = $userId;
        $httpVars["entry_type_0"] = "user";
        $httpVars["right_read_0"] = (isSet($httpVars["simple_right_read"]) ? "true" : "false");
        $httpVars["right_write_0"] = (isSet($httpVars["simple_right_write"]) ? "true" : "false");
        $httpVars["right_watch_0"] = "false";
        $httpVars["disable_download"] = (isSet($httpVars["simple_right_download"]) ? false : true);
        if ($httpVars["right_read_0"] == "false" && !$httpVars["disable_download"]) {
            $httpVars["right_read_0"] = "true";
        }
        if ($httpVars["right_write_0"] == "false" && $httpVars["right_read_0"] == "false") {
            throw new Exception("Insufficient rights");
        }

    }

    /**
     * @param $httpVars
     * @param array $users
     * @param array $groups
     * @throws Exception
     */
    public function createUsersFromParameters($httpVars, &$users = array(), &$groups = array()){

        $index = 0;

        while (isSet($httpVars[PARAM_USER_LOGIN_PREFIX.$index])) {

            $eType = $httpVars[PARAM_USER_ENTRY_TYPE.$index];
            $rightString = ($httpVars[PARAM_USER_RIGHT_READ_PREFIX.$index]=="true"?"r":"").($httpVars[PARAM_USER_RIGHT_WRITE_PREFIX.$index]=="true"?"w":"");
            $uWatch = false;
            if($this->watcher !== false) {
                $uWatch = $httpVars[PARAM_USER_RIGHT_WATCH_PREFIX.$index] == "true" ? true : false;
            }
            if (empty($rightString)) {
                $index++;
                continue;
            }

            if ($eType == "user") {

                $u = AJXP_Utils::decodeSecureMagic($httpVars[PARAM_USER_LOGIN_PREFIX.$index], AJXP_SANITIZE_EMAILCHARS);
                if (!AuthService::userExists($u) && !isSet($httpVars[PARAM_USER_PASS_PREFIX.$index])) {
                    $index++;
                    continue;
                } else if (AuthService::userExists($u, "w") && isSet($httpVars[PARAM_USER_PASS_PREFIX.$index])) {
                    throw new Exception("User $u already exists, please choose another name.");
                }
                if(!AuthService::userExists($u, "r") && !empty($this->tmpUsersPrefix) && strpos($u, $this->tmpUsersPrefix)!==0 ){
                    $u = $this->tmpUsersPrefix . $u;
                }
                $entry = array("ID" => $u, "TYPE" => "user");

            } else {

                $u = AJXP_Utils::decodeSecureMagic($httpVars[PARAM_USER_LOGIN_PREFIX.$index]);

                if (strpos($u, "/AJXP_TEAM/") === 0) {

                    $confDriver = ConfService::getConfStorageImpl();
                    if (method_exists($confDriver, "teamIdToUsers")) {
                        $teamUsers = $confDriver->teamIdToUsers(str_replace("/AJXP_TEAM/", "", $u));
                        foreach ($teamUsers as $userId) {
                            $users[$userId] = array("ID" => $userId, "TYPE" => "user", "RIGHT" => $rightString);
                            if ($this->watcher !== false) {
                                $users[$userId]["WATCH"] = $uWatch;
                            }
                        }
                    }
                    $index++;
                    continue;

                }

                $entry = array("ID" => $u, "TYPE" => "group");

            }
            $entry["RIGHT"] = $rightString;
            $entry["PASSWORD"] = isSet($httpVars[PARAM_USER_PASS_PREFIX.$index])?$httpVars[PARAM_USER_PASS_PREFIX.$index]:"";
            if ($this->watcher !== false) {
                $entry["WATCH"] = $uWatch;
            }
            if($entry["TYPE"] == "user") {
                $users[$entry["ID"]] = $entry;
            }else{
                $groups[$entry["ID"]] = $entry;
            }
            $index ++;

        }

    }

    /**
     * @param String $repoId
     * @param bool $mixUsersAndGroups
     * @param AJXP_Node|null $watcherNode
     * @return array
     */
    public function computeSharedRepositoryAccessRights($repoId, $mixUsersAndGroups, $watcherNode = null)
    {
        $roles = AuthService::getRolesForRepository($repoId);
        $sharedEntries = $sharedGroups = $sharedRoles = array();
        $mess = ConfService::getMessages();
        foreach($roles as $rId){
            $role = AuthService::getRole($rId);
            if ($role == null) continue;

            $RIGHT = $role->getAcl($repoId);
            if (empty($RIGHT)) continue;
            $ID = $rId;
            $WATCH = false;
            if(strpos($rId, "AJXP_USR_/") === 0){
                $userId = substr($rId, strlen('AJXP_USR_/'));
                $role = AuthService::getRole($rId);
                $userObject = ConfService::getConfStorageImpl()->createUserObject($userId);
                $LABEL = $role->filterParameterValue("core.conf", "USER_DISPLAY_NAME", AJXP_REPO_SCOPE_ALL, "");
                if(empty($LABEL)) $LABEL = $userId;
                $TYPE = $userObject->hasParent()?"tmp_user":"user";
                if ($this->watcher !== false && $watcherNode != null) {
                    $WATCH = $this->watcher->hasWatchOnNode(
                        $watcherNode,
                        $userId,
                        MetaWatchRegister::$META_WATCH_USERS_NAMESPACE
                    );
                }
                $ID = $userId;
            }else if($rId == "AJXP_GRP_/"){
                $rId = "AJXP_GRP_/";
                $TYPE = "group";
                $LABEL = $mess["447"];
            }else if(strpos($rId, "AJXP_GRP_/") === 0){
                if(empty($loadedGroups)){
                    $displayAll = ConfService::getCoreConf("CROSSUSERS_ALLGROUPS_DISPLAY", "conf");
                    if($displayAll){
                        AuthService::setGroupFiltering(false);
                    }
                    $loadedGroups = AuthService::listChildrenGroups();
                    if($displayAll){
                        AuthService::setGroupFiltering(true);
                    }else{
                        $baseGroup = AuthService::filterBaseGroup("/");
                        foreach($loadedGroups as $loadedG => $loadedLabel){
                            unset($loadedGroups[$loadedG]);
                            $loadedGroups[rtrim($baseGroup, "/")."/".ltrim($loadedG, "/")] = $loadedLabel;
                        }
                    }
                }
                $groupId = substr($rId, strlen('AJXP_GRP_'));
                if(isSet($loadedGroups[$groupId])) {
                    $LABEL = $loadedGroups[$groupId];
                }
                if($groupId == "/"){
                    $LABEL = $mess["447"];
                }
                if(empty($LABEL)) $LABEL = $groupId;
                $TYPE = "group";
            }else{
                $role = AuthService::getRole($rId);
                $LABEL = $role->getLabel();
                $TYPE = 'group';
            }

            if(empty($LABEL)) $LABEL = $rId;
            $entry = array(
                "ID"    => $ID,
                "TYPE"  => $TYPE,
                "LABEL" => $LABEL,
                "RIGHT" => $RIGHT
            );
            if($WATCH) $entry["WATCH"] = $WATCH;
            if($TYPE == "group"){
                $sharedGroups[$entry["ID"]] = $entry;
            } else {
                $sharedEntries[$entry["ID"]] = $entry;
            }
        }

        if (!$mixUsersAndGroups) {
            return array("USERS" => $sharedEntries, "GROUPS" => $sharedGroups);
        }else{
            return array_merge(array_values($sharedGroups), array_values($sharedEntries));

        }
    }

    /**
     * @param string $repoId
     * @param array $newUsers
     * @param array $newGroups
     * @param AJXP_Node|null $watcherNode
     */
    public function unregisterRemovedUsers($repoId, $newUsers, $newGroups, $watcherNode = null){

        $confDriver = ConfService::getConfStorageImpl();

        $currentRights = $this->computeSharedRepositoryAccessRights(
            $repoId,
            false,
            $watcherNode
        );

        $originalUsers = array_keys($currentRights["USERS"]);
        $removeUsers = array_diff($originalUsers, array_keys($newUsers));
        if (count($removeUsers)) {
            foreach ($removeUsers as $user) {
                if (AuthService::userExists($user)) {
                    $userObject = $confDriver->createUserObject($user);
                    $userObject->personalRole->setAcl($repoId, "");
                    $userObject->save("superuser");
                }
                if($this->watcher !== false){
                    $this->watcher->removeWatchFromFolder(
                        $watcherNode,
                        $user,
                        true
                    );
                }
            }
        }
        $originalGroups = array_keys($currentRights["GROUPS"]);
        $removeGroups = array_diff($originalGroups, array_keys($newGroups));
        if (count($removeGroups)) {
            foreach ($removeGroups as $groupId) {
                $role = AuthService::getRole($groupId);
                if ($role !== false) {
                    $role->setAcl($repoId, "");
                    AuthService::updateRole($role);
                }
            }
        }

    }

    /**
     * @param AbstractAjxpUser $parentUser
     * @param string $userName
     * @param string $password
     * @param string $display
     * @param bool $isHidden
     * @return AbstractAjxpUser
     * @throws Exception
     */
    public function createNewUser($parentUser, $userName, $password, $display, $isHidden){

        $confDriver = ConfService::getConfStorageImpl();
        if (ConfService::getAuthDriverImpl()->getOptionAsBool("TRANSMIT_CLEAR_PASS")) {
            $pass = $password;
        } else {
            $pass = md5($password);
        }
        if(!$isHidden){
            // This is an explicit user creation - check possible limits
            AJXP_Controller::applyHook("user.before_create", array($userName, null, false, false));
            $limit = $parentUser->mergedRole->filterParameterValue("core.conf", "USER_SHARED_USERS_LIMIT", AJXP_REPO_SCOPE_ALL, "");
            if (!empty($limit) && intval($limit) > 0) {
                $count = count($confDriver->getUserChildren($parentUser->getId()));
                if ($count >= $limit) {
                    $mess = ConfService::getMessages();
                    throw new Exception($mess['483']);
                }
            }
        }
        AuthService::createUser($userName, $pass, false, $isHidden);
        $userObject = $confDriver->createUserObject($userName);
        $userObject->personalRole->clearAcls();
        $userObject->setParent($parentUser->getId());
        $userObject->setGroupPath($parentUser->getGroupPath());
        $userObject->setProfile("shared");
        if($isHidden){
            $mess = ConfService::getMessages();
            $userObject->setHidden(true);
            $userObject->personalRole->setParameterValue("core.conf", "USER_DISPLAY_NAME", $display);
        }
        AJXP_Controller::applyHook("user.after_create", array($userObject));

        return $userObject;

    }


    /**
     * @param AbstractAjxpUser $parentUser
     * @param string $parentRepoId
     * @param AJXP_Node $ajxpNode
     * @return AJXP_PermissionMask|null
     */
    public function forkMaskIfAny($parentUser, $parentRepoId, $ajxpNode){

        $file = $ajxpNode->getPath();
        if($file != "/" && $parentUser->mergedRole->hasMask($parentRepoId)){
            $parentTree = $parentUser->mergedRole->getMask($parentRepoId)->getTree();
            // Try to find a branch on the current selection
            $parts = explode("/", trim($file, "/"));
            while( ($next = array_shift($parts))  !== null){
                if(is_array($parentTree) && isSet($parentTree[$next])) {
                    $parentTree = $parentTree[$next];
                }else{
                    $parentTree = null;
                    break;
                }
            }
            if($parentTree != null){
                $newMask = new AJXP_PermissionMask();
                $newMask->updateTree($parentTree);
            }
            if(isset($newMask)){
                return $newMask;//$childUser->personalRole->setMask($childRepoId, $newMask);
            }
        }
        return null;

    }

    /**
     * @param string $repositoryId
     * @param bool $disableDownload
     * @param bool $replace
     * @return AJXP_Role|null
     */
    public function createRoleForMinisite($repositoryId, $disableDownload, $replace){
        if($replace){
            try{
                AuthService::deleteRole("AJXP_SHARED-".$repositoryId);
            }catch (Exception $e){}
        }
        $newRole = new AJXP_Role("AJXP_SHARED-".$repositoryId);
        $r = AuthService::getRole("MINISITE");
        if (is_a($r, "AJXP_Role")) {
            if ($disableDownload) {
                $f = AuthService::getRole("MINISITE_NODOWNLOAD");
                if (is_a($f, "AJXP_Role")) {
                    $r = $f->override($r);
                }
            }
            $allData = $r->getDataArray();
            $newData = $newRole->getDataArray();
            if(isSet($allData["ACTIONS"][AJXP_REPO_SCOPE_SHARED])) $newData["ACTIONS"][$repositoryId] = $allData["ACTIONS"][AJXP_REPO_SCOPE_SHARED];
            if(isSet($allData["PARAMETERS"][AJXP_REPO_SCOPE_SHARED])) $newData["PARAMETERS"][$repositoryId] = $allData["PARAMETERS"][AJXP_REPO_SCOPE_SHARED];
            $newRole->bunchUpdate($newData);
            AuthService::updateRole($newRole);
            return $newRole;
//            $userObject->addRole($newRole);
        }
        return null;
    }

}