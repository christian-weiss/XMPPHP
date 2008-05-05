<?php
/*
XMPPHP: The PHP XMPP Library
Copyright (C) 2008  Nathanael C. Fritz
This file is part of SleekXMPP.

XMPPHP is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

XMPPHP is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with XMPPHP; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

require_once("XMLStream.php");

class XMPPHP_XMPP extends XMPPHP_XMLStream {
	protected $server;
	protected $user;
	protected $password;
	protected $resource;
	protected $fulljid;
	protected $basejid;
	protected $authed;
	public $auto_subscribe = False;

	/**
	 * Constructor
	 *
	 * @param string  $host
	 * @param integer $port
	 * @param string  $user
	 * @param string  $password
	 * @param string  $resource
	 * @param string  $server
	 * @param boolean $printlog
	 * @param string  $loglevel
	 */
	public function __construct($host, $port, $user, $password, $resource, $server = null, $printlog = false, $loglevel = null) {
		parent::__construct($host, $port, $printlog, $loglevel);
		
		$this->user     = $user;
		$this->password = $password;
		$this->resource = $resource;
		if(!$server) $server = $host;
		$this->basejid = $this->user . '@' . $this->host;

		$this->stream_start = '<stream:stream to="' . $server . '" xmlns:stream="http://etherx.jabber.org/streams" xmlns="jabber:client" version="1.0">';
		$this->stream_end = '</stream:stream>';
		$this->addHandler('features', 'http://etherx.jabber.org/streams', 'features_handler');
		$this->addHandler('success', 'urn:ietf:params:xml:ns:xmpp-sasl', 'sasl_success_handler');
		$this->addHandler('failure', 'urn:ietf:params:xml:ns:xmpp-sasl', 'sasl_failure_handler');
		$this->addHandler('proceed', 'urn:ietf:params:xml:ns:xmpp-tls', 'tls_proceed_handler');
		$this->addHandler('message', 'jabber:client', 'message_handler');
		$this->addHandler('presence', 'jabber:client', 'presence_handler');
        
		$this->default_ns     = 'jabber:client';
		$this->authed         = false;
		$this->use_encryption = true;
	}

	/**
	 * Turn on auto-authorization of subscription requests.
	 */
	public function autoSubscribe() {
		$this->auto_subscribe = true;
	}

    /**
     * Send XMPP Message
     *
     * @param string $to
     * @param string $body
     * @param string $type
     * @param string $subject
     */
	public function message($to, $body, $type = 'chat', $subject = null) {
        $to      = htmlspecialchars($to);
        $body    = htmlspecialchars($body);
        $subject = htmlspecialchars($subject);
        
        $out = "<message from='{$this->fulljid}' to='$to' type='$type'>";
        if($subject) $out .= "<subject>$subject</subject>";
        $out .= "<body>$body</body></message>";
        
        $this->send($out);
    }

    /**
     * Set Presence
     *
     * @param string $status
     * @param string $show
     * @param string $to
     */
    public function presence($status = null, $show = 'available', $to = null) {
        $type   = '';
        $to     = htmlspecialchars($to);
        $status = htmlspecialchars($status);
        if($show == 'unavailable') $type = 'unavailable';
        
        $out = "<presence";
        if($to) $out .= " to='$to'";
        if($type) $out .= " type='$type'";
        if($show == 'available' and !$status) {
            $out .= "/>";
        } else {
            $out .= ">";
            if($show != 'available') $out .= "<show>$show</show>";
            if($status) $out .= "<status>$status</status>";
            $out .= "</presence>";
        }
        
        $this->send($out);
    }

	/**
	 * Message handler
	 *
	 * @param string $xml
	 */
    public function message_handler($xml) {
	    if(isset($xml->attrs['type'])) {
		    $payload['type'] = $xml->attrs['type'];
	    } else {
		    $payload['type'] = 'chat';
	    }
		$payload['from'] = $xml->attrs['from'];
		$payload['body'] = $xml->sub('body')->data;
		$this->log->log("Message: {$xml->sub('body')->data}", XMPPHP_Log::LEVEL_DEBUG);
		$this->event('message', $payload);
	}

    /**
     * Presence handler
     *
     * @param string $xml
     */
	public function presence_handler($xml) {
		$payload['type'] = (isset($xml->attrs['type'])) ? $xml->attrs['type'] : 'available';
		$payload['show'] = (isset($xml->sub('show')->data)) ? $xml->sub('show')->data : $payload['type'];
		$payload['from'] = $xml->attrs['from'];
		$payload['status'] = (isset($xml->sub('status')->data)) ? $xml->sub('status')->data : '';
		$this->log->log("Presence: {$payload['from']} [{$payload['show']}] {$payload['status']}",  XMPPHP_Log::LEVEL_DEBUG);
		if($xml->attrs['type'] == 'subscribe') {
			if($this->auto_subscribe) $this->send("<presence type='subscribed' to='{$xml->attrs['from']}' from='{$this->basejid}' /><presence type='subscribe' to='{$xml->attrs['from']}' from='{$this->basejid}' />");
			$this->event('subscription_requested', $payload);
		} elseif($xml->attrs['type'] == 'subscribed') {
			$this->event('subscription_accepted', $payload);
		} else {
			$this->event('presence', $payload);
		}
	}

    /**
     * Features handler
     *
     * @param string $xml
     */
	public function features_handler($xml) {
		if($xml->hasSub('starttls') and $this->use_encryption) {
			$this->send("<starttls xmlns='urn:ietf:params:xml:ns:xmpp-tls'><required /></starttls>");
		} elseif($xml->hasSub('bind')) {
			$id = $this->getId();
			$this->addIdHandler($id, 'resource_bind_handler');
			$this->send("<iq xmlns=\"jabber:client\" type=\"set\" id=\"$id\"><bind xmlns=\"urn:ietf:params:xml:ns:xmpp-bind\"><resource>{$this->resource}</resource></bind></iq>");
		} else {
			$this->log->log("Attempting Auth...");
			$this->send("<auth xmlns='urn:ietf:params:xml:ns:xmpp-sasl' mechanism='PLAIN'>" . base64_encode("\x00" . $this->user . "\x00" . $this->password) . "</auth>");
		}
	}

    /**
     * SASL success handler
     *
     * @param string $xml
     */
	public function sasl_success_handler($xml) {
		$this->log->log("Auth success!");
		$this->authed = true;
		$this->reset();
	}
	
    /**
     * SASL feature handler
     *
     * @param string $xml
     */
	public function sasl_failure_handler($xml) {
		$this->log->log("Auth failed!",  XMPPHP_Log::LEVEL_ERROR);
		$this->disconnect();
	}

    /**
     * Resource bind handler
     *
     * @param string $xml
     */
	public function resource_bind_handler($xml) {
		if($xml->attrs['type'] == 'result') {
			$this->log->log("Bound to " . $xml->sub('bind')->sub('jid')->data);
			$this->fulljid = $xml->sub('bind')->sub('jid')->data;
		}
		$id = $this->getId();
		$this->addIdHandler($id, 'session_start_handler');
		$this->send("<iq xmlns='jabber:client' type='set' id='$id'><session xmlns='urn:ietf:params:xml:ns:xmpp-session' /></iq>");
	}

    /**
     * Session start handler
     *
     * @param string $xml
     */
	public function session_start_handler($xml) {
		$this->log->log("Session started");
		$this->event('session_start');
	}

    /**
     * TLS proceed handler
     *
     * @param string $xml
     */
	public function tls_proceed_handler($xml) {
		$this->log->log("Starting TLS encryption");
		stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_SSLv23_CLIENT);
		$this->reset();
	}
}