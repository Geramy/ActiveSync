<?php
/**
 *
 * @license   http://www.horde.org/licenses/gpl GPLv2
 *            NOTE: According to sec. 8 of the GENERAL PUBLIC LICENSE (GPL),
 *            Version 2, the distribution of the Horde_ActiveSync module in or
 *            to the United States of America is excluded from the scope of this
 *            license.
 * @copyright 2009-2016 Horde LLC (http://www.horde.org)
 * @author    Michael J Rubinsky <mrubinsk@horde.org>
 * @package   ActiveSync
 */
/**
 * Horde_ActiveSync_Request_MeetingResponse::
 *
 * Portions of this class were ported from the Z-Push project:
 *   File      :   wbxml.php
 *   Project   :   Z-Push
 *   Descr     :   WBXML mapping file
 *
 *   Created   :   01.10.2007
 *
 *   © Zarafa Deutschland GmbH, www.zarafaserver.de
 *   This file is distributed under GPL-2.0.
 *   Consult COPYING file for details
 *
 * @license   http://www.horde.org/licenses/gpl GPLv2
 *            NOTE: According to sec. 8 of the GENERAL PUBLIC LICENSE (GPL),
 *            Version 2, the distribution of the Horde_ActiveSync module in or
 *            to the United States of America is excluded from the scope of this
 *            license.
 * @copyright 2009-2016 Horde LLC (http://www.horde.org)
 * @author    Michael J Rubinsky <mrubinsk@horde.org>
 * @package   ActiveSync
 * @internal
 */
class Horde_ActiveSync_Request_MeetingResponse extends Horde_ActiveSync_Request_Base
{
    const MEETINGRESPONSE_CALENDARID      = 'MeetingResponse:CalendarId';
    const MEETINGRESPONSE_FOLDERID        = 'MeetingResponse:FolderId';
    const MEETINGRESPONSE_MEETINGRESPONSE = 'MeetingResponse:MeetingResponse';
    const MEETINGRESPONSE_REQUESTID       = 'MeetingResponse:RequestId';
    const MEETINGRESPONSE_REQUEST         = 'MeetingResponse:Request';
    const MEETINGRESPONSE_RESULT          = 'MeetingResponse:Result';
    const MEETINGRESPONSE_STATUS          = 'MeetingResponse:Status';
    const MEETINGRESPONSE_USERRESPONSE    = 'MeetingResponse:UserResponse';
    const MEETINGRESPONSE_VERSION         = 'MeetingResponse:Version';

    // 14.1
    const MEETINGRESPONSE_INSTANCEID      = 'MeetingResponse:InstanceId';

    // 16.0 @todo
    const MEETINGRESPONSE_SENDRESPONSE    = 'MeetingResponse:SendResposne';

    // Response constants
    const RESPONSE_ACCEPTED               = 1;
    const RESPONSE_TENTATIVE              = 2;
    const RESPONSE_DECLINED               = 3;

    // Status constants
    const STATUS_SUCCESS                  = 1;
    const STATUS_INVALID_REQUEST          = 2;
    const STATUS_STATE_ERROR              = 3;
    const STATUS_SERVER_ERROR             = 4;

    /**
     * Handle request
     *
     * @return boolean
     */
    protected function _handle()
    {
        $requests = array();
        if (!$this->_decoder->getElementStartTag(self::MEETINGRESPONSE_MEETINGRESPONSE)) {
            throw new Horde_ActiveSync_Exception('Protocol Error');
        }
        while ($this->_decoder->getElementStartTag(self::MEETINGRESPONSE_REQUEST)) {
            $req = array();
            while (($tag = ($this->_decoder->getElementStartTag(self::MEETINGRESPONSE_USERRESPONSE) ? self::MEETINGRESPONSE_USERRESPONSE :
                   ($this->_decoder->getElementStartTag(self::MEETINGRESPONSE_FOLDERID) ? self::MEETINGRESPONSE_FOLDERID :
                   ($this->_decoder->getElementStartTag(self::MEETINGRESPONSE_REQUESTID) ? self::MEETINGRESPONSE_REQUESTID : -1)))) != -1) {

                switch ($tag) {
                case self::MEETINGRESPONSE_USERRESPONSE:
                    $req['response'] = $this->_decoder->getElementContent();
                    if (!$this->_decoder->getElementEndTag()) {
                        throw new Horde_ActiveSync_Exception('Protocol Error');
                    }
                    break;
                case self::MEETINGRESPONSE_FOLDERID:
                    $req['folderid'] = $this->_activeSync->getCollectionsObject()
                        ->getBackendIdForFolderUid($this->_decoder->getElementContent());
                    if (!$this->_decoder->getElementEndTag()) {
                        throw new Horde_ActiveSync_Exception('Protocol Error');
                    }
                    break;
                case self::MEETINGRESPONSE_REQUESTID:
                    $req['requestid'] = $this->_decoder->getElementContent();
                    if (!$this->_decoder->getElementEndTag()) {
                        throw new Horde_ActiveSync_Exception('Protocol Error');
                    }
                    break;
                }
            }
            $requests[] = $req;
            // </self::MEETINGRESPONSE_REQUEST>
            if (!$this->_decoder->getElementEndTag()) {
                throw new Horde_ActiveSync_Exception('Protocol Error');
            }
        }

        // </self::MEETINGRESPONSE>
        if (!$this->_decoder->getElementEndTag()) {
            throw new Horde_ActiveSync_Exception('Protocol Error');
        }

        // Start output, simply the error code, plus the ID of the calendar item
        // that was generated by the accept of the meeting response
        $this->_encoder->StartWBXML();
        $this->_encoder->startTag(self::MEETINGRESPONSE_MEETINGRESPONSE);

        foreach ($requests as $req) {
            try {
                $uid = $this->_driver->meetingResponse($req);
                $status = self::STATUS_SUCCESS;
            } catch (Horde_Exception_NotFound $e) {
                $status = self::STATUS_SERVER_ERROR;
            } catch (Horde_ActiveSync_Exception $e) {
                // Outlook seems to sometimes send the response from the
                // calendar folder instead of the mailbox regardless of where
                // the message is replied to from this will obviously fail,
                // so we should try one last time to get the message from the
                // INBOX. If it was moved to some other mail folder, we have to
                // just give up.
                $this->_logger->info(sprintf('[%s] Trying to find meeting request in INBOX.', $this->_procid));
                $req['folderid'] = 'INBOX';
                try {
                    $uid = $this->_driver->meetingResponse($req);
                    $status = self::STATUS_SUCCESS;
                    $this->_logger->info(sprintf('[%s] Successfully found meeting response in INBOX.', $this->_procid));
                } catch (Horde_ActiveSync_Exception $e) {
                    $this->_logger->err(sprintf('[%s] Meeting request unable to be located.', $this->_procid));
                    $status = self::STATUS_INVALID_REQUEST;
                }
            }

            $this->_encoder->startTag(self::MEETINGRESPONSE_RESULT);
            $this->_encoder->startTag(self::MEETINGRESPONSE_REQUESTID);
            $this->_encoder->content($req['requestid']);
            $this->_encoder->endTag();
            $this->_encoder->startTag(self::MEETINGRESPONSE_STATUS);
            $this->_encoder->content($status);
            $this->_encoder->endTag();
            if ($status == self::STATUS_SUCCESS) {
                $this->_encoder->startTag(self::MEETINGRESPONSE_CALENDARID);
                $this->_encoder->content($uid);
                $this->_encoder->endTag();
            }
            $this->_encoder->endTag();
        }

        $this->_encoder->endTag();

        return true;
    }

}