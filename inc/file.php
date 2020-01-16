<?php
    if ( ! defined( '__SHAFI_FOLDER' ) ) {
        exit; // Exit if accessed directly
    }
    
    require_once(__SHAFI_INC . 'dbobject.php');
    require_once(__SHAFI_INC . 'uuid.php');
    require_once(__SHAFI_INC . 'storage.php');

    define('__STATE', [
        'a' => __('active'), 'e' => __('expired'), 'c' => __('cancelled'), 'g' => __('grace period'), 'd' => __('deleted')
    ]);
        
    class SHAFile extends SCPM_DBObject {
        // TODO: 
        //  - store extra data? (e.g. IP address when it was created)
        protected static $db_tablename = "files";
        const FIELDS = [
            'owner',
            'time' => 'datetime',       // Then it was created
            'expired' => 'datetime',    // When it expired (NULL means that it has not expired)
            'name',                     // The name of the file
            'path',                     // The path of the file (according to the storage backend)
            'state',                    // The state of expiration (alive, expired, cancelled, etc.)
            'stid'                      // A unique ID for the file, provided by the storage backend (probably won't be used)
        ];

        protected $owner = null;
        protected $time = null;
        protected $expired = null;
        protected $name = null;
        protected $path = null;
        protected $state = 'a';

        public function __construct($id = null) {
            parent::__construct($id);
            $this->set_id_function("UUID::v4");
            $this->time = new Datetime();
        }

        public function set_basic_info($fileinfo, $owner) {
            $this->stid = $fileinfo->stid;
            $this->name = $fileinfo->name;
            $this->path = $fileinfo->path;
            $this->owner = $owner;
        }

        public function get_fileinfo() {
            return new FileInfo($this->stid, $this->path, $this->name, $this->owner);
        }

        public function is_deleted() {
            return $this->state == 'd';
        }

        public function is_active() {
            return in_array($this->state, ['a', 'g']);
        }

        public function create_token($exp_seconds, $exp_hits, $passwd) {
            $token = new SHAToken();
            $token->set_limits($exp_seconds, $exp_hits);
            $token->set_fileid($this->get_id());
            $token->set_password($passwd);
            return $token;
        }

        public function file_exists() {
            global $storage_backend;
            return $storage_backend->getfilesize($this->path) !== false;
        }

        public function cancel($autosave = false) {
            if ($this->is_active()) {
                $now = new Datetime();
                $this->expired = $now;
                $this->state = 'c';

                if ($autosave) {
                    if (! $this->save_i(['expired', 'state']))
                        return false;
                }
                return true;
            }
            return false;
        }

        /**
         * Function that sets a file as active, except from 'd'
         */
        public function reactivate($autosave = false) {
            if ($this->is_active()) return true;

            // 'd' things are kept just for historical purposes
            if ($this->is_deleted()) return false;

            $this->state = 'a';
            $this->expired = null;

            if (($autosave) && (! $this->save_i(['expired', 'state'])))
                return false;

            return $this->update_state($autosave);
        }

        public function force_state($state, $autosave = false) {
            $now = new Datetime();

            $this->state = $state;
            switch ($this->state) {
                case 'a':
                case 'g':
                    $this->expired = null;
                    break;

                case 'd':
                case 'e':
                case 'c':
                    $this->expired = $now;
                    default:
            }
            if (($autosave) && (! $this->save_i(['expired', 'state'])))
                return false;

            return true;
        }

        public function update_state($autosave = false) {
            // 'd' is a terminal state; there is no operation (except force_state) that may change the state
            if ($this->state == 'd')
                return false;

            $prev_state = $this->state;
            $now = new Datetime();

            if ($this->state == 'a') {
                $tokens = $this->get_active_tokens();
                if (sizeof($tokens) == 0) {
                    $this->state = 'g';
                    $this->expired = $now;
                }
            }

            // Checking this after a => g allows a grace period of zero
            if ($this->state == 'g') {
                $tokens = $this->get_active_tokens();
                
                if (sizeof($tokens) > 0) {
                    $this->state = 'a';
                    $this->expired = null;
                } else {
                    $seconds = $now->getTimestamp() - $this->expired->getTimestamp();
                    if ($seconds >= __GRACE_PERIOD) {
                        $this->state = 'e';
                        $this->expired = $now;
                    }
                }
            }

            // This is a very special case... if we get tokens AFTER the file has expired, it is supposed to 
            //   have been uploaded again and this is why a token has been created. We'll have confidence on
            if ($this->state == 'e') {
                $tokens = $this->get_active_tokens();
                
                if ((sizeof($tokens) > 0) && ($this->file_exists())) {
                    $this->state = 'a';
                    $this->expired = null;
                }
            }

            if ($this->state != $prev_state) {
                if ($autosave) {
                    if (! $this->save_i(['expired', 'state'])) {
                        global $pagecomm;
                        $pagecomm->add_message('error', __('Token has expired but the state could not be saved'));
                        return false;
                    }
                }
                return true;
            }
            return false;
        }

        public function get_active_tokens($filter = array()) {
            if (isset($filter['state'])) {
                if (is_array($filter['state'])) {
                    array_push($filter['state'], 'a');
                    array_push($filter['state'], 'g');
                } else {
                    $filter['state'] = [ $filter['state'], 'a', 'g' ];
                }
            } else 
                $filter['state'] = [ 'a', 'g' ];
            return $this->get_tokens($filter);
        }

        public function get_tokens($filter = array(), $deleted = false) {
            // TODO: check if we can cache the tokens
            // if ($this->_tokens === null)

            $filter['fileid'] = $this->get_id();
            if ($deleted === false) {
                if (isset($filter['!state'])) {
                    if (is_array($filter['!state'])) {
                        array_push($filter['!state'], 'd');
                    } else {
                        $filter['!state'] = [ $filter['!state'], 'd' ];
                    }
                } else
                    $filter['!state'] = 'd';
            }

            return SHAToken::search($filter, 0, 0, false, 'AND', 'time');
        }

        public function OLD_get_tokens($deleted = false) {
            // TODO: check if we can cache the tokens
            // if ($this->_tokens === null)

            if ($deleted === false)
                return SHAToken::search([ 'fileid' => $this->get_id(), '!state' => 'd' ],
                    0, 0, false, 'AND', 'time');
            else
                return SHAToken::search([ 'fileid' => $this->get_id() ],
                    0, 0, false, 'AND', 'time');
        }        
    }