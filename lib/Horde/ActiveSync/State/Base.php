<?php
/**
 * Base class for managing everything related to state:
 *
 *     Persistence of state data
 *     Generating delta between server and PIM
 *     Caching PING related state (hearbeat interval, folder list etc...)
 *
 * Copyright 2010 The Horde Project (http://www.horde.org)
 *
 * @author Michael J. Rubinsky <mrubinsk@horde.org>
 * @package Horde_ActiveSync
 */
abstract class Horde_ActiveSync_State_Base
{

    /**
     * Filtertype constants
     */
    const FILTERTYPE_ALL = 0;
    const FILTERTYPE_1DAY = 1;
    const FILTERTYPE_3DAYS = 2;
    const FILTERTYPE_1WEEK = 3;
    const FILTERTYPE_2WEEKS = 4;
    const FILTERTYPE_1MONTH = 5;
    const FILTERTYPE_3MONTHS = 6;
    const FILTERTYPE_6MONTHS = 7;

    /**
     * Configuration parameters
     *
     * @var array
     */
    protected $_params;

    /**
     * Caches the current state(s) in memory
     *
     * @var array
     */
    protected $_stateCache;

    /**
     * The syncKey for the current request.
     *
     * @var string
     */
    protected $_syncKey;

    /**
     * The backend driver
     *
     * @param Horde_ActiveSync_Driver_Base
     */
    protected $_backend;

    /**
     * The collection array for the collection we are currently syncing.
     * Keys include:
     *   'class'      - The collection class Contacts, Calendar etc...
     *   'synckey'    - The current synckey
     *   'newsynckey' - The new synckey sent back to the PIM
     *   'id'         - Server folder id
     *   'filtertype' - Filter
     *   'conflict'   - Conflicts
     *   'truncation' - Truncation
     *
     *
     * @var array
     */
    protected $_collection;

    /**
     * Logger instance
     *
     * @var Horde_Log_Logger
     */
    protected $_logger;

    /**
     * The PIM device id. Needed for PING requests
     *
     * @var string
     */
    protected $_devId;

    /**
     * Const'r
     *
     * @param array $collection  A collection array
     * @param array $params  All configuration parameters, requirements.
     *
     * @return Horde_ActiveSync_State_Base
     */
    public function __construct($params = array())
    {
        $this->_params = $params;
    }

    /**
     * Update the $oldKey syncState to $newKey.
     *
     * @param string $newKey
     *
     * @return void
     */
    public function setNewSyncKey($newKey)
    {
        $this->_syncKey = $newKey;
    }

    /**
     * Get the current synckey
     *
     * @return string  The synkey we last retrieved state for
     */
    public function getCurrentSyncKey()
    {
        return $this->_syncKey;
    }

    /**
     * Generate a random 10 digit policy key
     *
     * @return unknown
     */
    public function generatePolicyKey()
    {
        return mt_rand(1000000000, 9999999999);
    }

    /**
     * Loads the initial state from storage for the specified syncKey and
     * intializes the stateMachine for use.
     *
     * @param string $syncKey  The key for the state to load.
     * @param string $type     Treat the loaded state data as this type of state.
     *
     * @return array The state array
     */
    abstract public function loadState($syncKey, $type = null, $id = '');

    /**
     * Load/initialize the ping state for the specified device.
     *
     * @param string $devId
     */
    abstract public function initPingState($devId);

    /**
     * Load the ping state for the given device id
     *
     * @param string $devid  The device id.
     */
    abstract public function loadPingCollectionState($devid);

    /**
     * Get the list of known folders for the specified syncState
     *
     * @param string $syncKey  The syncState key
     *
     * @return array  An array of server folder ids
     */
    abstract public function getKnownFolders();

    /**
     * Save the current syncstate to storage
     *
     * @param string $syncKey
     */
    abstract public function save();

    /**
     * Update the state for a specific syncKey
     *
     * @param <type> $type
     * @param <type> $change
     * @param <type> $key
     */
    abstract public function updateState($type, $change, $origin = Horde_ActiveSync::CHANGE_ORIGIN_NA);

    /**
     * Obtain the diff between PIM and server
     */
    abstract public function getChanges();

    /**
     * Determines if the server version of the message represented by $stat
     * conflicts with the PIM version of the message according to the current
     * state.
     *
     * @param array $stat   A message stat array
     * @param string $type  The type of change (change, delete, add)
     *
     * @return boolean
     */
    abstract public function isConflict($stat, $type);

    /**
     * Obtain the current policy key, if it exists.
     *
     * @param string $devId     The device id to obtain policy key for.
     *
     * @return integer  The current policy key for this device, or 0 if none
     *                  exists.
     */
    abstract public function getPolicyKey($devId);

    /**
     * Save a new device policy key to storage.
     *
     * @param string $devId  The device id
     * @param integer $key   The new policy key
     */
    abstract public function setPolicyKey($devId, $key);

    /**
     * Return a device remotewipe status
     *
     * @param string $devId  The device id
     *
     * @return int
     */
    abstract public function getDeviceRWStatus($devId);


    /**
     * Set a new remotewipe status for the device
     *
     * @param string $devid
     * @param string $status
     *
     * @return boolean
     */
    abstract public function setDeviceRWStatus($devid, $status);

    /**
     * Obtain the device object.
     *
     * @param string $devId
     *
     * @return StdClass
     */
    abstract public function getDeviceInfo($devId);

    /**
     * Check that a given device id is known to the server. This is regardless
     * of Provisioning status.
     *
     * @param string $devId
     *
     * @return boolean
     */
    abstract public function deviceExists($devId);

    /**
     * Set new device info
     *
     * @param string $devId   The device id.
     * @param StdClass $data  The device information
     *
     * @return boolean
     */
    abstract public function setDeviceInfo($devId, $data);

    /**
     * Explicitly remove a state from storage.
     *
     * @param string $synckey
     */
    abstract public function removeState($synckey);

    /**
     * Return the heartbeat interval, or zero if we have no existing state
     *
     * @return integer  The hearbeat interval, or zero if not found.
     * @throws Horde_ActiveSync_Exception
     */
    abstract public function getHeartbeatInterval();

    /**
     * Set the device's heartbeat interval
     *
     * @param integer $lifetime
     */
    abstract public function setHeartbeatInterval($lifetime);

    /**
     * Set the backend driver
     * (should really only be called by a backend object when passing this
     * object to client code)
     *
     * @param Horde_ActiveSync_Driver_Base $backend  The backend driver
     *
     * @return void
     */
    public function setBackend(Horde_ActiveSync_Driver_Base $backend)
    {
        $this->_backend = $backend;
    }

    /**
     * Initialize the state object
     *
     * @param array $collection  The collection array
     *
     * @return void
     */
    public function init($collection = array())
    {
        $this->_collection = $collection;
    }

    /**
     * Set the logger instance for this object.
     *
     * @param Horde_Log_Logger $logger
     */
    public function setLogger($logger)
    {
        $this->_logger = $logger;
    }

    /**
     * Reset the device's PING state.
     *
     * @return void
     */
    public function resetPingState()
    {
        $this->_pingState = array(
            'lifetime' => 0,
            'collections' => array());
    }

    /**
     * Get the number of server changes.
     *
     * @return integer
     */
    public function getChangeCount()
    {
        if (!isset($this->_changes)) {
            $this->getChanges();
        }

        return count($this->_changes);
    }

    /**
     * Gets the new sync key for a specified sync key. You must save the new
     * sync state under this sync key when done sync'ing by calling
     * setNewSyncKey(), then save().
     *
     * @param string $syncKey  The old syncKey
     *
     * @return string  The new synckey
     * @throws Horde_ActiveSync_Exception
     */
    static public function getNewSyncKey($syncKey)
    {
        if (empty($syncKey)) {
            return '{' . self::uuid() . '}' . '1';
        } else {
            if (preg_match('/^s{0,1}\{([a-fA-F0-9-]+)\}([0-9]+)$/', $syncKey, $matches)) {
                $n = $matches[2];
                $n++;

                return '{' . $matches[1] . '}' . $n;
            }
            throw new Horde_ActiveSync_Exception('Invalid SyncKey format passed to getNewSyncKey()');
        }
    }

    /**
     * Generate a uid for the sync key
     *
     * @return unknown_type
     */
    static public function uuid()
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                    mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
                    mt_rand( 0, 0x0fff ) | 0x4000,
                    mt_rand( 0, 0x3fff ) | 0x8000,
                    mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ));
    }

   /**
    * Returns the timestamp of the earliest modification time to consider
    *
    * @param integer $restrict  The time period to restrict to
    *
    * @return integer
    */
    static protected function _getCutOffDate($restrict)
    {
        switch($restrict) {
        case self::FILTERTYPE_1DAY:
            $back = 60 * 60 * 24;
            break;
        case self::FILTERTYPE_3DAYS:
            $back = 60 * 60 * 24 * 3;
            break;
        case self::FILTERTYPE_1WEEK:
            $back = 60 * 60 * 24 * 7;
            break;
        case self::FILTERTYPE_2WEEKS:
            $back = 60 * 60 * 24 * 14;
            break;
        case self::FILTERTYPE_1MONTH:
            $back = 60 * 60 * 24 * 31;
            break;
        case self::FILTERTYPE_3MONTHS:
            $back = 60 * 60 * 24 * 31 * 3;
            break;
        case self::FILTERTYPE_6MONTHS:
            $back = 60 * 60 * 24 * 31 * 6;
            break;
        default:
            break;
        }

        if (isset($back))
        {
            $date = time() - $back;
            return $date;
        } else {
            return 0; // unlimited
        }
    }
    /**
     * Helper function that performs the actual diff between PIM state and
     * server state arrays.
     *
     * @param array $old  The PIM state
     * @param array $new  The current server state
     *
     * @return unknown_type
     */
    protected function _getDiff($old, $new)
    {
        $changes = array();

        // Sort both arrays in the same way by ID
        usort($old, array(__CLASS__, 'RowCmp'));
        usort($new, array(__CLASS__, 'RowCmp'));

        $inew = 0;
        $iold = 0;

        // Get changes by comparing our list of messages with
        // our previous state
        while (1) {
            $change = array();

            if ($iold >= count($old) || $inew >= count($new)) {
                break;
            }

            if ($old[$iold]['id'] == $new[$inew]['id']) {
                // Both messages are still available, compare flags and mod
                if (isset($old[$iold]['flags']) && isset($new[$inew]['flags']) && $old[$iold]['flags'] != $new[$inew]['flags']) {
                    // Flags changed
                    $change['type'] = 'flags';
                    $change['id'] = $new[$inew]['id'];
                    $change['flags'] = $new[$inew]['flags'];
                    $changes[] = $change;
                }

                if ($old[$iold]['mod'] != $new[$inew]['mod']) {
                    $change['type'] = 'change';
                    $change['id'] = $new[$inew]['id'];
                    $changes[] = $change;
                }

                $inew++;
                $iold++;
            } else {
                if ($old[$iold]['id'] > $new[$inew]['id']) {
                    // Message in state seems to have disappeared (delete)
                    $change['type'] = 'delete';
                    $change['id'] = $old[$iold]['id'];
                    $changes[] = $change;
                    $iold++;
                } else {
                    // Message in new seems to be new (add)
                    $change['type'] = 'change';
                    $change['flags'] = Horde_ActiveSync::FLAG_NEWMESSAGE;
                    $change['id'] = $new[$inew]['id'];
                    $changes[] = $change;
                    $inew++;
                }
            }
        }

        while ($iold < count($old)) {
            // All data left in _syncstate have been deleted
            $change['type'] = 'delete';
            $change['id'] = $old[$iold]['id'];
            $changes[] = $change;
            $iold++;
        }

        while ($inew < count($new)) {
            // All data left in new have been added
            $change['type'] = 'change';
            $change['flags'] = Horde_ActiveSync::FLAG_NEWMESSAGE;
            $change['id'] = $new[$inew]['id'];
            $changes[] = $change;
            $inew++;
        }

        return $changes;
    }

    /**
     * Helper function for the _diff method
     *
     * @param $a
     * @param $b
     * @return unknown_type
     */
    static public function RowCmp($a, $b)
    {
        return $a['id'] < $b['id'] ? 1 : -1;
    }
}