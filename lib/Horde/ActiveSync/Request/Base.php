<?php
/**
 * Base class for handling ActiveSync requests
 *
 * Copyright 2009 - 2010 The Horde Project (http://www.horde.org)
 *
 * @author Michael J. Rubinsky <mrubinsk@horde.org>
 * @package Horde_ActiveSync
 */
/**
 * Zarafa Deutschland GmbH, www.zarafaserver.de
 * This file is distributed under GPL v2.
 * Consult LICENSE file for details
 */
abstract class Horde_ActiveSync_Request_Base
{
    /**
     * Driver for communicating with the backend datastore.
     *
     * @var Horde_ActiveSync_Driver_Base
     */
    protected $_driver;

    /**
     * Encoder
     *
     * @var Horde_ActiveSync_Wbxml_Encoder
     */
    protected $_encoder;

    /**
     * Decoder
     *
     * @var Horde_ActiveSync_Wbxml_Decoder
     */
    protected $_decoder;

    /**
     * Request object
     *
     * @var Horde_Controller_Request_Http
     */
    protected $_request;

    /**
     * Whether we require provisioned devices.
     * Valid values are true, false, or loose.
     * Loose allows devices that don't know about provisioning to continue to
     * function, but requires devices that are capable to be provisioned.
     *
     * @var mixed
     */
    protected $_provisioning;

    /**
     * The ActiveSync Version
     *
     * @var string
     */
    protected $_version;

    /**
     * The device Id
     *
     * @var string
     */
    protected $_devId;

    /**
     * Used to track what error code to send back to PIM on failure
     *
     * @var integer
     */
    protected $_statusCode = 0;

    protected $_logger;

    /**
     * Const'r
     *
     * @param Horde_ActiveSync_Driver $driver            The backend driver
     * @param Horde_ActiveSync_Wbxml_Decoder $decoder    The Wbxml decoder
     * @param Horde_ActiveSync_Wbxml_Endcodder $encdoer  The Wbxml encoder
     * @param Horde_Controller_Request_Http $request     The request object
     * @param string $version                            ActiveSync version
     * @param string $devId                              The PIM device id
     * @param string $provisioning                       Is provisioning required?
     *
     * @return Horde_ActiveSync
     */
    public function __construct(Horde_ActiveSync_Driver_Base $driver,
                                Horde_ActiveSync_Wbxml_Decoder $decoder,
                                Horde_ActiveSync_Wbxml_Encoder $encoder,
                                Horde_Controller_Request_Http $request,
                                $provisioning)
    {
        /* Backend driver */
        $this->_driver = $driver;

        /* Wbxml handlers */
        $this->_encoder = $encoder;
        $this->_decoder = $decoder;

        /* The http request */
        $this->_request = $request;

        /* Provisioning support */
        $this->_provisioning = $provisioning;
    }

    /**
     * Ensure the PIM's policy key is current.
     *
     * @param <type> $devId
     * @return <type>
     */
    public function checkPolicyKey($sentKey)
    {
        /* Don't attempt if we don't care */
        if ($this->_provisioning !== false) {
            $state = $this->_driver->getStateObject();
            $storedKey = $state->getPolicyKey($this->_devId);
            /* Loose provsioning should allow a blank key */
            if ($storedKey != $sentKey &&
               ($this->_provisioning !== 'loose' ||
               ($this->_provisioning === 'loose' && !empty($this->_policyKey)))) {

                    Horde_ActiveSync::provisioningRequired();
                    return false;
            }
        }

        return true;
    }

    public function setLogger(Horde_Log_Logger $logger) {
        $this->_logger = $logger;
    }
    /**
     *
     * @param string $version
     * @param string $devId
     */
    public function handle(Horde_ActiveSync $activeSync, $devId)
    {
        $this->_version = $activeSync->getProtocolVersion();
        $this->_devId = $devId;
    }

}