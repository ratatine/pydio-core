<?php
/*
 * Copyright 2007-2013 Charles du Jeu - Abstrium SAS <team (at) pyd.io>
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
defined('AJXP_EXEC') or die( 'Access not allowed');
require_once('../classes/class.AbstractTest.php');

/**
 * Check that DOMXml is enabled
 * @package Pydio
 * @subpackage Tests
 */
class PHPDomXML extends AbstractTest
{
    public function __construct() { parent::__construct("DOM Xml enabled", "Dom XML is required, you may have to install the php-xml extension."); }
    public function doTest()
    {
        $this->failedLevel = "error";
        if (!class_exists("DOMDocument")) {
            $this->testedParams["DOM Enabled"] = "No";
            return FALSE;
        }
        $this->testedParams["DOM Enabled"] = "Yes";
        return TRUE;
    }
}