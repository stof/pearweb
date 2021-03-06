<?php
require_once 'HTTP/Upload.php';
class Bugs_Patchtracker
{
    var $_upload;
    var $_patchdir;
    var $_dbh;

    function __construct()
    {
        if (!file_exists(PEAR_PATCHTRACKER_TMPDIR)) {
            if (!@mkdir(PEAR_PATCHTRACKER_TMPDIR)) {
                trigger_error(
                    'Could not create directory for patches:'
                    . PEAR_PATCHTRACKER_TMPDIR,
                    E_USER_NOTICE
                );
                $this->_upload = false;
                $this->_dbh = &$GLOBALS['dbh'];
                return;
            }
        }
        $this->_upload = new HTTP_Upload('en');
        $this->_dbh = $GLOBALS['dbh'];
    }

    /**
     * Return the directory in which patches should be stored
     *
     * @param int $bugid
     * @param string $name name of this patch line
     * @return string
     */
    function patchDir($bugid, $name)
    {
        return PEAR_PATCHTRACKER_TMPDIR . '/p' . $bugid . '/' . $name;
    }
    /**
     * Create the directory in which patches for this bug ID will be stored
     *
     * @param int $bugid
     */
    function setupPatchDir($bugid, $name)
    {
        if (file_exists($this->patchDir($bugid, $name))) {
            if (!is_dir($this->patchDir($bugid, $name))) {
                return PEAR::raiseError('Cannot create patch storage for Bug #' . $bugid .
                    ', storage directory exists and is not a directory');
            }
            return;
        }
        if (!file_exists(dirname($this->patchDir($bugid, $name)))) {
            // setup bug directory
            if (!@mkdir(dirname($this->patchDir($bugid, $name)))) {
                require_once 'PEAR.php';
                return PEAR::raiseError('Cannot create patch storage for Bug #' . $bugid);
            }
        } elseif (!is_dir(dirname($this->patchDir($bugid, $name)))) {
            return PEAR::raiseError('Cannot create patch storage for Bug #' . $bugid .
                ', storage directory exists and is not a directory');
        }
        // setup patch directory
        if (!@mkdir($this->patchDir($bugid, $name))) {
            require_once 'PEAR.php';
            return PEAR::raiseError('Cannot create patch storage for Bug #' . $bugid);
        }
    }

    /**
     * Retrieve a unique, ordered patch filename
     *
     * @param int $bugid
     * @param string $patch
     * @return array array(revision, patch file name)
     */
    function newPatchFileName($bugid, $patch, $handle)
    {
        $id = time();
        PEAR::pushErrorHandling(PEAR_ERROR_RETURN);
        $e = $this->_dbh->query('INSERT INTO bugdb_patchtracker
            (bugdb_id, patch, revision, developer) VALUES(?, ?, ?, ?)',
            array($bugid, $patch, $id, $handle));
        if (PEAR::isError($e)) {
            // try with another timestamp
            $id++;
            $e = $this->_dbh->query('INSERT INTO bugdb_patchtracker
                (bugdb_id, patch, revision, developer) VALUES(?, ?, ?, ?)',
                array($bugid, $patch, $id, $handle));
        }
        PEAR::popErrorHandling();
        if (PEAR::isError($e)) {
            return PEAR::raiseError('Could not get unique patch file name for bug #' .
                $bugid . ', patch "'. $patch . '"');
        }
        return array($id, $this->getPatchFileName($id));
    }

    /**
     * Retrieve the name of the patch file on the system
     *
     * @param int $id revision ID
     * @return string
     */
    function getPatchFileName($id)
    {
        return 'p' . $id . '.patch.txt';
    }

    /**
     * Retrieve the full path to a patch file
     *
     * @param int $bugid
     * @param string $name
     * @param int $revision
     * @return string
     */
    function getPatchFullpath($bugid, $name, $revision)
    {
        return $this->patchDir($bugid, $name) .
            DIRECTORY_SEPARATOR . $this->getPatchFileName($revision);
    }

    function userNotRegistered($bugid, $name, $revision)
    {
        $user = $this->_dbh->getOne('SELECT registered from bugdb_patchtracker, users
            WHERE bugdb_id = ? AND patch = ? AND revision = ?
                AND users.handle = bugdb_patchtracker.developer',
            array($bugid, $name, $revision));
        return !$user;
    }

    /**
     * Attach a patch to this bug
     *
     * @param int $bugid
     * @param string $patch uploaded patch filename form variable
     * @param string $name patch name (allows several patches to be versioned)
     * @param string $handle developer handle
     * @param array  $obsoletes obsoleted patches
     * @return int patch revision
     */
    function attach($bugid, $patch, $name, $handle, $obsoletes)
    {
        if (!$this->_upload) {
            return PEAR::raiseError('Upload directory for patches could not be' .
                ' initialized');
        }
        if (!preg_match('/^[\w\-\.]+\z/', $name) || strlen($name) > 40) {
            return PEAR::raiseError('Invalid patch name "' . $name . '"');
        }
        if (!is_array($obsoletes)) {
            return PEAR::raiseError('Invalid obsoleted patches');
        }
        $newobsoletes = array();
        foreach ($obsoletes as $who) {
            if (!$who) {
                continue; // remove (none)
            }
            $who = explode('#', $who);
            if (count($who) != 2) {
                continue;
            }
            if (file_exists($this->getPatchFullpath($bugid, $who[0], $who[1]))) {
                $newobsoletes[] = $who;
            }
        }
        if (PEAR::isError($e = $this->setupPatchDir($bugid, $name))) {
            return $e;
        }
        $file = $this->_upload->getFiles($patch);
        if (PEAR::isError($file)) {
            return $file;
        }
        if ($file->isValid()) {
            $res = $this->newPatchFileName($bugid, $name, $handle);
            if (PEAR::isError($res)) {
                return $res;
            }
            list($id, $fname) = $res;
            $file->setName($fname);
            $allowed_mime_types = array(
                'application/x-txt',
                'text/plain',
                'text/x-diff',
                'text/x-patch',
                'text/x-c++',
                'text/x-c',
            );

            // return mime type ala mimetype extension
            if (!class_exists('finfo')) {
                $this->detach($bugid, $name, $id);
                return PEAR::raiseError('Error: finfo missing (fileinfo extension)');
            }
            $finfo = new finfo(FILEINFO_MIME);
            if (!$finfo) {
                return PEAR::raiseError('Error: Opening fileinfo database failed');
            }

            // get mime-type for a specific file
            $mime = $finfo->file($file->getProp('tmp_name'));
            // get rid of the comment
            $t    = explode(';', $mime);
            $mime = $t[0];
            //get rid of charset
            $t    = explode(' ', $mime);
            $mime = $t[0];

            if (!in_array($mime, $allowed_mime_types)) {
                $this->detach($bugid, $name, $id);
                return PEAR::raiseError('Error: uploaded patch file must be text'
                    . ' file (save as e.g. "patch.txt" or "package.diff")'
                    . ' (detected "' . htmlspecialchars($mime) . '")'
                );
            }
            $tmpfile = $file->moveTo($this->patchDir($bugid, $name));
            if (PEAR::isError($tmpfile)) {
                $this->detach($bugid, $name, $id);
                return $tmpfile;
            }
            if (!$file->getProp('size')) {
                $this->detach($bugid, $name, $id);
                return PEAR::raiseError('zero-length patches not allowed');
            }
            if ($file->getProp('size') > 102400) {
                $this->detach($bugid, $name, $id);
                return PEAR::raiseError('Patch files cannot be larger than 100k');
            }
            foreach ($newobsoletes as $obsolete) {
                $this->obsoletePatch($bugid, $name, $id, $obsolete[0], $obsolete[1]);
            }
            return $id;
        } elseif ($file->isMissing()) {
            return PEAR::raiseError('No patch has been uploaded.');
        } elseif ($file->isError()) {
            return PEAR::raiseError($file->errorMsg());
        }
        return PEAR::raiseError('Unable to attach patch (try renaming the file with .txt extension)');
    }

    /**
     * Remove a patch revision from this bug
     *
     * @param int $bugid
     * @param string $name
     * @param int $revision
     */
    function detach($bugid, $name, $revision)
    {
        $this->_dbh->query('DELETE FROM bugdb_patchtracker
            WHERE bugdb_id = ? and patch = ? and revision = ?',
            array($bugid, $name, $revision));
        @unlink($this->patchDir($bugid, $name) . DIRECTORY_SEPARATOR .
            $this->getPatchFileName($revision));
    }

    /**
     * Checks if the patch is valid in the database
     *
     * @param integer $bugid    ID number of bug
     * @param string  $name     Name of patch
     * @param integer $revision Patch revision (timestamp)
     *
     * @return boolean True if all is ok, false if not
     */
    function isPatchValid($bugid, $name, $revision)
    {
        if (!$this->_dbh->getOne('SELECT bugdb_id FROM bugdb_patchtracker
              WHERE bugdb_id = ? AND patch = ? AND revision = ?',
              array($bugid, $name, $revision))
        ) {
            return false;
        }
        if (!$this->_dbh->getOne(
            'SELECT registered FROM users, bugdb_patchtracker
            WHERE bugdb_id = ? AND patch = ? AND revision = ? AND
            users.handle = bugdb_patchtracker.developer',
            array($bugid, $name, $revision)
        )) {
            // user is not registered
            return false;
        }
        return true;
    }

    /**
     * Retrieve the actual contents of the patch
     *
     * @param integer $bugid    ID number of bug
     * @param string  $name     Name of patch
     * @param integer $revision Patch revision (timestamp)
     *
     * @return string Patch contents
     */
    function getPatch($bugid, $name, $revision)
    {
        if (!$this->isPatchValid($bugid, $name, $revision)) {
            return PEAR::raiseError(
                'No such patch revision "' .
                $revision . '", or no such patch "' . $name . '"'
            );
        }

        $contents = @file_get_contents(
            $this->getPatchFullpath($bugid, $name, $revision)
        );
        if (!$contents) {
            return PEAR::raiseError('Cannot retrieve patch revision "' .
                $revision . '" for patch "' . $name . '"'
            );
        }
        return $contents;
    }

    /**
     * Retrieve a listing of all patches and their revisions
     *
     * @param int $bugid
     * @return array
     */
    function listPatches($bugid)
    {
        return $this->_dbh->getAssoc(
            'SELECT patch, revision, developer
                FROM bugdb_patchtracker, users
                WHERE bugdb_id = ? AND users.handle = bugdb_patchtracker.developer
             ORDER BY revision DESC',
            false, array($bugid),
            DB_FETCHMODE_ORDERED, true
        );
    }

    /**
     * Retrieve the number of patches for a bug
     *
     * @param int $bugid
     *
     * @return integer
     */
    function getPatchCount($bugid)
    {
        $res = $this->_dbh->getRow(
            'SELECT COUNT(*) as number
                FROM bugdb_patchtracker, users
                WHERE bugdb_id = ? AND users.handle = bugdb_patchtracker.developer',
            false, array($bugid),
            DB_FETCHMODE_ORDERED, true
        );
        return (int)$res[0];
    }

    /**
     * Retrieve a listing of all patches and their revisions
     *
     * @param int $bugid
     * @param string $patch
     * @return array
     */
    function listRevisions($bugid, $patch)
    {
        return $this->_dbh->getAll(
            'SELECT revision FROM bugdb_patchtracker, users
                WHERE bugdb_id = ? AND
             patch = ? AND users.handle = bugdb_patchtracker.developer AND
             users.registered = 1
             ORDER BY revision DESC', array($bugid, $patch),
            DB_FETCHMODE_ORDERED
        );
    }

    /**
     * Retrieve the developer who uploaded this patch
     *
     * @param int $bugid
     * @param string $patch
     * @param int $revision
     * @return string|array array if no revision is selected
     */
    function getDeveloper($bugid, $patch, $revision = false)
    {
        if ($revision) {
            return $this->_dbh->getOne(
                'SELECT developer FROM bugdb_patchtracker
                 WHERE bugdb_id = ? AND patch = ? AND revision = ?
                ', array($bugid, $patch, $revision));
        }
        return $this->_dbh->getAll(
            'SELECT developer,revision FROM bugdb_patchtracker
             WHERE bugdb_id = ? AND patch = ? ORDER BY revision DESC',
            array($bugid, $patch), DB_FETCHMODE_ASSOC
        );
    }

    function getObsoletingPatches($bugid, $patch, $revision)
    {
        return $this->_dbh->getAll('SELECT bugdb_id, patch, revision
            FROM bugdb_obsoletes_patches
                WHERE bugdb_id = ? AND
                      obsolete_patch = ? AND
                      obsolete_revision = ?', array($bugid, $patch, $revision),
         DB_FETCHMODE_ASSOC);
    }

    function getObsoletePatches($bugid, $patch, $revision)
    {
        return $this->_dbh->getAll('SELECT bugdb_id, obsolete_patch, obsolete_revision
            FROM bugdb_obsoletes_patches
                WHERE bugdb_id = ? AND
                      patch = ? AND
                      revision = ?', array($bugid, $patch, $revision),
         DB_FETCHMODE_ASSOC);
    }

    /**
     * link to an obsolete patch from the new one
     *
     * @param int $bugid
     * @param string $name better patch name
     * @param int $revision better patch revision
     * @param string $obsoletename
     * @param int $obsoleterevision
     */
    function obsoletePatch($bugid, $name, $revision, $obsoletename, $obsoleterevision)
    {
        $this->_dbh->query('INSERT INTO bugdb_obsoletes_patches
            VALUES(?, ?, ?, ?, ?)', array($bugid, $name, $revision, $obsoletename,
                                      $obsoleterevision));
    }

    /**
     * Retrieve information on a bug
     *
     * @param int $bugid
     * @return array
     */
    function getBugInfo($bugid)
    {
        $bugid = (int) $bugid;
        $info = $this->_dbh->getAll('SELECT * FROM bugdb WHERE id = ?', array($bugid),
            DB_FETCHMODE_ASSOC);
        if (!is_array($info) || !count($info)) {
            return PEAR::raiseError('No such bug "' . $bugid . '"');
        }
        return $info[0];
    }
}