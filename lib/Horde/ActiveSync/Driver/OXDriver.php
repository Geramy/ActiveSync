<?php
include_once ('../ox/lib/OXConnector.php');
include_once ('../ox/lib/OXUtils.php');
include_once ('../ox/lib/TimezoneConverter/fake.php');
include_once ('../ox/lib/TimezoneConverter/DateTime.php');
include_once ('../ox/lib/TimezoneConverter/Exception.php');
include_once ('../ox/lib/TimezoneConverter/TimezoneNotFoundException.php');
include_once ('../ox/lib/TimezoneConverter/TimezoneConverter.php');
include_once ('../ox/mail/emails.php');
include_once ('../ox/contacts/contacts.php');
include_once ('../ox/calendar/calendar.php');
include_once ('../ox/lib/default/diffbackend/diffbackend.php');
include_once ('../ox/include/mimeDecode.php');
require_once ('../ox/include/z_RFC822.php');
require_once ('../ox/lib/utils/timezoneutil.php');
require_once ('../ox/HTTP/Request2.php');

/**
 * Horde_ActiveSync_Driver_Base::
 *
 * @license   http://www.horde.org/licenses/gpl GPLv2
 *            NOTE: According to sec. 8 of the GENERAL PUBLIC LICENSE (GPL),
 *            Version 2, the distribution of the Horde_ActiveSync module in or
 *            to the United States of America is excluded from the scope of this
 *            license.
 * @copyright 2018-2020 JQluv.net, Inc.
 * @author    Geramy L Loveless <geramy.loveless@jqluvhost.net>
 * @package   OX ActiveSync
 */
/**
 * Base ActiveSync Driver backend. Provides communication with the actual
 * server backend that ActiveSync will be syncing devices with. This is an
 * class, servers must implement their own backend to provide
 * the needed data.
 */
class Horde_ActiveSync_Driver_OXDriver extends Horde_ActiveSync_Driver_Base
{
    /**
     *  Server folder ids for non-email folders.
     *  We use the @ modifiers to avoid issues in the (fringe) case of
     *  having email folders named like contacts etc...
     */
    const APPOINTMENTS_FOLDER_UID = '@Calendar@';
    const CONTACTS_FOLDER_UID     = '@Contacts@';
    const TASKS_FOLDER_UID        = '@Tasks@';
    const NOTES_FOLDER_UID        = '@Notes@';
    const SPECIAL_SENT   = 'default0/Sent Items';
    const SPECIAL_SPAM   = 'default0/Spam';
    const SPECIAL_TRASH  = 'default0/Trash';
    const SPECIAL_DRAFTS = 'default0/Drafts';
    const SPECIAL_INBOX  = 'default0/INBOX';

    protected $_auth;
    protected $_connector;
    protected $_imap;
    protected $_displayMap = array(
        self::APPOINTMENTS_FOLDER_UID => 'Calendar',
        self::CONTACTS_FOLDER_UID     => 'Contacts',
        self::TASKS_FOLDER_UID        => 'Tasks',
        self::NOTES_FOLDER_UID        => 'Notes',
    );

    private $session = false;
    private $cookiejar = true;
    private $root_folder = array();
    private $OXConnector;
    private $OXUtils;
    private $EmailSync;
    private $ContactSync;
    private $CalendarSync;
    private $TZconverter;

    public function __construct($params = array())
    {
        parent::__construct($params);
        $this->_connector = $params['connector'];
        //$this->_auth = $params['auth'];
        $this->_imap = $params['imap'];
        $this -> OXConnector = new OXConnector();
        $this -> OXUtils = new OXUtils();
    }

        /**
     * Any code needed to authenticate to backend as the actual user.
     *
     * @param string $username  The username to authenticate as
     * @param string $password  The password
     * @param string $domain    The user domain (unused in this driver).
     *
     * @return mixed  Boolean true on success, boolean false on credential
     *                failure or Horde_ActiveSync::AUTH_REASON_*
     *                constant on policy failure.
     */
    public function authenticate($username, $password, $domain = null)
    {
        //Implemented OXDriver Version - Done!
        $this->_authUser = $username;
        $this->_authPass = $password;
        $response = $this -> OXConnector -> OXreqPOST('/ajax/login?action=login', array('name' => $username, 'password' => $password, ));
        if ($response) {
            if (array_key_exists("session", $response)) {
                $this -> OXConnector -> setSession($response["session"]);
                $this->_logger->log(LOGLEVEL_DEBUG, "OXDriver::authenticate() - Login successfully, get SessionID: " . $this -> session);

                $this -> EmailSync = new OXEmailSync($this -> OXConnector, $this -> OXUtils);
                $this -> ContactSync = new OXContactSync($this -> OXConnector, $this -> OXUtils);
                $this -> CalendarSync = new OXCalendarSync($this -> OXConnector, $this -> OXUtils);

                return true;
            }
        }
        return true;
    }

    /**
     * Delete a folder on the server.
     *
     * @param string $id  The server's folder id.
     * @param string $parent  The folder's parent, if needed.
     */
    public function deleteFolder($id, $parent = Horde_ActiveSync::FOLDER_ROOT) {  }

    /**
     * Change a folder on the server.
     *
     * @param string $id           The server's folder id
     * @param string $displayname  The new display name.
     * @param string $parent       The folder's parent, if needed.
     * @param string $uid          The existing folder uid, if this is an edit.
     *                             @since 2.5.0 (@todo Look at this for H6. It's
     *                             here now to save an extra DB lookup for data
     *                             we already have.)
     */
    public function changeFolder($id, $displayname, $parent, $uid = null)
    {
        return $uid;
    }

    /**
     * Move message
     *
     * @param string $folderid     Existing folder id
     * @param array $ids           Message UIDs
     * @param string $newfolderid  The new folder id
     *
     * @return array  The new uids for the message.
     */
    public function moveMessage($folderid, array $ids, $newfolderid)
    {
        return $ids;
    }

    /**
     * Returns array of items which contain contact information
     *
     * @param string $type   The search type; ['gal'|'mailbox']
     * @param array $query   The search query. An array containing:
     *  - query: (string) The search term.
     *           DEFAULT: none, REQUIRED
     *  - range: (string)   A range limiter.
     *           DEFAULT: none (No range used).
     *
     * @return array  An array containing:
     *  - rows:   An array of search results
     *  - status: The search store status code.
     */
    public function getSearchResults($type, array $query)
    {
        return array();
    }

    /**
     * Stat folder. Note that since the only thing that can ever change for a
     * folder is the name, we use that as the 'mod' value.
     *
     * @param string $id     The folder id
     * @param mixed $parent  The parent folder (or 0 if none).
     * @param mixed $mod     Modification indicator. For folders, this is the
     *                       name of the folder, since that's the only thing
     *                       that can change.
     * @return a stat hash
     */
    public function statFolder($id, $parent = 0, $mod = null)
    {
        //Implemented OXDriver Version - Done!
        $this->_logger->log(LOGLEVEL_DEBUG, 'OXDriver::StatFolder(' . $id . ')');
        $folder = $this ->getFolder($id);
    
        $stat = array();
        $stat["id"] = $id;
        $stat["parent"] = $folder->parentid;
        $stat["mod"] = $folder->displayname;
        $stat["serverid"] = $id;
        return $stat;
    }

    /**
     * Return the ActiveSync message object for the specified folder.
     *
     * @param string $id  The folder's server id.
     *
     * @return Horde_ActiveSync_Message_Folder object.
     */
    public function getFolder($id)
    {
        //Implemented OXDriver Version - Done!
        $this->_logger->log(LOGLEVEL_DEBUG, "OXDriver::getFolder() - SessionID: " . $this -> session);
        // Get the list of calendar and contact folders:
        /*	Name of the module which implements this folder; e.g. "tasks", "calendar", "contacts", "infostore", or "mail"*/
        $response = $this -> OXConnector -> OXreqGET('/ajax/folders', array('action' => 'get', 'session' => $this -> OXConnector -> getSession(), 'id' => $id,
        //http://oxpedia.org/wiki/index.php?title=HTTP_API#CommonFolderData
        'columns' => '1,20,300,301,316', //objectID�| parentfolderID | title | module/type
        ));
        if ($response) {

            $this->_logger->log(LOGLEVEL_DEBUG, 'OXDriver::getFolder(' . $id . ') title: ' . $response["data"]["title"] . ' module: ' . $response["data"]["module"]);
            $serverid = $id;
            if (array_key_exists($response["data"]["folder_id"], $this -> root_folder) || $response["data"]["folder_id"] == "default0") {
                $parentid = "0";
            } else {
                $parentid = $response["data"]["folder_id"];
            }
            $displayname = $response["data"]["title"];
            switch ($response["data"]["module"]) {
                case "contacts" :
                    $type = Horde_ActiveSync::FOLDER_TYPE_CONTACT;
                    break;
                case "calendar" :
                    $type = Horde_ActiveSync::FOLDER_TYPE_APPOINTMENT;
                    break;
                case "mail" :
                    $this->_logger->log(LOGLEVEL_DEBUG, "Mail folder: " . $id . " FolderType : " . $response["data"]["standard_folder_type"]);
                    switch ( $response["data"]["standard_folder_type"] ) {
                        case "7" :
                            $type = Horde_ActiveSync::FOLDER_TYPE_INBOX;
                            break;
                        case "9" :
                            $type = Horde_ActiveSync::FOLDER_TYPE_DRAFTS;
                            break;
                        case "10" :
                            $type = Horde_ActiveSync::FOLDER_TYPE_SENTMAIL;
                            break;
                        case "12" :
                            $type = Horde_ActiveSync::FOLDER_TYPE_WASTEBASKETT;
                            break;
                        default :
                            $type = Horde_ActiveSync::FOLDER_TYPE_USER_MAIL;
                            break;
                    }
                    break;
                default:
                    return false;
                    break;
            }
            return $folder = $this->_buildNonMailFolder($serverid, $parentid, $type, $displayname);
        }
        return false;
        //throw new Horde_ActiveSync_Exception('Folder ' . $id . ' unknown');
    }

    protected function _getFolderUidForBackendId($sid, $type = null, $old_id = null)
    {
        switch ($sid) {
        case 'INBOX':
            return '519422f1-4c5c-4547-946a-1701c0a8015f';
        default:
            return $sid;
        }
    }

    /**
     * Helper to build a folder object for non-email folders.
     *
     * @param string $id      The folder's server id.
     * @param stirng $parent  The folder's parent id.
     * @param integer $type   The folder type.
     * @param string $name    The folder description.
     *
     * @return  Horde_ActiveSync_Message_Folder  The folder object.
     */
    protected function _buildNonMailFolder($id, $parent, $type, $name)
    {
        //Implemented OXDriver Version - Done!
        $folder = new Horde_ActiveSync_Message_Folder();
        $folder->serverid = $id;
        $folder->parentid = $parent;
        $folder->type = $type;
        $folder->displayname = $name;

        return $folder;
    }

    /**
     * Get the list of folder stat arrays @see self::statFolder()
     *
     * @return array  An array of folder stat arrays.
     */
    public function getFolderList()
    {
        //Implemented OXDriver Version - Done!
        $folderlist = $this->getFolders();
        $folders = array();
        foreach ($folderlist as $f) {
            $folders[] = $this->statFolder($f->serverid, $f->parentid);
        }

        return $folders;
    }

    /**
     * Return an array of the server's folder objects.
     *
     * @return array  An array of Horde_ActiveSync_Message_Folder objects.
     */
    public function getFolders()
    {
        //Implemented OXDriver Version - Done!
        $this->_logger->log(LOGLEVEL_DEBUG, 'OXDriver::GetFolderList() - start: ');
        if (empty($this->_folders)) {
            try {
                $supported = $this->_connector->listApis();
            } catch (Exception $e) {
                return array();
            }
            $folder_list = array();
            /* We need to build Horde Message Folders for all the results. */
            //$this->_folders = $folders;
            //$folder = $this->_buildNonMailFolder($serverid, $parentid, $type, $displayname); //1,20,300,301,316
            if (array_search('calendar', $supported) !== false) {
                $type = "calendar";
                $response = $this -> OXConnector -> OXreqGET('/ajax/folders', array('action' => 'root', 'session' => $this -> OXConnector -> getSession(), 'allowed_modules' => $type,
                //http://oxpedia.org/wiki/index.php?title=HTTP_API#CommonFolderData
                'columns' => '1', ));
                if ($response) {
                    foreach ($response["data"] as &$root_folder) {
                        $root_folder = $root_folder[0];
                        $this -> root_folder[] = $root_folder;
                        $folderlist = $this -> GetSubFolders($root_folder, $type);
                        foreach ($folderlist as &$folderid) {
                            $folder = $this -> statFolder($folderid);
                            # custom folders for non email folders is only supported by eas >= 12
                            # all folders with parent == 1 are the default folders
                            if (Request::GetProtocolVersion() >= 12.0 or $folder['parent'] == 1){
                                $folder_list[] = $folder;
                            }
                        }
                    }
                }
            }

            if (array_search('contacts', $supported) !== false) {
                $type = "contacts";
                $response = $this -> OXConnector -> OXreqGET('/ajax/folders', array('action' => 'root', 'session' => $this -> OXConnector -> getSession(), 'allowed_modules' => $type,
                //http://oxpedia.org/wiki/index.php?title=HTTP_API#CommonFolderData
                'columns' => '1', ));
                if ($response) {
                    foreach ($response["data"] as &$root_folder) {
                        $root_folder = $root_folder[0];
                        $this -> root_folder[] = $root_folder;
                        $folderlist = $this -> GetSubFolders($root_folder, $type);
                        foreach ($folderlist as &$folderid) {
                            $folder = $this -> statFolder($folderid);
                            # custom folders for non email folders is only supported by eas >= 12
                            # all folders with parent == 1 are the default folders
                            if (Request::GetProtocolVersion() >= 12.0 or $folder['parent'] == 1){
                                $folder_list[] = $folder;
                            }
                        }
                    }
                }
            }

            if (array_search('tasks', $supported) !== false) {
                $type = "tasks";
                $response = $this -> OXConnector -> OXreqGET('/ajax/folders', array('action' => 'root', 'session' => $this -> OXConnector -> getSession(), 'allowed_modules' => $type,
                //http://oxpedia.org/wiki/index.php?title=HTTP_API#CommonFolderData
                'columns' => '1', ));
                if ($response) {
                    foreach ($response["data"] as &$root_folder) {
                        $root_folder = $root_folder[0];
                        $this -> root_folder[] = $root_folder;
                        $folderlist = $this -> GetSubFolders($root_folder, $type);
                        foreach ($folderlist as &$folderid) {
                            $folder = $this -> statFolder($folderid);
                            # custom folders for non email folders is only supported by eas >= 12
                            # all folders with parent == 1 are the default folders
                            if (Request::GetProtocolVersion() >= 12.0 or $folder['parent'] == 1){
                                $folder_list[] = $folder;
                            }
                        }
                    }
                }
            }

            if (array_search('notes', $supported) !== false) {
                /* I dont know if OX Supports Notes? */
               // $folders[] = $this->getFolder(self::NOTES_FOLDER_UID);
            }

            if (array_search('mail', $supported) !== false) {

                $response = $this -> OXConnector -> OXreqGET('/ajax/folders', array('action' => 'list', 'parent' => 'default0', // personal email folder ?
                'session' => $this -> OXConnector -> getSession(), 'allowed_modules' => 'mail',
                //http://oxpedia.org/wiki/index.php?title=HTTP_API#CommonFolderData
                'columns' => '1', ));
            
                $this->_logger->log(LOGLEVEL_DEBUG, 'OXDriver::GetFolderList() - mailrepsonse: ' . print_r($response, true));
            
                if ($response) {
                    foreach ($response["data"] as &$root_folder) {
                        $root_folder = $root_folder[0];
                        $this->_logger->log(LOGLEVEL_DEBUG, "root_folder: " . $root_folder);
                        $this -> root_folder[] = $root_folder;
                        $folderlist = $this -> GetSubFolders($root_folder);
                        $folder_list[] = $this -> statFolder($root_folder);
                        foreach ($folderlist as &$folderid) {
                            if (!is_numeric($folderid)) {
                                $folder_list[] = $this -> statFolder($folderid);
                                $this->_logger->log(LOGLEVEL_DEBUG, "folder: " . $folderid);
                            }
                        }
                    }
                }
                else {
                    return array();
                }
            }
            $this->_folders = $folder_list;
        }
        $this->_logger->log(LOGLEVEL_DEBUG, 'OXDriver::GetFolderList() - folder_list: ' . print_r($folder_list, true));
        return $this->_folders;
    }

    private function GetSubFolders($id, $type) {
        //Implemented OXDriver Version - Done!
        $this->_logger->log(LOGLEVEL_DEBUG, 'OXDriver::GetSubFolders(' . $id . ')');
        $lst = array();
        $response = $this -> OXConnector -> OXreqGET('/ajax/folders', array('action' => 'list', 'session' => $this -> OXConnector -> getSession(),
        'parent' => $id, 'columns' => '1,301', "allowed_modules" => $type ));

        $this->_logger->log(LOGLEVEL_DEBUG, 'OXDriver::GetSubFolder(' . $id . ') - response: ' . print_r($response, true));

        foreach ($response["data"] as &$folder) {
            // restrict to contacts | calendar | mail
            if (in_array($folder[1], array($type))) {
                $lst[] = $folder[0];
                $subfolders = $this -> GetSubFolders($folder[0], $type);
                foreach ($subfolders as &$subfolder) {
                    $lst[] = $subfolder;
                }
            }
        }

        $this->_logger->log(LOGLEVEL_DEBUG, 'OXDriver::GetSubFolder() - lst: ' . print_r($lst, true));

        return $lst;
    }

    /**
     * Get a list of server changes that occured during the specified time
     * period.
     *
     * @param string $folderId     The server id of the collection to check.
     * @param integer $from_ts     The starting timestamp.
     * @param integer $to_ts       The ending timestamp.
     * @param integer $cutoffdate  The earliest date to retrieve back to.
     * @param boolean $ping        If true, returned changeset may
     *                             not contain the full changeset, may only
     *                             contain a single change, designed only to
     *                             indicate *some* change has taken place. The
     *                             value should not be used to determine *what*
     *                             change has taken place.
     *
     * @return array A list of messge uids that have chnaged in the specified
     *               time period.
     */
    public function getServerChanges($folderId, $from_ts, $to_ts, $cutoffdate, $ping)
    {

        $changes = array(
            'add' => array(),
            'delete' => array(),
            'modify' => array()
        );
        if ($from_ts == 0 && !$ignoreFirstSync) {
            $startstamp = (int)$cutoffdate;
            $endstamp = time() + 32140800; //60 * 60 * 24 * 31 * 12 == one year
            $changes['add'] = $this->_connector->listUids($startstamp, $endstamp);
        } else {
            $changes = $this->_connector->getChanges($folderId, $from_ts, $to_ts);
        }

        $results = array();
        foreach ($changes['add'] as $add) {
            $results[] = array(
                'id' => $add,
                'type' => Horde_ActiveSync::CHANGE_TYPE_CHANGE,
                'flags' => Horde_ActiveSync::FLAG_NEWMESSAGE);
        }

        // For CLASS_EMAIL, all changes are a change in flags.
        if ($folder->collectionClass() == Horde_ActiveSync::CLASS_EMAIL) {
            $flags = $folder->flags();
            foreach ($changes['modify'] as $uid) {
                $results[] = array(
                    'id' => $uid,
                    'type' => Horde_ActiveSync::CHANGE_TYPE_FLAGS,
                    'flags' => $flags[$uid]
                );
            }
        } else {
            foreach ($changes['modify'] as $change) {
                $results[] = array(
                    'id' => $change,
                    'type' => Horde_ActiveSync::CHANGE_TYPE_CHANGE
                );
            }
        }

        // Server Deletions
        foreach ($changes['delete'] as $deleted) {
            $results[] = array(
                'id' => $deleted,
                'type' => Horde_ActiveSync::CHANGE_TYPE_DELETE);
        }

        return $results;
    }

    /**
     * Returns array of items which contain contact information
     *
     * @param string $type   The search type; ['gal'|'mailbox']
     * @param array $query   The search query. An array containing:
     *  - query:          (array) The search query. Contains at least:
     *                    'query' and 'range'. The rest depends on the type of
     *                    search being performed.
     *                    DEFAULT: none, REQUIRED
     *  - range:          (string)   A range limiter.
     *                     DEFAULT: none (No range used).
     *  - rebuildresults: (boolean)  If true, invalidate any cached search.
     *                    DEFAULT: Use cached search results if available.
     *  - deeptraversal:  (boolean) If true, traverse sub folders.
     *                    @todo NOT IMPLEMENTED YET.
     *
     * @return array  An array containing:
     *  - rows:   An array of search results, limited by $query['range'].
     *  - status: The search store status code.
     *  - total:  The total number of matches (not limited by $query['range']
     */
    public function getSearchResults($type, array $query)
    {
        switch (Horde_String::lower($type)) {
        case 'gal':
            return $this->_searchGal($query);
        case 'mailbox':
            if (!empty($this->_cache)) {
                $clear_cache = !empty($query['rebuildresults']);
                unset($query['rebuildresults']);
                $cache_key = $GLOBALS['registry']->getAuth() . ':HCASD:' . hash('md5', serialize($query));
                if ($clear_cache) {
                    $this->_cache->expire($cache_key);
                }
            }
            if (!empty($this->_cache) && $this->_cache->exists($cache_key, 0)) {
                $results = json_decode($this->_cache->get($cache_key, 0), true);
            } else {
                try {
                    $results = $this->_searchMailbox($query);
                    if (!empty($this->_cache)) {
                        $this->_cache->set($cache_key, json_encode($results));
                    }
                } catch (Horde_ActiveSync_Exception $e) {
                    $this->_logger->err($e->getMessage());
                    $results = array();
                }
            }
            $count = count($results);
            if (!empty($query['range'])) {
                $range = explode('-', $query['range']);
                $results = array_slice($results, $range[0], $range[1] - $range[0] + 1);
            }
            return array(
                'rows' => $results,
                'total' => $count,
                'status' => Horde_ActiveSync_Request_Search::STORE_STATUS_SUCCESS);
        case 'documentlibrary':
            foreach ($query['query'][0] as $q) {
                if (!empty($q['DocumentLibrary:LinkId'])) {
                    $results = $this->_connector->files_browse($q['DocumentLibrary:LinkId']);
                }
            }
            return array(
                'rows' => $results,
                'total' => count($results),
                'status' => Horde_ActiveSync_Request_Search::STORE_STATUS_SUCCESS
            );
        }
    }

    /**
     * Perform a search of the Global Address Book.
     *
     * @param array $query  A query array. @see self::getSearchResults()
     *
     * @return array  The results array. @see self::getSearchResults()
     */
    protected function _searchGal(array $query)
    {
        ob_start();
        $return = array(
            'rows' => array(),
            'status' => Horde_ActiveSync_Request_Search::STORE_STATUS_SUCCESS,
            'total' => 0
        );
        // If no perms to the GAL, return zero results.
        $perms = $GLOBALS['injector']->getInstance('Horde_Perms');
        if ($perms->exists('horde:activesync:no_gal') &&
            $perms->getPermissions('horde:activesync:no_gal', $this->_user)) {
                return $return;
        }
        try {
            $results = $this->_connector->contacts_search(
                $query['query'],
                array('pictures' => !empty($query[Horde_ActiveSync_Request_Search::SEARCH_PICTURE])));
        } catch (Horde_ActiveSync_Exception $e) {
            $this->_logger->err($e);
            $this->_endBuffer();
            return $return;
        }
        // Honor range, and don't bother if no results
        $results = array_pop($results);
        $count = count($results);
        if (!$count) {
            $this->_endBuffer();
            return $return;
        }
        $return['total'] = $count;
        $this->_logger->meta(sprintf(
            'Horde_Core_ActiveSync_Driver::_searchGal() found %d matches.', $count)
        );
        if (!empty($query['range'])) {
            preg_match('/(.*)\-(.*)/', $query['range'], $matches);
            $return_count = $matches[2] - $matches[1];
            $rows = array_slice($results, $matches[1], $return_count + 1, true);
        }
        $picture_count = 0;
        foreach ($rows as $row) {
            // Explicitly disallow returning contact groups since EAS clients
            // only expect a SINGLE email address to be returned. Returning
            // multiple email addresses, or the group syntax will cause most
            // clients to silently throw out all but the first email address in
            // the list, or will completely fail to send the message altogether.
            if (!empty($row['__type']) && ($row['__type'] != 'Object')) {
                continue;
            }
            $entry = array(
                Horde_ActiveSync::GAL_ALIAS => !empty($row['alias']) ? $row['alias'] : '',
                Horde_ActiveSync::GAL_DISPLAYNAME => $row['name'],
                Horde_ActiveSync::GAL_EMAILADDRESS => !empty($row['email']) ? $row['email'] : '',
                Horde_ActiveSync::GAL_FIRSTNAME => $row['firstname'],
                Horde_ActiveSync::GAL_LASTNAME => $row['lastname'],
                Horde_ActiveSync::GAL_COMPANY => !empty($row['company']) ? $row['company'] : '',
                Horde_ActiveSync::GAL_HOMEPHONE => !empty($row['homePhone']) ? $row['homePhone'] : '',
                Horde_ActiveSync::GAL_PHONE => !empty($row['workPhone']) ? $row['workPhone'] : '',
                Horde_ActiveSync::GAL_MOBILEPHONE => !empty($row['cellPhone']) ? $row['cellPhone'] : '',
                Horde_ActiveSync::GAL_TITLE => !empty($row['title']) ? $row['title'] : '',
                Horde_ActiveSync::GAL_OFFICE => !empty($row['office']) ? $row['office'] : '',
            );
            if (!empty($query[Horde_ActiveSync_Request_Search::SEARCH_PICTURE])) {
                $picture = Horde_ActiveSync::messageFactory('GalPicture');
                if (empty($row['photo'])) {
                    $picture->status = Horde_ActiveSync_Status::NO_PICTURE;
                } elseif (!empty($query[Horde_ActiveSync_Request_Search::SEARCH_MAXPICTURES]) &&
                          $picture_count > $query[Horde_ActiveSync_Request_Search::SEARCH_MAXPICTURES]) {
                    $picture->status = Horde_ActiveSync_Status::PICTURE_LIMIT_REACHED;
                } elseif (!empty($query[Horde_ActiveSync_Request_Search::SEARCH_MAXSIZE]) &&
                          strlen($row['photo']) > $query[Horde_ActiveSync_Request_Search::SEARCH_MAXSIZE]) {
                    $picture->status = Horde_ActiveSync_Status::PICTURE_TOO_LARGE;
                } else {
                    $picture->data = base64_encode($row['photo']['load']['data']);
                    $picture->status = Horde_ActiveSync_Status::PICTURE_SUCCESS;
                    ++$picture_count;
                }
                $entry[Horde_ActiveSync::GAL_PICTURE] = $picture;
            }
            $return['rows'][] = $entry;
        }
        $this->_endBuffer();
        return $return;
    }

    /**
     * Perform a search of the email store.
     *
     * @param array $query  A query array. @see self::getSearchResults()
     *
     * @return array  The results array. @see self::getSearchResults()
     */
    protected function _searchMailbox(array $query)
    {
        return $this->queryMailbox($query);
    }

    /**
     * Perform a search from a search mailbox request.
     *
     * @param array $query  The query array.
     *
     * @return array  An array of 'uniqueid', 'searchfolderid' hashes.
     */
    public function queryMailbox($query)
    {
        return $this->_doQuery($query['query']);
    }

    /**
     * Perform an IMAP search based on a SEARCH request.
     *
     * @param array $query  The search query.
     *
     * @return array  The results array containing an array of hashes:
     *   'uniqueid' => [The unique identifier of the result]
     *   'searchfolderid' => [The mailbox name that this result comes from]
     *
     * @throws Horde_ActiveSync_Exception
     */
    protected function _doQuery(array $query)
    {
        $serverid = NULL;
        $results = array();
        foreach ($query as $q) {
            switch ($q['op']) {
            case Horde_ActiveSync_Request_Search::SEARCH_AND:
                return $this->_doQuery(array($q['value']), $range);
            default:
                foreach ($q as $key => $value) {
                    switch ($key) {
                    case 'FolderType':
                        if ($value != Horde_ActiveSync::CLASS_EMAIL) {
                            throw new Horde_ActiveSync_Exception('Only Email folders are supported.');
                        }
                        break;
                    case 'serverid':
                        $serverid = $value
                        break;
                    case Horde_ActiveSync_Message_Mail::POOMMAIL_DATERECEIVED:
                        if ($q['op'] == Horde_ActiveSync_Request_Search::SEARCH_GREATERTHAN) {
                            $query_range = Horde_Imap_Client_Search_Query::DATE_SINCE;
                        } elseif ($q['op'] == Horde_ActiveSync_Request_Search::SEARCH_LESSTHAN) {
                            $query_range = Horde_Imap_Client_Search_Query::DATE_BEFORE;
                        } else {
                            $query_range = Horde_Imap_Client_Search_Query::DATE_ON;
                        }
                        $imap_query->dateSearch($value, $query_range);
                        break;
                    case Horde_ActiveSync_Request_Search::SEARCH_FREETEXT:
                        $imap_query->text($value, false);
                        break;
                    case 'subquery':
                        $imap_query->andSearch(array($this->_buildSubQuery($value)));
                    }
                }
            }
        }
        if (empty($mboxes)) {
            foreach ($this->getMailboxes() as $mailbox) {
                $mboxes[] = $mailbox['ob'];
            }
        }
        foreach ($mboxes as $mbox) {
            try {
                $search_res = $this->_getImapOb()->search(
                    $mbox,
                    $imap_query,
                    array(
                        'results' => array(Horde_Imap_Client::SEARCH_RESULTS_MATCH, Horde_Imap_Client::SEARCH_RESULTS_SAVE, Horde_Imap_Client::SEARCH_RESULTS_COUNT),
                        'sort' => array(Horde_Imap_Client::SORT_REVERSE, Horde_Imap_Client::SORT_ARRIVAL))
                );
            } catch (Horde_Imap_Client_Exception $e) {
                throw new Horde_ActiveSync_Exception($e);
            }
            if ($search_res['count'] == 0) {
                continue;
            }
            $ids = $search_res['match']->ids;
            foreach ($ids as $id) {
                $results[] = array('uniqueid' => $mbox->utf8 . ':' . $id, 'searchfolderid' => $mbox->utf8);
            }
            if (!empty($range)) {
                preg_match('/(.*)\-(.*)/', $range, $matches);
                $return_count = $matches[2] - $matches[1];
                $results = array_slice($results, $matches[1], $return_count + 1, true);
            }
        }
        return $results;
    }

    /**
     * Get a message stat.
     *
     * @param string $folderId  The folder id
     * @param string $id        The message id (??)
     *
     * @return hash with 'id', 'mod', and 'flags' members
     */
    public function statMessage($folderId, $id)
    {
        $mod = $this->_connector->getActionTimestamp($id, 'modify');
        $message = array();
        $message['id'] = $id;
        $message['mod'] = $mod;
        $message['flags'] = 1;

        return $message;
    }

    /**
     * Obtain an ActiveSync message from the backend.
     *
     * @param string $folderid    The server's folder id this message is from
     * @param string $id          The server's message id
     * @param array  $collection  The colletion data. May contain things like:
     *   - mimesupport: (boolean) Indicates if the device has MIME support.
     *                  DEFAULT: false (No MIME support)
     *   - truncation: (integer)  The truncation constant, if sent by the device.
     *                 DEFAULT: 0 (No truncation)
     *   - bodyprefs: (array)  The bodypref array from the device.
     *
     * @return Horde_ActiveSync_Message_Base The message data
     * @throws Horde_ActiveSync_Exception
     */
    public function getMessage($folderid, $id, array $collection)
    {
        //Implemented OXDriver Version - Done!
        return $this->_connector->export($id, array());
    }

    /**
     * Delete a message
     *
     * @param string $folderid  The folder id containing the messages.
     * @param array $ids        An array of message ids to delete.
     */
    public function deleteMessage($folderid, array $ids)
    {
        return $ids;
    }

    /**
     * Get the wastebasket folder.
     *
     * @param string $class  The collection class.
     *
     * @return string|boolean  Returns name of the trash folder, or false
     *                         if not using a trash folder.
     */
    public function getWasteBasket($class)
    {
        return false;
    }

    /**
     * Add/Edit a message
     *
     * @param string $folderid  The server id for the folder the message belongs
     *                          to.
     * @param string $id        The server's uid for the message if this is a
     *                          change to an existing message, null if new.
     * @param Horde_ActiveSync_Message_Base $message
     *                          The activesync message
     * @param Horde_ActiveSync_Device $device  The device information
     *
     * @return array|boolean    A stat array if successful, otherwise false.
     */
    public function changeMessage($folderid, $id, Horde_ActiveSync_Message_Base $message, $device) {  }

    /**
     * Set the read (\seen) flag on the specified message.
     *
     * @param string $folderid  The folder id containing the message.
     * @param integer $uid      The message IMAP UID.
     * @param integer $flag     The value to set the flag to.
     * @deprecated Will be removed in 3.0, use changeMessage() instead.
     */
    public function setReadFlag($folderid, $uid, $flag) {  }

    /**
     * Sends the email represented by the rfc822 string received by the client.
     *
     * @param mixed $rfc822     The rfc822 mime message, a string or stream
     *                          resource.
     * @param integer $forward  The UID of the message, if forwarding.
     * @param integer $reply    The UID of the message if replying.
     * @param string $parent    The collection id of parent message if
     *                          forwarding/replying.
     * @param boolean $save     Save in sent messages.
     *
     * @return boolean
     */
    public function sendMail(
        $rfc822, $forward = null, $reply = null, $parent = null, $save = true)
    {
        return true;
    }

    /**
     * Return the specified attachment.
     *
     * @param string $name  The attachment identifier. For this driver, this
     *                      consists of 'mailbox:uid:mimepart'
     *
     * @param array $options  Any options requested. Currently supported:
     *  - stream: (boolean) Return a stream resource for the mime contents.
     *
     * @return array  The attachment in the form of an array with the following
     *                structure:
     * array('content-type' => {the content-type of the attachement},
     *       'data'         => {the raw attachment data})
     */
    public function getAttachment($name, array $options = array()) {  }

    /**
     * Return the specified attachement data for an ITEMOPERATIONS request.
     *
     * @param string $filereference  The attachment identifier.
     *
     * @return
     */
    public function itemOperationsGetAttachmentData($filereference) {  }

    /**
     * Returnmail object represented by the specified longid. Used to fetch
     * email objects from a search result, which only returns a 'longid'.
     *
     * @param string $longid        The unique search result identifier.
     * @param array $bodyprefs      The bodypreference array.
     * @param boolean $mimesupport  Mimesupport flag.
     *
     * @return Horde_ActiveSync_Message_Base  The message requested.
     */
    public function itemOperationsFetchMailbox($longid, array $bodyprefs, $mimesupport) {  }

    /**
     * Return a documentlibrary item.
     *
     * @param string $linkid  The linkid
     * @param array $cred     A credential array:
     *   - username: A hash with 'username' and 'domain' key/values.
     *   - password: User password
     *
     * @return array An array containing the data and metadata:
     */
    public function itemOperationsGetDocumentLibraryLink($linkid, $cred) {  }

    /**
     * Build a stat structure for an email message.
     *
     * @param string $folderid   The mailbox name.
     * @param integer|array $id  The message(s) to stat (IMAP UIDs).
     *
     * @return array
     */
    public function statMailMessage($folderid, $id)
    {
        return array(
            'id' => $id,
            'mod' => 0,
            'flags' => false);
    }

    /**
     * Return the server id of the specified special folder type.
     *
     * @param string $type  The self::SPECIAL_* constant.
     *
     * @return string  The folder's server id.
     */
    public function getSpecialFolderNameByType($type)
    {
        $folders = $this->_imap->getSpecialMailboxes();
        $folder = $folders[$type];
        if (!is_null($folder)) {
            return $folder->value;
        }
    }

    /**
     * Return the security policies.
     *
     * @param boolean|array $device  The device information sent by EAS 14.1
     *                               set to false otherwise. @since 3.0
     * @return array  An array of provisionable properties and values.
     */
    public function getCurrentPolicy() {  }

    /**
     * Return settings from the backend for a SETTINGS request.
     *
     * @param array $settings   An array of settings to return.
     * @param Horde_ActiveSync_Device $device  The device to obtain settings for.
     *
     * @return array  The requested settings.
     */
    public function getSettings(array $settings, $device) {  }

    /**
     * Set backend settings from a SETTINGS request.
     *
     * @param array $settings   The settings to store.
     * @param Horde_ActiveSync_Device $device  The device to store settings for.
     *
     * @return array  An array of status responses for each set request. e.g.,:
     *   array('oof' => Horde_ActiveSync_Request_Settings::STATUS_SUCCESS,
     *         'deviceinformation' => Horde_ActiveSync_Request_Settings::STATUS_SUCCESS) {  }
     */
    public function setSettings(array $settings, $device) {  }

    /**
     * Return properties for an AUTODISCOVER request.
     *
     * @return array  An array of properties.
     */
    public function autoDiscover() {  }

    /**
     * Attempt to guess a username based on the email address passed from
     * EAS Autodiscover requests.
     *
     * @param string $email  The email address
     *
     * @return string  The username to use to authenticate to OX with.
     */
    public function getUsernameFromEmail($email) { return $email; }

    /**
     * Handle ResolveRecipient requests
     *
     * @param string $type    The type of recipient request. e.g., 'certificate'
     * @param string $search  The email to resolve.
     * @param array $opts     Any options required to perform the resolution.
     *  - maxcerts: (integer)     The maximum number of certificates to return
     *                             as provided by the client.
     *  - maxambiguous: (integer) The maximum number of ambiguous results. If
     *                            set to zero, we MUST have an exact match.
     *  - starttime: (Horde_Date) The start time for the availability window if
     *                            requesting AVAILABILITY.
     *  - endtime: (Horde_Date)   The end of the availability window if
     *                            requesting AVAILABILITY.
     *  - maxsize: (integer)      The maximum size of any pictures.
     *                            DEFAULT: 0 (No limit).
     *  - maxpictures: (integer)  The maximum count of images to return.
     *                            DEFAULT: - (No limit).
     *  - pictures: (boolean)     Return pictures.
     *
     * @return array  An array of results containing any of the following:
     *   - type: (string)  The type of result a GAL entry or personal
     *                     address book entry. A
     *                     Horde_ActiveSync::RESOLVE_RESULT constant.
     *   - displayname: (string)   The display name of the contact.
     *   - emailaddress: (string)  The emailaddress.
     *   - entries: (array)        An array of certificates.
     *   - availability: (string)  A EAS style FB string.
     *   - picture: (Horde_ActiveSync_Message_ResolveRecipientsPicture)
     */
    public function resolveRecipient($type, $search, array $options = array()) {  }

    /**
     * Returns the provisioning support for the current request.
     *
     * @return mixed  The value of the provisiong support flag.
     */
    public function getProvisioning() {  }

    /**
     * Hanlde meeting responses.
     *
     * @param array $response  The response data. Contains:
     *   - requestid: The identifier of the meeting request. Used by the server
     *                to fetch the original meeting request details.
     *   - response:  The user's response to the request. One of the response
     *                code constants.
     *   - folderid:  The collection id that contains the meeting request.
     *
     *
     * @return string  The UID of any created calendar entries, otherwise false.
     * @throws Horde_ActiveSync_Exception, Horde_Exception_NotFound
     */
    public function meetingResponse(array $response) {  }

    /**
     * Request freebusy information from the server
     *
     * @param string $user    The user to request FB information for.
     * @param array $options  Options.
     *
     * @return mixed boolean|array  The FB information, if available. Otherwise
     *                              false.
     */
    public function getFreebusy($user, array $options = array()) { }

    public function getHeartbeatConfig()
    {
        return array(
            'heartbeatmin' => 60,
            'heartbeatmax' => 2700,
            'heartbeatdefault' => 480,
            'deviceping' => true,
            'waitinterval' => 10);
    }

}
