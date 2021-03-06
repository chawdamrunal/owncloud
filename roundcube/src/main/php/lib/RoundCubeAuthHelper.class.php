<?php

/**
 * ownCloud - roundcube auth helper
 *
 * @author Martin Reinhardt
 * @copyright 2013 Martin Reinhardt contact@martinreinhardt-online.de
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */
class OC_RoundCube_AuthHelper
{

    /**
     *
     * @param            
     *
     * @param
     *            s array with a mutable array inside, for
     *            storing the variable-value pairs.
     *            
     */
    public static function jsLoadHook($params)
    {
        OCP\App::checkAppEnabled('roundcube');
        $jsAssign = &$params['array'];
        
        $refresh = OCP\Config::getAppValue('roundcube', 'rcRefreshInterval', 240);
        $jsAssign['Roundcube'] = 'Roundcube || {};' . "\n" . 'Roundcube.refreshInterval = ' . $refresh;
    }

    /**
     * Login into roundcube server
     *
     * @param $params userdata            
     * @return true if login was succesfull otherwise false
     */
    public static function login($params, $optionalparams = null)
    {
        $via = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        if (preg_match('#(/ocs/v1.php|'.
                       '/apps/calendar/caldav.php|'.
                       '/apps/contacts/carddav.php|'.
                   '/remote.php/webdav)/#', $via)) {
            return;
        }
        OCP\App::checkAppEnabled('roundcube');
        try {
            if (is_array($params) )
            {
                $username = $params['uid'];
                if (array_key_exists('password', $params))
                    $password = $params['password'];
                else
                    $password = $optionalparams;
            }
            else
            {
                $username = $params;
                $password = $optionalparams;
            }
            // TODO : here are an error when admin change settings.. roundcube logout and then can login..
            OCP\Util::writeLog('roundcube', 'OC_RoundCube_AuthHelper.class.php->login(): Preparing login of roundcube user "' . $username . '"', OCP\Util::DEBUG);
            
            $maildir = OCP\Config::getAppValue('roundcube', 'maildir', '');
            $rc_host = self::getServerHost();
            $rc_port = OCP\Config::getAppValue('roundcube', 'rcPort', '');
            $enable_auto_login = OCP\Config::getAppValue('roundcube', 'autoLogin', false);
            if ($enable_auto_login) {
                OCP\Util::writeLog('roundcube', 'OC_RoundCube_AuthHelper.class.php->login(): Starting auto login', OCP\Util::DEBUG);
                // SSO attempt
                $mail_username = $username;
                $mail_password = $password;
            } else {
                OCP\Util::writeLog('roundcube', 'OC_RoundCube_AuthHelper.class.php->login(): Starting manual login', OCP\Util::DEBUG);
                $privKey = OC_RoundCube_App::getPrivateKey($username, $password);
                // Fetch credentials from data-base
                $mail_userdata_entries = OC_RoundCube_App::checkLoginData($username);
                // TODO create dropdown list
                $mail_userdata = $mail_userdata_entries[0];
                $mail_username = OC_RoundCube_App::decryptMyEntry($mail_userdata['mail_user'], $privKey);
                OCP\Util::writeLog('roundcube', 'OC_RoundCube_AuthHelper.class.php->login(): Used roundcube user: ' . $mail_username, OCP\Util::DEBUG);
                $mail_password = OC_RoundCube_App::decryptMyEntry($mail_userdata['mail_password'], $privKey);
            }
            // save username for displaying in later usage
            OC_RoundCube_App::setSessionVariable(OC_RoundCube_App::SESSION_ATTR_RCUSER, $mail_username);
            // login
            return OC_RoundCube_App::login($rc_host, $rc_port, $maildir, $mail_username, $mail_password);
        } catch (Exception $e) {
            // We got an exception == table not found
            OCP\Util::writeLog('roundcube', 'OC_RoundCube_AuthHelper.class.php->login(): Login error. ', OCP\Util::ERROR);
            return false;
        }
    }

    /**
     * Logout from roundcube server to cleaning up session on OwnCloud logout
     *
     * @return boolean true if logut was successfull, otherwise false
     */
    public static function logout()
    {
        $via = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        if (preg_match('#(/ocs/v1.php|'.
                       '/apps/calendar/caldav.php|'.
                       '/apps/contacts/carddav.php|'.
                   '/remote.php/webdav)/#', $via)) {
            return;
        }
        OCP\App::checkAppEnabled('roundcube');
        try {
            OCP\Util::writeLog('roundcube', 'OC_RoundCube_AuthHelper.class.php->logout(): Preparing logout of user from roundcube.', OCP\Util::DEBUG);
            $maildir = OCP\Config::getAppValue('roundcube', 'maildir', '');
            $rc_host = self::getServerHost();
            $rc_port = OCP\Config::getAppValue('roundcube', 'rcPort', '');
            
            OC_RoundCube_App::logout($rc_host, $rc_port, $maildir, OCP\User::getUser());
            OCP\Util::writeLog('roundcube', 'Logout of user ' . OCP\User::getUser() . ' from roundcube done', OCP\Util::INFO);
            return true;
        } catch (Exception $e) {
            // We got an exception == table not found
            OCP\Util::writeLog('roundcube', 'OC_RoundCube_AuthHelper.class.php->logout(): Logout/Session cleaning causing errors.', OCP\Util::DEBUG);
            return false;
        }
    }

    /**
     * Refreshs the roundcube HTTP session
     *
     * @return boolean true if refresh was successfull, otherwise false
     */
    public static function refresh()
    {
        $via = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        if (preg_match('#(/ocs/v1.php|'.
                       '/apps/calendar/caldav.php|'.
                       '/apps/contacts/carddav.php|'.
                   '/remote.php/webdav)/#', $via)) {
            return;
        }
        try {
            OCP\Util::writeLog('roundcube', 'OC_RoundCube_AuthHelper.class.php->refresh(): Preparing refresh for roundcube', OCP\Util::DEBUG);
            $maildir = OCP\Config::getAppValue('roundcube', 'maildir', '');
            $rc_host = self::getServerHost();
            $rc_port = OCP\Config::getAppValue('roundcube', 'rcPort', '');
            OC_RoundCube_App::refresh($rc_host, $rc_port, $maildir);
            OCP\Util::writeLog('roundcube', 'OC_RoundCube_AuthHelper.class.php->refresh(): Finished refresh for roundcube', OCP\Util::DEBUG);
            return true;
        } catch (Exception $e) {
            // We got an exception during login/refresh
            OCP\Util::writeLog('roundcube', 'OC_RoundCube_AuthHelper.class.php: ' . 'Login error during refresh.' /* . $e */, OCP\Util::DEBUG);
            return false;
        }
    }

    /**
     * listener which gets invoked if password is changed within owncloud
     *
     * @param unknown $params
     *            userdata
     */
    public static function changePasswordListener($params)
    {
        $username = $params['uid'];
        $password = $params['password'];
        
        // Try to fetch from session
        $oldPrivKey = OC_RoundCube_App::getSessionVariable(OC_RoundCube_App::SESSION_ATTR_RCPRIVKEY);
        // Take the chance to alter the priv/pubkey pair
        OC_RoundCube_App::generateKeyPair($username, $password);
        $privKey = OC_RoundCube_App::getPrivateKey($username, $password);
        $pubKey = OC_RoundCube_App::getPublicKey($username);
        if ($oldPrivKey !== false) {
            // Fetch credentials from data-base
            $mail_userdata_entries = OC_RoundCube_App::checkLoginData($username);
            foreach ($mail_userdata_entries as $mail_userdata) {
                $mail_username = OC_RoundCube_App::decryptMyEntry($mail_userdata['mail_user'], $oldPrivKey);
                $mail_password = OC_RoundCube_App::decryptMyEntry($mail_userdata['mail_password'], $oldPrivKey);
                OC_RoundCube_App::cryptEmailIdentity($username, $mail_username, $mail_password);
                OCP\Util::writeLog('roundcube', 'OC_RoundCube_AuthHelper.class.php->changePasswordListener():' . 'Updated mail password data due to password changed for user ' . $username, OCP\Util::DEBUG);
            }
        } else {
            OCP\Util::writeLog('roundcube', 'OC_RoundCube_AuthHelper.class.php->changePasswordListener():' . 'No private key for ' . $username, OCP\Util::DEBUG);
        }
    }

    public static function getServerHost()
    {
        $rc_host = OCP\Config::getAppValue('roundcube', 'rcHost', '');
        if ($rc_host == '') {
            $rc_host = OCP\Util::getServerHost();
        }
        OCP\Util::writeLog('roundcube', 'OC_RoundCube_AuthHelper.class.php->getServerHost():' . ' rcHost: ' . $rc_host, OCP\Util::DEBUG);
        return $rc_host;
    }
}
